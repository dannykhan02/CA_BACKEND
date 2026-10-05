<?php

namespace App\Services\AI\Incremental;

use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use Illuminate\Support\Facades\DB;

class SynthesisCheckpoint
{
    public function claim(Document $document): ?DocumentChunk
    {
        $data = app(EvidenceBudget::class)->forDocument($document);
        $model = app(AiModels::class)->forTask('document_summary');
        $version = (string) AiPrompt::active('document_summary')->version;
        $hash = hash('sha256', json_encode([$data, $model, $version]));

        return DB::transaction(function () use ($document, $hash, $version, $model, $data) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $unit = DocumentChunk::firstOrCreate(['document_id' => $document->id,
                'pipeline_key' => $document->ai_pipeline['key'], 'identity' => 'synthesis:'.$hash],
                ['workspace_id' => $document->workspace_id, 'stage' => 'synthesis', 'input_hash' => $hash,
                    'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => $version]);
            if ($unit->status === 'completed') {
                return $unit;
            }
            if ($unit->status !== 'pending') {
                return null;
            }
            $cost = app(AiPricing::class)->estimate($model, ['input_tokens' => strlen(json_encode($data)) + 3000,
                'output_tokens' => config('services.anthropic.max_tokens') * 2]);
            if ($cost !== null) {
                $cost *= 2;
            } // Reserve room for one targeted repair, including input.
            $spent = DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $unit->pipeline_key)->sum('reserved_cost');
            if ($cost === null || $spent + $cost > $locked->ai_pipeline['budget_usd']) {
                $unit->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);
                $locked->forceFill(['ai_pipeline' => [...$locked->ai_pipeline, 'partial' => true, 'synthesis' => 'budget_exceeded']])->save();

                return null;
            }
            $unit->update(['status' => 'running', 'started_at' => now(), 'attempts' => $unit->attempts + 1, 'reserved_cost' => $unit->reserved_cost + $cost]);

            return $unit;
        });
    }
}
