<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\ProviderGate;
use App\Services\AnthropicClient;
use App\Support\QueueTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDocumentChunkJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Token count (15s) + one extraction request (extraction_timeout_seconds) + bookkeeping.
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /** $dispatchToken identifies the dispatch this message belongs to (null: pre-token message). */
    public function __construct(public string $chunkId, public ?string $dispatchToken = null) {}

    public function handle(AnthropicClient $client, IncrementalPipeline $pipeline): void
    {
        $chunk = DocumentChunk::find($this->chunkId);
        $document = $chunk ? Document::find($chunk->document_id) : null;
        if (! $document?->canGenerateIntelligence() || $chunk->workspace_id !== $document->workspace_id
            || $chunk->pipeline_key !== ($document->ai_pipeline['key'] ?? null)) {
            return;
        }
        // A superseded dispatch (recovery re-issued this chunk) is dropped before any provider call.
        if ($this->dispatchToken !== null && $chunk->dispatch_token !== null && ! hash_equals($chunk->dispatch_token, $this->dispatchToken)) {
            Log::info('Superseded chunk delivery ignored', ['document_id' => $document->id, 'chunk_id' => $chunk->id]);

            return;
        }
        if (! in_array($chunk->status, ['queued', 'pending'], true)) {
            // Running/finished duplicate: the claim below rejects it without provider work.
            $this->process($client, $pipeline, $chunk, $document);

            return;
        }
        try {
            // One global permit covers the token count and the extraction request; when none is
            // free the chunk stays queued and a delayed copy of this message is enqueued.
            try {
                $this->recordWorkerArrival($chunk);
            } catch (\Throwable $e) {
                Log::warning('Chunk queue timing unavailable', ['chunk_id' => $chunk->id, 'error_type' => $e::class]);
            }
            // The gate reserves capacity for documents that actually attempted admission.
            // A queued DB row alone may have no runnable worker (or belong to stale work).
            app(ProviderGate::class)->hold($document->id, fn () => $this->process($client, $pipeline, $chunk, $document));
        } catch (ProviderBusyException $e) {
            $this->deferForProvider($e, ['document_id' => $document->id, 'chunk_id' => $chunk->id], function (int $delay) use ($chunk, $e) {
                try {
                    $this->recordAdmissionDeferral($chunk, $e->reason, $delay);
                } catch (\Throwable $error) {
                    Log::warning('Chunk admission timing unavailable', ['chunk_id' => $chunk->id, 'error_type' => $error::class]);
                }
            });
        }
    }

    /** Persist metadata only; the original dispatch timestamp is retained across deferrals. */
    private function recordWorkerArrival(DocumentChunk $chunk): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $accounting = $chunk->cost_accounting ?? [];
        $timing = $accounting['queue_timing'] ?? [];
        if (! array_key_exists('dispatch_at_ms', $timing) || ($timing['dispatch_token'] ?? null) !== $chunk->dispatch_token) {
            $timing = ['dispatch_token' => $chunk->dispatch_token,
                'dispatch_at_ms' => ($chunk->dispatched_at ?? $chunk->updated_at)?->getTimestampMs()];
        }
        if (! isset($timing['first_worker_at_ms'])) {
            $timing['first_worker_at_ms'] = $now;
            $timing['worker_wait_ms'] = isset($timing['dispatch_at_ms'])
                ? max(0, $now - $timing['dispatch_at_ms']) : 0;
        }
        if (isset($timing['deferred_at_ms'], $timing['not_before_ms'], $timing['reason'])) {
            $scheduled = max(0, min($now, $timing['not_before_ms']) - $timing['deferred_at_ms']);
            $field = $timing['reason'] === 'fairness' ? 'fairness_wait_ms' : 'provider_admission_wait_ms';
            $timing[$field] = ($timing[$field] ?? 0) + $scheduled;
            $timing['worker_wait_ms'] += max(0, $now - $timing['not_before_ms']);
            unset($timing['deferred_at_ms'], $timing['not_before_ms'], $timing['reason']);
        }
        $chunk->update(['cost_accounting' => [...$accounting, 'queue_timing' => $timing]]);
    }

    private function recordAdmissionDeferral(DocumentChunk $chunk, string $reason, int $delay): void
    {
        $accounting = $chunk->fresh()->cost_accounting ?? [];
        $now = (int) floor(microtime(true) * 1000);
        $timing = $accounting['queue_timing'] ?? [];
        $timing['deferred_at_ms'] = $now;
        $timing['not_before_ms'] = $now + $delay * 1000;
        $timing['reason'] = $reason;
        $timing['deferrals'] = ($timing['deferrals'] ?? 0) + 1;
        $chunk->update(['cost_accounting' => [...$accounting, 'queue_timing' => $timing]]);
    }

    private function process(AnthropicClient $client, IncrementalPipeline $pipeline, DocumentChunk $chunk, Document $document): void
    {
        $text = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
        $grounding = app(EvidenceGrounding::class);
        // What the provider actually receives: labeled evidence spans in span mode, the raw slice
        // otherwise. Counting, admission and the context bound all apply to this, not to the raw
        // slice, so span labels are paid for from the real budget rather than hidden from it.
        $payload = $grounding->payload($document, $chunk, $text);
        $mode = $grounding->mode($document);
        // Free provider token count before admission, so the reservation bounds the real input instead
        // of raw bytes (~3x tighter). A conservative byte bound remains when counting is unavailable.
        $counted = null;
        if (in_array($chunk->status, ['queued', 'pending'], true) && hash('sha256', $text) === $chunk->input_hash) {
            try {
                $counted = $client->countTokens($payload);
            } catch (\Throwable) {
                $counted = null;
            }
        }
        $claimed = DB::transaction(function () use ($chunk, $document, $pipeline, $counted, $grounding, $mode) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $unit = DocumentChunk::whereKey($chunk->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canGenerateIntelligence() || $unit->pipeline_key !== ($locked->ai_pipeline['key'] ?? null)) {
                return false;
            }
            if (! in_array($unit->status, ['queued', 'pending'], true)) {
                return false;
            }
            $envelope = ['document_name' => $locked->name, 'start_page' => $unit->start_page, 'end_page' => $unit->end_page, 'max_records' => 99999,
                ...$pipeline->continuationContext($unit)];
            $slice = $grounding->payload($locked, $unit,
                mb_substr($locked->extracted_text, $unit->start_offset, $unit->end_offset - $unit->start_offset));
            $escaped = strlen(json_encode($slice, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            // The request carries the slice JSON-escaped: every escape byte is bounded as one more token.
            $source = $counted === null ? $escaped : $counted + max(0, $escaped - strlen($slice));
            $cost = app(AiPricing::class)->reserve(
                app(AiModels::class)->forTask('extraction'),
                $source + strlen(json_encode([...$envelope, 'source_text' => ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    + strlen(json_encode(EvidenceSchema::extraction($mode)))
                    + strlen(EvidenceSchema::instructions($mode)) + 512,
                app(ExtractionCapacity::class)->outputTokens(),
                cacheWrite: true
            );
            if (! $pipeline->canReserve($locked, $cost)) {
                // Running siblings will settle below their reservations. Wait for one of them
                // (its completion pumps this unit again) instead of failing a fundable unit.
                if (DocumentChunk::where('document_id', $unit->document_id)->where('pipeline_key', $unit->pipeline_key)
                    ->where('stage', 'extraction')->where('status', 'running')->whereKeyNot($unit->id)->exists()) {
                    $unit->update(['status' => 'pending']);

                    return 'deferred';
                }
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);

                return false;
            }
            $pipeline->reserveCost($unit, $cost, $pipeline->admissionMetadata($unit) + ['counted_input_tokens' => $counted]);

            return true;
        });
        if ($claimed === 'deferred') {
            return;
        }
        if (! $claimed) {
            $pipeline->pump($document->id);

            return;
        }
        $chunk->refresh();
        $providerCalled = false;
        try {
            if (hash('sha256', $text) !== $chunk->input_hash) {
                throw new AiProcessingException('input_changed');
            }
            // Actual tokens were counted before admission. A conservative local estimate remains on endpoint failure.
            $tokens = $counted ?? app(ChunkPlanner::class)->estimate($payload);
            if ($tokens > app(ExtractionCapacity::class)->maxRequestInputTokens()) {
                throw new AiProcessingException('context_overflow');
            }
            $chunk->update(['token_count' => $tokens]);
            $providerCalled = true;
            $result = $client->extractChunk($document, $chunk, $text);
            $chunk->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'failure_class' => null]);
        } catch (AiProcessingException $e) {
            if (isset($e->diagnostics['records_returned'])) {
                // An all-invalid response has no accepted records to persist. Keep only counts.
                $chunk->update(['result' => ['records' => [], '_validation' => $e->diagnostics,
                    '_returned_records' => $e->diagnostics['records_returned'],
                    '_dropped_records' => $e->diagnostics['rejections']]]);
            }
            $chunk->update(['failure_class' => $e->classification]);
            $capacity = in_array($e->classification, IncrementalPipeline::SPLITTABLE_FAILURES, true);
            $retry = $e->classification === 'transient' && $chunk->attempts < config('document_intelligence.attempts');
            // Settle this attempt first, so the ceiling check sees real spend, not its upper bound.
            $pipeline->settleCost($chunk, $providerCalled);
            $affordable = ($capacity || $retry) && $pipeline->canAffordMoreExtraction($document, $chunk);
            if ($e->classification === 'max_tokens' && $e->partial) {
                $pipeline->keepSalvaged($chunk, $document, $e->partial, $affordable);
            } elseif (($capacity || $retry) && ! $affordable) {
                $pipeline->stopForBudget($chunk);
            } elseif ($capacity) {
                $pipeline->split($chunk, $document);
            } elseif ($retry) {
                $chunk->update(['status' => 'queued']);
                self::dispatch($chunk->id, $this->dispatchToken)->onQueue(QueueTopology::for(ProcessDocumentChunkJob::class))
                    ->delay(max($e->retryAfter, 2 ** $chunk->attempts * 5) + random_int(0, 5));
            } else {
                // Terminal (invalid_evidence, invalid_schema, auth, billing, ...): never split or retried.
                $chunk->update(['status' => 'failed', 'completed_at' => now()]);
            }
        } catch (\Throwable) {
            $chunk->update(['status' => 'failed', 'failure_class' => 'deterministic', 'completed_at' => now()]);
        } finally {
            $pipeline->settleCost($chunk, $providerCalled);
            $this->logOutcome($chunk);
        }
        $pipeline->pump($document->id);
    }

    /** Metadata only: identifiers, timings, counts and USD amounts. Never text, quotes or responses. */
    private function logOutcome(DocumentChunk $chunk): void
    {
        try {
            $this->writeOutcomeLog($chunk->refresh());
        } catch (\Throwable) {
            // Diagnostics never change processing outcomes.
        }
    }

    private function writeOutcomeLog(DocumentChunk $chunk): void
    {
        $accounting = $chunk->cost_accounting ?? [];
        $runs = DocumentAiRun::where('chunk_id', $chunk->id)->where('request_attempt', $chunk->attempts)->get();
        $validation = $chunk->result['_validation'] ?? [];
        Log::info('Document intelligence validation summary', [
            'document_id' => $chunk->document_id, 'chunk_id' => $chunk->id, 'chunk_key' => $chunk->identity,
            'depth' => $chunk->depth, 'attempt' => $chunk->attempts, 'status' => $chunk->status, 'failure_class' => $chunk->failure_class,
            'input_tokens_counted' => $chunk->token_count, 'queue_wait_ms' => $accounting['queue_wait_ms'] ?? null,
            'job_ms' => $chunk->started_at ? (int) $chunk->started_at->diffInMilliseconds(now(), true) : null,
            'provider_ms' => (int) $runs->sum('duration_ms'), 'input_tokens' => (int) $runs->sum('input_tokens'),
            'output_tokens' => (int) $runs->sum('output_tokens'), 'stop_reason' => $runs->last()?->stop_reason,
            'document_running' => $accounting['document_running'] ?? null, 'global_running' => $accounting['global_running'] ?? null,
            'records_returned' => $validation['records_returned'] ?? $chunk->result['_returned_records'] ?? null,
            'records_kept' => $validation['records_kept'] ?? count($chunk->result['records'] ?? []),
            'records_dropped' => $validation['records_dropped'] ?? array_sum($chunk->result['_dropped_records'] ?? []),
            'rejections' => $validation['rejections'] ?? $chunk->result['_dropped_records'] ?? [],
            'rejection_reasons' => $validation['rejection_reasons'] ?? [],
            'records_date_metadata_sanitized' => $validation['records_date_metadata_sanitized'] ?? 0,
            'date_metadata_sanitized' => $validation['date_metadata_sanitized'] ?? [],
            'date_rejection_reasons_by_kind' => $validation['date_rejection_reasons_by_kind'] ?? [],
            'date_metadata_sanitized_by_kind' => $validation['date_metadata_sanitized_by_kind'] ?? [],
            'evidence_grounding_mode' => $validation['evidence_grounding_mode'] ?? null,
            'saturated' => $chunk->result['_saturated'] ?? false,
            'estimated_cost_usd' => $accounting['estimate'] ?? null, 'settled_cost_usd' => $accounting['cost'] ?? null,
            'actual_known' => $accounting['actual_known'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $chunk = DocumentChunk::find($this->chunkId);
        if ($chunk && $chunk->status === 'running') {
            $chunk->update(['status' => 'uncertain', 'failure_class' => 'worker_timeout']);
            app(IncrementalPipeline::class)->pump($chunk->document_id);
        }
    }
}
