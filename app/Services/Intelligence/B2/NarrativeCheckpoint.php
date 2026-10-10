<?php

namespace App\Services\Intelligence\B2;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\IncrementalPipeline;
use Illuminate\Support\Facades\DB;

/**
 * One B2 attempt, claimed as a `document_chunks` unit at stage `brief_synthesis`.
 *
 * This is the same checkpoint pattern the Stage A synthesis uses, and it is why B2 needs no new
 * table and no spend accounting of its own:
 *  - identity is the input hash, so an unchanged evidence set, contract, prompt, verifier and model
 *    resolve to the unit that already holds the answer and no provider call is made;
 *  - reserved_cost on the unit is what IncrementalPipeline::canReserve() sums, so B2 is bounded by
 *    the same per-document ceiling (DOCINTEL_MAX_DOCUMENT_COST_USD, lowered by any AI-credits
 *    quote) as extraction and synthesis, and cannot overrun it;
 *  - document_ai_runs rows link to the unit by chunk_id, so docintel:ai-usage-report groups B2 by
 *    its stage with no change to that command.
 *
 * B2 runs after the document is Ready. It therefore claims only what is left over: if the document
 * budget is already committed, the claim fails and B1 is served.
 */
class NarrativeCheckpoint
{
    public function __construct(private IncrementalPipeline $pipeline, private AiPricing $pricing) {}

    /**
     * @return array{unit:DocumentChunk|null,reason:string|null,reused:bool}
     */
    public function claim(Document $document, string $inputHash, int $contextTokens, string $model): array
    {
        return DB::transaction(function () use ($document, $inputHash, $contextTokens, $model) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->first();
            $key = $locked?->ai_pipeline['key'] ?? null;
            if (! $locked || ! is_string($key)) {
                return ['unit' => null, 'reason' => 'no_pipeline', 'reused' => false];
            }
            $unit = DocumentChunk::firstOrCreate(
                ['document_id' => $locked->id, 'pipeline_key' => $key,
                    'identity' => 'brief_synthesis:'.$inputHash],
                ['workspace_id' => $locked->workspace_id, 'stage' => 'brief_synthesis',
                    'input_hash' => $inputHash,
                    'pipeline_version' => (string) config('document_intelligence.pipeline_version'),
                    'prompt_version' => (string) config('intelligence_v2.b2.prompt_version')]);

            if ($unit->status === 'completed') {
                // Identical inputs already have an answer, verified or rejected. Never pay twice.
                return ['unit' => $unit, 'reason' => null, 'reused' => true];
            }
            if ($unit->status !== 'pending') {
                // Another worker owns this exact attempt, or it is terminally failed.
                return ['unit' => null, 'reason' => 'in_flight', 'reused' => false];
            }
            if ($unit->attempts >= (int) config('intelligence_v2.b2.attempts')) {
                return ['unit' => null, 'reason' => 'attempts_exhausted', 'reused' => false];
            }

            $cost = $this->pricing->reserve($model, $this->inputBound($contextTokens),
                (int) config('intelligence_v2.b2.max_output_tokens'), cacheWrite: true);
            if ($cost === null) {
                return ['unit' => null, 'reason' => 'unpriced_model', 'reused' => false];
            }
            if (! $this->pipeline->canReserve($locked, $cost, synthesis: true)) {
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded',
                    'completed_at' => now()]);

                return ['unit' => null, 'reason' => 'budget_exceeded', 'reused' => false];
            }
            $this->pipeline->reserveCost($unit, $cost, ['b2_context_tokens' => $contextTokens,
                'b2_model' => $model], [$cost]);

            return ['unit' => $unit->fresh(), 'reason' => null, 'reused' => false];
        });
    }

    /** System prompt and JSON envelope on top of the already-bounded context. */
    private function inputBound(int $contextTokens): int
    {
        $planner = strlen(NarrativePrompt::system())
            + strlen(json_encode(NarrativeSchema::schema(), JSON_THROW_ON_ERROR));

        return $contextTokens + (int) ceil($planner / 3) + 512;
    }
}
