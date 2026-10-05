<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentIntelligenceSummary;
use App\Services\AI\AiModels;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\SynthesisCheckpoint;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Grounded document-level summary — feeds Claude the ALREADY-EXTRACTED
 * structured data (entities/risks/deadlines/document_type), not the raw
 * document text. This is deliberate: the summary must be traceable to
 * verified extraction results, not a fresh independent read of the
 * document that could disagree with what entities/risks/deadlines jobs
 * already found. No new facts can enter here that didn't already pass
 * through the Day 3/4 validators.
 *
 * Dispatched from the intelligence batch's finally() callback rather than
 * included in the batch array itself — it reads relations written by its
 * four siblings, and finally() only fires once all of them have settled,
 * which a batch's own internal ordering cannot guarantee. Because it's
 * dispatched standalone (not inside Bus::batch([...])), $this->batch()
 * will always be null here — Batchable is kept for consistency with the
 * rest of the pipeline, not because this job is tracked by a batch.
 */
class GenerateDocumentSummaryJob implements ShouldQueue
{
    use Batchable, Dispatchable, GuardsDocumentIntelligence, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;

    public int $timeout = 140;

    public bool $failOnTimeout = true;

    public function __construct(public string $documentId, public bool $forceReprocess = false, public ?string $queuedStageId = null) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);

        if ($this->batch()?->cancelled() || ! $document?->canGenerateIntelligence()) {
            return;
        }

        if (($document->ai_pipeline['route'] ?? null) !== 'incremental'
            && $this->skipIfUnchanged($document, 'document_summary', 'document_summary', $recorder)) {
            return;
        }

        $checkpoint = null;
        if (($document->ai_pipeline['route'] ?? null) === 'incremental') {
            $checkpoint = app(SynthesisCheckpoint::class)->claim($document);
            if (! $checkpoint) {
                return;
            }
        }

        $stage = $this->startIntelligence($document, 'document_summary', $recorder);
        if (! $stage || $this->abandonIntelligence($document, $stage)) {
            if ($checkpoint && $checkpoint->status === 'running') {
                app(IncrementalPipeline::class)->settleCost($checkpoint, false);
                $checkpoint->update(['status' => 'pending']);
            }

            return;
        }

        $document->loadMissing(['documentTypeClassification', 'entities', 'risks', 'deadlines', 'kpis']);

        $extractedData = [
            'document_type' => $document->documentTypeClassification?->document_type,
            'entities' => $document->entities->map(fn ($e) => [
                'id' => 'entity:'.$e->id, 'type' => $e->entity_type, 'value' => $e->value,
                'context' => $e->context,
            ])->toArray(),
            'risks' => $document->risks->map(fn ($r) => [
                'id' => 'risk:'.$r->id, 'title' => $r->title, 'severity' => $r->severity,
                'description' => $r->description, 'evidence' => $r->evidence,
            ])->toArray(),
            'deadlines' => $document->deadlines->map(fn ($d) => [
                'id' => 'deadline:'.$d->id, 'title' => $d->title, 'date_type' => $d->date_type,
                'due_date' => $d->due_date?->toDateString(), 'relative_text' => $d->relative_text,
                'evidence' => $d->evidence,
            ])->toArray(),
            'kpis' => $document->kpis->map(fn ($k) => [
                'id' => 'kpi:'.$k->id, 'label' => $k->label, 'period' => $k->period,
                'value' => $k->value, 'unit' => $k->unit, 'trend' => $k->trend,
            ])->toArray(),
            'insights' => array_slice($document->insights ?? [], 0, 5),
        ];

        if (($document->ai_pipeline['route'] ?? null) === 'incremental') {
            $extractedData = app(EvidenceBudget::class)->forSynthesis($document);
        } else {
            $extractedData = app(EvidenceBudget::class)->trimNormal($extractedData);
        }
        $document->forceFill(['ai_pipeline' => [...($document->ai_pipeline ?? []),
            'evidence_trimmed' => $extractedData['coverage']['evidence_omitted'] > 0]])->save();

        if ($checkpoint) {
            $client->setRunContext(['chunk_id' => $checkpoint->id, 'pipeline_version' => $checkpoint->pipeline_version,
                'request_attempt' => $checkpoint->attempts, 'evidence_trimmed' => ($extractedData['coverage']['evidence_omitted'] ?? 0) > 0]);
        }
        $providerCalled = false;
        try {
            $providerCalled = $checkpoint?->result === null;
            $result = $checkpoint?->result ?? $client->generateDocumentSummary(json_encode($extractedData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $document->name, $document);
            if ($checkpoint && $checkpoint->status !== 'completed') {
                $checkpoint->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'failure_class' => null]);
            }
        } catch (\Throwable $e) {
            $retryScheduled = false;
            $failure = $e instanceof AiProcessingException ? $e->classification
                : ($e instanceof AnthropicStructuredOutputException ? $e->outputStatus : 'validation');
            $checkpoint?->update(['status' => 'failed', 'failure_class' => $failure]);
            if ($checkpoint) {
                $document->refresh()->forceFill(['status' => 'Needs Review', 'progress' => 100,
                    'error_message' => $failure === 'billing'
                        ? 'AI analysis is temporarily unavailable. Your extracted evidence has been preserved. Please contact support.'
                        : 'Available evidence is preserved, but document synthesis could not be completed.',
                    'ai_pipeline' => [...$document->ai_pipeline, 'partial' => true, 'synthesis' => $failure]])->save();
                if (in_array($failure, ['max_tokens', 'truncated', 'context_overflow', 'timeout'], true)
                    && ($document->ai_pipeline['synthesis_reductions'] ?? 0) < 2) {
                    // Only synthesis changes: completed extraction is never scheduled again.
                    $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
                        'synthesis_reductions' => ($document->ai_pipeline['synthesis_reductions'] ?? 0) + 1]])->save();
                    $document->forceFill(['status' => 'Processing'])->save();
                    self::dispatch($document->id, true)->onQueue('extraction');
                    $retryScheduled = true;
                }
            }
            if ($checkpoint && $e instanceof AiProcessingException && $e->classification === 'transient'
                && $checkpoint->attempts < config('document_intelligence.attempts')) {
                $checkpoint->update(['status' => 'pending']);
                $document->forceFill(['status' => 'Processing'])->save();
                self::dispatch($document->id, true)->onQueue('extraction')->delay(max(2 ** $checkpoint->attempts * 5, $e->retryAfter) + random_int(0, 5));
                $retryScheduled = true;
            }

            if (! $checkpoint && $this->abandonIntelligence($document, $stage)) {
                return;
            }
            $recorder->fail($stage, $e->getMessage());
            if (! $retryScheduled) {
                $this->fail($e);
            }

            return;
        } finally {
            if ($checkpoint) {
                app(IncrementalPipeline::class)->settleCost($checkpoint, $providerCalled);
                $client->setRunContext([]);
                $this->logSynthesis($document, $checkpoint, $providerCalled, $extractedData['coverage'] ?? []);
            }
        }

        if ($this->abandonIntelligence($document, $stage)) {
            return;
        }

        if ($checkpoint && ! empty($extractedData['coverage']['warning'])
            && ! str_contains($result['executive_summary'], $extractedData['coverage']['warning'])) {
            $result['executive_summary'] .= "\n\nCoverage note: ".$extractedData['coverage']['warning'];
        }

        $this->persistIntelligence($document, $stage, $recorder, function () use ($document, $result, $recorder) {
            if (($document->ai_pipeline['route'] ?? null) === 'incremental' && ! empty($result['document_type_assessment'])) {
                $document->documentTypeClassification()->updateOrCreate(['document_id' => $document->id],
                    $result['document_type_assessment'] + ['workspace_id' => $document->workspace_id,
                        'prompt_version' => (string) $result['prompt_version'], 'provider' => 'anthropic',
                        'model' => app(AiModels::class)->forTask('document_summary')]);
                $recorder->complete($recorder->start($document, 'document_type'), ['from_global_synthesis' => true]);
            }
            DocumentIntelligenceSummary::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'workspace_id' => $document->workspace_id,
                    'executive_summary' => $result['executive_summary'],
                    'key_findings' => $result['key_findings'],
                    'critical_risks' => $result['critical_risks'],
                    'upcoming_deadlines' => $result['upcoming_deadlines'],
                    'important_entities' => $result['important_entities'],
                    'recommended_attention' => $result['recommended_attention'],
                    'executive_assessment' => $result['executive_assessment'] ?? null,
                    'material_findings' => $result['material_findings'] ?? [],
                    'trends' => $result['trends'] ?? [],
                    'tensions' => $result['tensions'] ?? [],
                    'questions' => $result['questions'] ?? [],
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => app(AiModels::class)->forTask('document_summary'),
                ]
            );
        }, ['generated' => true, 'optional_items_dropped' => array_sum($result['_optional_items_dropped'] ?? [])]);
        if ($checkpoint) {
            DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $checkpoint->pipeline_key)
                ->where('stage', 'synthesis')->where('id', '!=', $checkpoint->id)
                ->whereIn('status', ['failed', 'budget', 'uncertain'])->update(['status' => 'superseded']);
            $otherFailures = DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $checkpoint->pipeline_key)
                ->whereIn('status', ['failed', 'budget', 'uncertain'])->exists();
            $document->refresh()->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
                'partial' => $otherFailures || ! empty($result['_optional_items_dropped'])
                    || ! ($extractedData['coverage']['comprehensive'] ?? false),
                'synthesis' => 'completed', 'summary_stale' => false,
                'coverage' => $extractedData['coverage'],
                'synthesis_coverage_warning' => $extractedData['coverage']['warning'] ?? null],
                'status' => 'Ready', 'progress' => 100, 'error_message' => null])->save();
            AnalyzeEmbeddedVisualsJob::dispatch($document->id)->onQueue('extraction');
            GenerateEmbeddingsJob::dispatch($document->id)->onQueue('extraction');
        }
    }

    /** Metadata only: never evidence, source text, prompts or the response. */
    private function logSynthesis(Document $document, DocumentChunk $checkpoint, bool $providerCalled, array $coverage): void
    {
        $checkpoint->refresh();
        $runs = DocumentAiRun::where('chunk_id', $checkpoint->id)->where('request_attempt', $checkpoint->attempts)->get();
        $pipeline = $document->fresh()?->ai_pipeline ?? [];
        Log::info('Document synthesis attempt finished', [
            'document_id' => $document->id, 'chunk_id' => $checkpoint->id, 'status' => $checkpoint->status,
            'failure_class' => $checkpoint->failure_class, 'attempt' => $checkpoint->attempts,
            'reused_checkpoint' => ! $providerCalled, 'configured_model' => app(AiModels::class)->forTask('document_summary'),
            'source_context' => $coverage['source_text'] ?? null, 'evidence_total' => $coverage['evidence_total'] ?? null,
            'evidence_omitted' => $coverage['evidence_omitted'] ?? null,
            'provider_requests' => $runs->count(), 'repair_requests' => max(0, $runs->count() - 1),
            'synthesis_reductions' => $pipeline['synthesis_reductions'] ?? 0,
            'input_tokens' => (int) $runs->sum('input_tokens'), 'output_tokens' => (int) $runs->sum('output_tokens'),
            'latency_ms' => (int) $runs->sum('duration_ms'),
            'estimated_cost_usd' => $checkpoint->cost_accounting['estimate'] ?? null,
            'settled_cost_usd' => $checkpoint->cost_accounting['cost'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeIntelligenceFailure('document_summary', $e);
        $document = Document::find($this->documentId);
        if (($document?->ai_pipeline['route'] ?? null) === 'incremental') {
            DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $document->ai_pipeline['key'])
                ->where('stage', 'synthesis')->where('status', 'running')
                ->update(['status' => 'uncertain', 'failure_class' => 'worker_timeout']);
            if ($document->status === 'Processing') {
                $document->forceFill(['status' => 'Needs Review', 'progress' => 100,
                    'error_message' => 'Available evidence is preserved, but document synthesis could not be completed.',
                    'ai_pipeline' => [...$document->ai_pipeline, 'partial' => true]])->save();
            }
        }
        Log::error('GenerateDocumentSummaryJob failed after retries', [
            'document_id' => $this->documentId,
            'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'synthesis_failure',
        ]);
    }
}
