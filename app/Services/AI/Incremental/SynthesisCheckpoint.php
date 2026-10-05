<?php

namespace App\Services\AI\Incremental;

use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\DB;

class SynthesisCheckpoint
{
    public function claim(Document $document): ?DocumentChunk
    {
        return DB::transaction(function () use ($document) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $key = $locked->ai_pipeline['key'];
            if (DocumentChunk::where('document_id', $locked->id)->where('pipeline_key', $key)
                ->whereIn('stage', ['extraction', 'merge'])->whereIn('status', ['pending', 'queued', 'running'])->exists()) {
                return null;
            }
            $last = count(config('document_intelligence.synthesis_levels')) - 1;
            $pipeline = app(IncrementalPipeline::class);
            $first = true;
            while (true) {
                $level = EvidenceBudget::level($locked);
                $data = app(EvidenceBudget::class)->forSynthesis($locked);
                $unit = $this->unit($locked, $data);
                if ($first && $unit->status === 'completed') {
                    return $unit;
                }
                // Duplicate delivery: another worker owns (or finished) this exact checkpoint.
                if ($unit->status !== 'pending') {
                    return null;
                }
                $first = false;
                if (array_sum(array_map(fn ($group) => count($data[$group] ?? []), ['entities', 'risks', 'deadlines', 'kpis', 'facts'])) === 0) {
                    $unit->update(['status' => 'failed', 'failure_class' => 'no_evidence', 'completed_at' => now()]);
                    $locked->forceFill(['status' => 'Needs Review', 'progress' => 100,
                        'error_message' => 'Document intelligence could not produce usable evidence.',
                        'ai_pipeline' => [...$locked->ai_pipeline, 'partial' => true, 'synthesis' => 'no_evidence']])->save();

                    return null;
                }
                // Recalculate against the exact bounded evidence, prompt and schema now available.
                $reservation = app(AnthropicClient::class)->synthesisReservation($locked, $data, $level);
                $locked->forceFill(['ai_pipeline' => [...$locked->ai_pipeline, ...$reservation]])->save();
                $cost = $reservation['synthesis_reserved_usd'] === null || $reservation['repair_reserved_usd'] === null
                    ? null : $reservation['synthesis_reserved_usd'] + $reservation['repair_reserved_usd'];
                // Below the last level, keep one cheaper fallback affordable.
                $protect = $level < $last ? (float) ($reservation['synthesis_degraded_reserved_usd'] ?? 0) : 0.0;
                if ($pipeline->canReserve($locked, $cost, synthesis: true, protect: $protect)) {
                    $pipeline->reserveCost($unit, $cost, ['synthesis_level' => $level,
                        'source_context' => $data['coverage']['source_text'] ?? null],
                        [$reservation['synthesis_reserved_usd'], $reservation['repair_reserved_usd']]);

                    return $unit;
                }
                // Too little budget for this level: degrade source context before giving up.
                if ($level < $last && $cost !== null) {
                    $unit->update(['status' => 'superseded', 'failure_class' => 'budget_degraded', 'completed_at' => now()]);
                    $locked->forceFill(['ai_pipeline' => [...$locked->ai_pipeline, 'synthesis_reductions' => $level + 1,
                        'synthesis_degradations' => [...($locked->ai_pipeline['synthesis_degradations'] ?? []),
                            ['from' => $level, 'to' => $level + 1, 'reason' => 'budget']]]])->save();

                    continue;
                }
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);
                $afterTimeout = in_array('timeout', array_column($locked->ai_pipeline['synthesis_degradations'] ?? [], 'reason'), true);
                $locked->forceFill(['status' => 'Needs Review', 'progress' => 100,
                    'error_message' => $afterTimeout
                        ? 'Final synthesis timed out and could not be retried within the remaining AI budget. Your extracted evidence is preserved.'
                        : 'Available evidence is preserved, but document synthesis could not be completed within its AI budget.',
                    'ai_pipeline' => [...$locked->ai_pipeline, 'partial' => true, 'synthesis' => 'budget_exceeded',
                        'synthesis_failure_reason' => $afterTimeout ? 'timeout_then_budget' : 'budget']])->save();

                return null;
            }
        });
    }

    /** One checkpoint per exact evidence/source input, model, prompt, revision and fallback level. */
    private function unit(Document $locked, array $data): DocumentChunk
    {
        $model = app(AiModels::class)->forTask('document_summary');
        $version = (string) AiPrompt::active('document_summary')->version;
        $hash = hash('sha256', json_encode([$data, $model, $version,
            $locked->ai_pipeline['analysis_revision'] ?? 0, $locked->ai_pipeline['synthesis_reductions'] ?? 0]));

        return DocumentChunk::firstOrCreate(['document_id' => $locked->id,
            'pipeline_key' => $locked->ai_pipeline['key'], 'identity' => 'synthesis:'.$hash],
            ['workspace_id' => $locked->workspace_id, 'stage' => 'synthesis', 'input_hash' => $hash,
                'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => $version]);
    }
}
