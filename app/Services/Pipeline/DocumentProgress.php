<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\DB;

/**
 * Structured, user-facing processing stage (metadata only, no ETA) stored at
 * documents.ai_pipeline.progress_stage. The percentage only ever moves forward: a
 * single atomic statement takes GREATEST(current, new), so concurrent workers and
 * late deliveries cannot make it jump backwards. Only Processing documents change.
 */
class DocumentProgress
{
    public const LABELS = [
        'scanning' => 'Scanning file',
        'extracting_text' => 'Extracting text',
        'planning' => 'Planning document analysis',
        'extracting' => 'Extracting intelligence',
        'recovering' => 'Recovering a dense section',
        'merging' => 'Merging findings',
        'synthesizing' => 'Building final summary',
        'synthesis_retry' => 'Retrying final summary with reduced context',
        'finalizing' => 'Finalizing',
    ];

    public function record(string $documentId, string $stage, ?int $percent = null, array $detail = []): void
    {
        $payload = json_encode(['key' => $stage, 'label' => $this->label($stage, $detail), ...$detail,
            'updated_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        DB::update("UPDATE documents SET progress = CASE WHEN ?::int IS NULL THEN progress ELSE GREATEST(COALESCE(progress, 0), ?::int) END,
            ai_pipeline = jsonb_set(COALESCE(ai_pipeline, '{}'::jsonb), '{progress_stage}', ?::jsonb, true)
            WHERE id = ? AND status = 'Processing'", [$percent, $percent, $payload, $documentId]);
    }

    private function label(string $stage, array $detail): string
    {
        $label = self::LABELS[$stage] ?? 'Processing';
        if ($stage === 'extracting' && isset($detail['step'], $detail['total']) && $detail['total'] > 1) {
            return $label.' '.min($detail['step'], $detail['total']).' of '.$detail['total'];
        }

        return $label;
    }
}
