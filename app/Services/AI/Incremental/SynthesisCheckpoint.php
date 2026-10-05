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
            $data = app(EvidenceBudget::class)->forSynthesis($locked);
            $model = app(AiModels::class)->forTask('document_summary');
            $version = (string) AiPrompt::active('document_summary')->version;
            $hash = hash('sha256', json_encode([$data, $model, $version,
                $locked->ai_pipeline['analysis_revision'] ?? 0, $locked->ai_pipeline['synthesis_reductions'] ?? 0]));
            $unit = DocumentChunk::firstOrCreate(['document_id' => $locked->id,
                'pipeline_key' => $key, 'identity' => 'synthesis:'.$hash],
                ['workspace_id' => $locked->workspace_id, 'stage' => 'synthesis', 'input_hash' => $hash,
                    'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => $version]);
            if ($unit->status === 'completed') {
                return $unit;
            }
            if ($unit->status !== 'pending') {
                return null;
            }
            if (array_sum(array_map(fn ($group) => count($data[$group] ?? []), ['entities', 'risks', 'deadlines', 'kpis', 'facts'])) === 0) {
                $unit->update(['status' => 'failed', 'failure_class' => 'no_evidence', 'completed_at' => now()]);
                $locked->forceFill(['status' => 'Needs Review', 'progress' => 100,
                    'error_message' => 'Document intelligence could not produce usable evidence.',
                    'ai_pipeline' => [...$locked->ai_pipeline, 'partial' => true, 'synthesis' => 'no_evidence']])->save();

                return null;
            }
            // Recalculate against the exact bounded evidence, prompt and schema now available.
            $reservation = app(AnthropicClient::class)->synthesisReservation($locked, $data);
            $locked->forceFill(['ai_pipeline' => [...$locked->ai_pipeline, ...$reservation]])->save();
            $cost = $reservation['synthesis_reserved_usd'] === null || $reservation['repair_reserved_usd'] === null
                ? null : $reservation['synthesis_reserved_usd'] + $reservation['repair_reserved_usd'];
            $pipeline = app(IncrementalPipeline::class);
            if (! $pipeline->canReserve($locked, $cost, synthesis: true)) {
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);
                $locked->forceFill(['status' => 'Needs Review', 'progress' => 100,
                    'error_message' => 'Available evidence is preserved, but document synthesis could not be completed within its AI budget.',
                    'ai_pipeline' => [...$locked->ai_pipeline, 'partial' => true, 'synthesis' => 'budget_exceeded']])->save();

                return null;
            }
            $pipeline->reserveCost($unit, $cost);

            return $unit;
        });
    }
}
