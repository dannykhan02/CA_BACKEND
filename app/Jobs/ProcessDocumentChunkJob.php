<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessDocumentChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

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
        $claimed = DB::transaction(function () use ($chunk, $document, $pipeline) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $unit = DocumentChunk::whereKey($chunk->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canGenerateIntelligence() || $unit->pipeline_key !== ($locked->ai_pipeline['key'] ?? null)) {
                return false;
            }
            if (! in_array($unit->status, ['queued', 'pending'], true)) {
                return false;
            }
            $cost = app(AiPricing::class)->reserve(
                app(AiModels::class)->forTask('extraction'),
                strlen(json_encode(['document_name' => $locked->name, 'start_page' => $unit->start_page,
                    'end_page' => $unit->end_page, 'source_text' => mb_substr($locked->extracted_text,
                        $unit->start_offset, $unit->end_offset - $unit->start_offset)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    + strlen(json_encode(EvidenceSchema::extraction()))
                    + strlen(EvidenceSchema::instructions()) + 512,
                (int) config('document_intelligence.extraction_max_tokens'),
                cacheWrite: true
            );
            if (! $pipeline->canReserve($locked, $cost)) {
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);

                return false;
            }
            $pipeline->reserveCost($unit, $cost);

            return true;
        });
        if (! $claimed) {
            $pipeline->pump($document->id);

            return;
        }
        $chunk->refresh();
        $text = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
        $providerCalled = false;
        try {
            if (hash('sha256', $text) !== $chunk->input_hash) {
                throw new AiProcessingException('input_changed');
            }
            // Check actual tokens before generation. A conservative local estimate remains on endpoint failure.
            try {
                $tokens = $client->countTokens($text);
            } catch (\Throwable) {
                $tokens = app(ChunkPlanner::class)
                    ->estimate($text);
            }
            if ($tokens > config('document_intelligence.chunk_max_tokens')) {
                throw new AiProcessingException('context_overflow');
            }
            $chunk->update(['token_count' => $tokens]);
            $providerCalled = true;
            $result = $client->extractChunk($document, $chunk, $text);
            $chunk->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'failure_class' => null]);
        } catch (AiProcessingException $e) {
            $chunk->update(['failure_class' => $e->classification]);
            if (in_array($e->classification, ['max_tokens', 'context_overflow', 'timeout'], true)) {
                $pipeline->split($chunk, $document);
            } elseif ($e->classification === 'transient' && $chunk->attempts < config('document_intelligence.attempts')) {
                $chunk->update(['status' => 'queued']);
                self::dispatch($chunk->id)->onQueue('extraction')
                    ->delay(max($e->retryAfter, 2 ** $chunk->attempts * 5) + random_int(0, 5));
            } else {
                $chunk->update(['status' => 'failed', 'completed_at' => now()]);
            }
        } catch (\Throwable) {
            $chunk->update(['status' => 'failed', 'failure_class' => 'deterministic', 'completed_at' => now()]);
        } finally {
            $pipeline->settleCost($chunk, $providerCalled);
        }
        $pipeline->pump($document->id);
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
