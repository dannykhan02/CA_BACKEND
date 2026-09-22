<?php

namespace App\Jobs\Concerns;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Services\Pipeline\PipelineStageRecorder;

/**
 * Day 8 Batch 3 — reprocessing cost guard. Compares $document->file_hash
 * against the file_hash on the most recent document_ai_runs row for the
 * given purpose. If unchanged and forceReprocess is not set, records a
 * 'skipped' processing_jobs row and returns true so the caller can bail
 * out before ever calling Claude.
 *
 * A job that uses this trait must expose a public bool $forceReprocess
 * property (set via constructor) — DocumentReprocessController is the
 * only current caller that sets it true, since manual reprocessing must
 * always bypass this guard.
 */
trait SkipsUnchangedDocuments
{
    protected function skipIfUnchanged(
        Document $document,
        string $purpose,
        string $stage,
        PipelineStageRecorder $recorder,
        array $onSkipDocumentUpdate = [],
        ?\Closure $verifyCompleted = null,
    ): bool {
        if ($this->forceReprocess) {
            return false;
        }

        if (! $document->file_hash) {
            // Nothing to compare against — never skip.
            return false;
        }

        $lastRun = DocumentAiRun::where('document_id', $document->id)
            ->where('purpose', $purpose)
            ->latest('created_at')
            ->first();

        if (! $lastRun || $lastRun->file_hash !== $document->file_hash) {
            return false;
        }

        // A DocumentAiRun row only proves Claude was called and its response
        // validated — not that the caller's own persistence step (DB writes,
        // status transition) actually finished. A crash or timeout between
        // the two leaves a matching run with nothing behind it, which would
        // otherwise cause every future attempt to skip forever. When the
        // caller supplies a completion check, honor it.
        if ($verifyCompleted && ! $verifyCompleted($document, $lastRun)) {
            \Illuminate\Support\Facades\Log::warning(
                "{$purpose}: matching AI run found (id {$lastRun->id}) but completion check failed — reprocessing instead of skipping.",
                ['document_id' => $document->id]
            );

            return false;
        }

        if ($onSkipDocumentUpdate) {
            $document->forceFill($onSkipDocumentUpdate)->save();
        }

        $recorder->skip($document, $stage, "unchanged file_hash since last {$purpose} run");

        return true;
    }
}
