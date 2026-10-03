<?php

namespace App\Jobs\Concerns;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\EntitlementService;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Support\Facades\DB;

trait GuardsDocumentIntelligence
{
    private function startIntelligence(Document $document, string $stage, PipelineStageRecorder $recorder): ?ProcessingJob
    {
        // Serialize the eligibility check and reservation with document updates.
        // Match document completion: lock the document before billing's workspace lock.
        return DB::transaction(function () use ($document, $stage, $recorder) {
            $current = Document::whereKey($document->id)->lockForUpdate()->first();
            if (! $current?->canGenerateIntelligence()) {
                return null;
            }

            app(EntitlementService::class)->reserveDocument($current);

            return $recorder->start($current, $stage);
        });
    }

    private function abandonIntelligence(Document $document, ProcessingJob $stage): bool
    {
        if ($document->fresh()?->canGenerateIntelligence()) {
            return false;
        }

        // Abandonment is not an AI failure. The API reports these stages as
        // blocked while preserving the parent's original failure and history.
        $stage->forceFill([
            'status' => 'skipped',
            'output' => ['reason' => 'Document processing no longer permits intelligence.'],
            'completed_at' => now(),
        ])->save();

        return true;
    }
}
