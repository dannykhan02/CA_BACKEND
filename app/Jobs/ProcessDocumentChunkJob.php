<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDocumentChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Token count (15s) + one extraction request (extraction_timeout_seconds) + bookkeeping.
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $chunkId) {}

    public function handle(AnthropicClient $client, IncrementalPipeline $pipeline): void
    {
        $chunk = DocumentChunk::find($this->chunkId);
        $document = $chunk ? Document::find($chunk->document_id) : null;
        if (! $document?->canGenerateIntelligence() || $chunk->workspace_id !== $document->workspace_id
            || $chunk->pipeline_key !== ($document->ai_pipeline['key'] ?? null)) {
            return;
        }
        $text = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
        // Free provider token count before admission, so the reservation bounds the real input instead
        // of raw bytes (~3x tighter). A conservative byte bound remains when counting is unavailable.
        $counted = null;
        if (in_array($chunk->status, ['queued', 'pending'], true) && hash('sha256', $text) === $chunk->input_hash) {
            try {
                $counted = $client->countTokens($text);
            } catch (\Throwable) {
                $counted = null;
            }
        }
        $claimed = DB::transaction(function () use ($chunk, $document, $pipeline, $counted) {
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
            $slice = mb_substr($locked->extracted_text, $unit->start_offset, $unit->end_offset - $unit->start_offset);
            $escaped = strlen(json_encode($slice, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            // The request carries the slice JSON-escaped: every escape byte is bounded as one more token.
            $source = $counted === null ? $escaped : $counted + max(0, $escaped - strlen($slice));
            $cost = app(AiPricing::class)->reserve(
                app(AiModels::class)->forTask('extraction'),
                $source + strlen(json_encode([...$envelope, 'source_text' => ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    + strlen(json_encode(EvidenceSchema::extraction()))
                    + strlen(EvidenceSchema::instructions()) + 512,
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
            $tokens = $counted ?? app(ChunkPlanner::class)->estimate($text);
            if ($tokens > app(ExtractionCapacity::class)->maxRequestInputTokens()) {
                throw new AiProcessingException('context_overflow');
            }
            $chunk->update(['token_count' => $tokens]);
            $providerCalled = true;
            $result = $client->extractChunk($document, $chunk, $text);
            $chunk->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'failure_class' => null]);
        } catch (AiProcessingException $e) {
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
                self::dispatch($chunk->id)->onQueue('extraction')
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
        Log::info('Document intelligence chunk finished', [
            'document_id' => $chunk->document_id, 'chunk_id' => $chunk->id, 'identity' => $chunk->identity,
            'depth' => $chunk->depth, 'attempt' => $chunk->attempts, 'status' => $chunk->status, 'failure_class' => $chunk->failure_class,
            'input_tokens_counted' => $chunk->token_count, 'queue_wait_ms' => $accounting['queue_wait_ms'] ?? null,
            'job_ms' => $chunk->started_at ? (int) $chunk->started_at->diffInMilliseconds(now(), true) : null,
            'provider_ms' => (int) $runs->sum('duration_ms'), 'input_tokens' => (int) $runs->sum('input_tokens'),
            'output_tokens' => (int) $runs->sum('output_tokens'), 'stop_reason' => $runs->last()?->stop_reason,
            'document_running' => $accounting['document_running'] ?? null, 'global_running' => $accounting['global_running'] ?? null,
            'records_returned' => $chunk->result['_returned_records'] ?? null, 'records_kept' => count($chunk->result['records'] ?? []),
            'records_dropped' => array_sum($chunk->result['_dropped_records'] ?? []), 'saturated' => $chunk->result['_saturated'] ?? false,
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
