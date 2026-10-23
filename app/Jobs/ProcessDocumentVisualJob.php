<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentIntelligenceRevision;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\VisualPlanner;
use App\Services\AI\ProviderGate;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessDocumentVisualJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public string $chunkId, public ?string $dispatchToken = null) {}

    public function handle(AnthropicClient $client, VisualPlanner $planner): void
    {
        $unit = DocumentChunk::find($this->chunkId);
        if (! $unit || $unit->status !== 'queued'
            || ($this->dispatchToken !== null && $unit->dispatch_token !== null && ! hash_equals($unit->dispatch_token, $this->dispatchToken))) {
            return; // Finished, claimed elsewhere, or superseded by a newer dispatch.
        }
        try {
            app(ProviderGate::class)->hold($unit->document_id, fn () => $this->process($client, $planner));
        } catch (ProviderBusyException $e) {
            $this->deferForProvider($e, ['document_id' => $unit->document_id, 'chunk_id' => $unit->id]);
        }
    }

    private function process(AnthropicClient $client, VisualPlanner $planner): void
    {
        $unit = DocumentChunk::find($this->chunkId);
        $document = $unit?->document;
        if (! $document || $document->status !== 'Ready' || $document->workspace_id !== $unit->workspace_id) {
            return;
        }
        $key = $document->ai_pipeline['key'] ?? hash('sha256', $document->workspace_id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
        if ($unit->pipeline_key !== $key) {
            return;
        }
        if (! DocumentChunk::whereKey($unit->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'attempts' => 1])) {
            return;
        }
        $asset = $unit->result;
        $retainedPath = null;
        $retainedExisted = false;
        try {
            $cost = app(AiPricing::class)->estimate(app(AiModels::class)->forTask('chart_vision'), ['input_tokens' => 6000, 'output_tokens' => config('services.anthropic.max_tokens')]);
            $allowed = DB::transaction(function () use ($document, $unit, $cost) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $budget = $locked->ai_pipeline['budget_usd'] ?? config('document_intelligence.budget_base_usd');
                $spent = DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $unit->pipeline_key)->sum('reserved_cost');
                if (($locked->ai_pipeline['route'] ?? null) === 'incremental'
                    ? ! app(IncrementalPipeline::class)->canReserve($locked, $cost)
                    : ($cost === null || $spent + $cost > $budget)) {
                    return false;
                }
                // The visual status claim above is independent of its paid request claim.
                $unit->attempts = 0;
                app(IncrementalPipeline::class)->reserveCost($unit, $cost);

                return true;
            });
            if (! $allowed) {
                throw new AiProcessingException('budget_exceeded');
            }
            $client->setRunContext(['chunk_id' => $unit->id, 'pipeline_version' => $unit->pipeline_version, 'request_attempt' => 1]);
            $bytes = Storage::disk('documents')->get($asset['path']);
            if (! is_string($bytes) || ! hash_equals($unit->input_hash, hash('sha256', $bytes))) {
                throw new AiProcessingException('input_changed');
            }
            $result = $client->extractChartDataFromImage(base64_encode($bytes), $asset['media_type'], $document);
            $revisionId = $document->published_intelligence_revision_id;
            $revision = $revisionId === null ? null : DocumentIntelligenceRevision::whereKey($revisionId)
                ->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
                ->where('file_hash', $document->file_hash)->where('state', 'published')->first();
            if ($revisionId !== null && (! $revision || ($revision->pipeline_key !== null
                && $revision->pipeline_key !== $unit->pipeline_key))) {
                throw new AiProcessingException('input_changed');
            }
            if ($revisionId === null && (int) $document->intelligence_generation > 0) {
                throw new AiProcessingException('input_changed');
            }
            if (! empty($result['charts'])) {
                $retainedPath = 'chart-visuals/'.$document->workspace_id.'/'.$document->id.'/'
                    .($revisionId ?? 'legacy').'/'.$unit->input_hash;
                $retainedExisted = Storage::disk('documents')->exists($retainedPath);
                if (! $retainedExisted && ! Storage::disk('documents')->copy($asset['path'], $retainedPath)) {
                    throw new AiProcessingException('visual_partial');
                }
            }
            $stored = DB::transaction(function () use ($document, $result, $unit, $asset, $retainedPath, $revisionId) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($locked->workspace_id !== $unit->workspace_id || $locked->status !== 'Ready'
                    || $locked->file_hash !== $document->file_hash
                    || ($locked->ai_pipeline['key'] ?? hash('sha256', $locked->workspace_id.'|'.$locked->id.'|'.$locked->file_hash.'|visual-v1')) !== $unit->pipeline_key
                    || $locked->published_intelligence_revision_id !== $revisionId) {
                    throw new AiProcessingException('input_changed');
                }
                $count = app(AnalyzeEmbeddedVisualsJob::class, ['documentId' => $document->id, 'revisionId' => $revisionId])->mergeCharts(
                    $locked, $result['charts'] ?? [], $retainedPath ? [
                        'path' => $retainedPath, 'sha256' => $unit->input_hash,
                        'page' => $asset['page'] ?? null, 'media_type' => $asset['media_type'],
                        'document_hash' => $document->file_hash, 'pipeline_key' => $unit->pipeline_key,
                    ] : []);
                $unit->update(['status' => 'completed', 'result' => ['page' => $asset['page'] ?? null, 'charts' => $result['charts'] ?? []], 'completed_at' => now()]);

                return $count;
            });
            if ($stored === 0 && $retainedPath !== null && ! $retainedExisted) {
                Storage::disk('documents')->delete($retainedPath);
                $retainedPath = null;
            }
        } catch (\Throwable $e) {
            if ($retainedPath !== null && ! $retainedExisted) {
                Storage::disk('documents')->delete($retainedPath);
            }
            $unit->update(['status' => 'failed', 'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'visual_partial', 'completed_at' => now()]);
        } finally {
            app(IncrementalPipeline::class)->settleCost($unit);
            Storage::disk('documents')->delete($asset['path']);
            $planner->pump($document, $unit->pipeline_key);
        }
    }

    public function failed(\Throwable $e): void
    {
        $unit = DocumentChunk::find($this->chunkId);
        if (! $unit || $unit->status !== 'running') {
            return;
        }
        if (isset($unit->result['path'])) {
            Storage::disk('documents')->delete($unit->result['path']);
        }
        $unit->update(['status' => 'failed', 'failure_class' => 'visual_timeout']);
        if ($unit->document) {
            app(VisualPlanner::class)->pump($unit->document, $unit->pipeline_key);
        }
    }
}
