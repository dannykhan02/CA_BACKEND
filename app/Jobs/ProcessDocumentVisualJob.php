<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\VisualPlanner;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public string $chunkId) {}

    public function handle(AnthropicClient $client, VisualPlanner $planner): void
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
        try {
            $cost = app(AiPricing::class)->estimate(app(AiModels::class)->forTask('chart_vision'), ['input_tokens' => 6000, 'output_tokens' => config('services.anthropic.max_tokens')]);
            $allowed = DB::transaction(function () use ($document, $unit, $cost) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $budget = $locked->ai_pipeline['budget_usd'] ?? config('document_intelligence.budget_base_usd');
                $spent = DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $unit->pipeline_key)->sum('reserved_cost');
                if ($cost === null || $spent + $cost > $budget) {
                    return false;
                }
                $unit->update(['reserved_cost' => $cost]);

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
            DB::transaction(function () use ($document, $result, $unit, $asset) {
                Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                app(AnalyzeEmbeddedVisualsJob::class, ['documentId' => $document->id])->mergeCharts($document, $result['charts'] ?? []);
                $unit->update(['status' => 'completed', 'result' => ['page' => $asset['page'] ?? null, 'charts' => $result['charts'] ?? []], 'completed_at' => now()]);
            });
        } catch (\Throwable $e) {
            $unit->update(['status' => 'failed', 'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'visual_partial', 'completed_at' => now()]);
        } finally {
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
