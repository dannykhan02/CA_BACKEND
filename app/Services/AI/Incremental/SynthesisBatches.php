<?php

namespace App\Services\AI\Incremental;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentRevisionPublisher;
use Illuminate\Support\Facades\DB;

/** One durable, paid batch per delivery; duplicate deliveries cannot make duplicate calls. */
class SynthesisBatches
{
    public function identity(array $batch): string
    {
        return 'synthesis-batch:'.hash('sha256', json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function required(Document $document): bool
    {
        return config('document_intelligence.hierarchical_synthesis', true)
            && count(app(SynthesisCoverage::class)->plan($document)['batches']) > 1;
    }

    /** Returns processed, waiting, stale, or ready. */
    public function processNext(Document $document, ?string $revisionId, AnthropicClient $client): string
    {
        if (! config('document_intelligence.hierarchical_synthesis', true)) {
            return 'ready';
        }
        $plan = app(SynthesisCoverage::class)->plan($document);
        if (count($plan['batches']) <= 1) {
            return 'ready';
        }
        foreach ($plan['batches'] as $batch) {
            $claim = DB::transaction(function () use ($document, $revisionId, $batch, $client) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if (! app(DocumentRevisionPublisher::class)->owns($locked, $revisionId)
                    || $locked->ai_pipeline['key'] !== $document->ai_pipeline['key']) {
                    return ['unit' => null, 'claimed' => false];
                }
                $identity = $this->identity($batch);
                $unit = DocumentChunk::firstOrCreate(['document_id' => $locked->id,
                    'pipeline_key' => $locked->ai_pipeline['key'], 'identity' => $identity],
                    ['workspace_id' => $locked->workspace_id, 'stage' => 'synthesis_batch',
                        'input_hash' => substr($identity, strlen('synthesis-batch:')),
                        'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => '1']);
                if ($unit->status !== 'pending') {
                    return ['unit' => $unit, 'claimed' => false];
                }
                $repairFormat = in_array($unit->failure_class, ['malformed_output', 'invalid_schema'], true);
                $cost = $client->evidenceBatchReservation($batch, $repairFormat);
                // Preserve a final evidence-only request and its possible repair. This bound is
                // recalculated from the configured model and full final evidence allowance.
                $final = $client->synthesisReservation($locked, null, count(config('document_intelligence.synthesis_levels')) - 1);
                $protect = (float) $final['synthesis_reserved_usd'] + (float) $final['repair_reserved_usd'];
                $pipeline = app(IncrementalPipeline::class);
                if (! $pipeline->canReserve($locked, $cost, synthesis: true, protect: $protect)) {
                    $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded',
                        'result' => ['_failure' => ['group' => $batch['group'], 'input_count' => count($batch['items']),
                            'code' => 'budget_exceeded', 'attempt' => $unit->attempts, 'recoverable' => false]],
                        'completed_at' => now()]);

                    return ['unit' => $unit, 'claimed' => false];
                }
                $pipeline->reserveCost($unit, $cost, ['group' => $batch['group'], 'evidence_count' => count($batch['items'])]);

                return ['unit' => $unit, 'claimed' => true];
            });
            $unit = $claim['unit'];
            if ($unit === null) {
                return 'stale';
            }
            if (in_array($unit->status, ['completed', 'budget', 'failed', 'uncertain'], true)) {
                continue;
            }
            if (! $claim['claimed']) {
                return 'waiting';
            }
            $called = false;
            try {
                $client->setRunContext(['chunk_id' => $unit->id, 'pipeline_version' => $unit->pipeline_version,
                    'request_attempt' => $unit->attempts]);
                $called = true;
                $result = $client->summarizeEvidenceBatch($document, $batch,
                    in_array($unit->failure_class, ['malformed_output', 'invalid_schema'], true));
                // Keep the claim running until its spend is settled. A second worker must not
                // reserve a retry against the same checkpoint while this attempt is closing.
                app(IncrementalPipeline::class)->settleCost($unit, true);
                $unit->update(['status' => 'completed', 'result' => $result, 'failure_class' => null, 'completed_at' => now()]);
            } catch (\Throwable $e) {
                app(IncrementalPipeline::class)->settleCost($unit, $called);
                $failure = match (true) {
                    $e instanceof AiProcessingException => $e->classification,
                    $e instanceof AnthropicStructuredOutputException => $e->outputStatus,
                    default => 'internal_validation',
                };
                // Only a received, malformed response can safely get one format repair attempt.
                // A timeout or unknown failure may already have consumed its provider allowance.
                $formatRetry = in_array($failure, ['malformed_output', 'invalid_schema'], true)
                    && $unit->attempts < min(2, (int) config('document_intelligence.attempts'));
                $retry = $formatRetry || ($failure === 'transient'
                    && $unit->attempts < (int) config('document_intelligence.attempts'));
                $unit->update(['status' => $retry ? 'pending' : 'failed', 'failure_class' => $failure,
                    'result' => ['_failure' => ['group' => $batch['group'], 'input_count' => count($batch['items']),
                        'code' => $failure, 'exception' => class_basename($e), 'attempt' => $unit->attempts,
                        'recoverable' => $retry]],
                    'completed_at' => $retry ? null : now()]);
            } finally {
                app(IncrementalPipeline::class)->settleCost($unit, $called);
                $client->setRunContext([]);
            }

            return 'processed';
        }

        return 'ready';
    }
}
