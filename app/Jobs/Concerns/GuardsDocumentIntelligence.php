<?php

namespace App\Jobs\Concerns;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\EntitlementService;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Support\Facades\DB;

trait GuardsDocumentIntelligence
{
    protected ?string $intelligenceStageAttemptId = null;

    private function startIntelligence(Document $document, string $stage, PipelineStageRecorder $recorder): ?ProcessingJob
    {
        // Serialize the eligibility check and reservation with document updates.
        // Match document completion: lock the document before billing's workspace lock.
        return DB::transaction(function () use ($document, $stage, $recorder) {
            $current = Document::whereKey($document->id)->lockForUpdate()->first();
            if (! $current?->canGenerateIntelligence()) {
                return null;
            }
            $pendingId = $this->queuedStageId ?? null;
            if ($pendingId) {
                $reserved = ProcessingJob::whereKey($pendingId)
                    ->where('document_id', $current->id)->where('stage', $stage)->first();
                $latestId = $current->processingJobs()->where('stage', $stage)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('id');
                if ($reserved?->status === 'failed' && $this->attempts() > 1
                    && $latestId === $pendingId) {
                    // Queue retry of the same dispatch: preserve the failed
                    // first attempt and start a fresh attempt-history row.
                    $pendingId = null;
                } elseif ($reserved?->status !== 'pending'
                    || $latestId !== $pendingId) {
                    return null;
                }
            }

            // Optional retries on an already-accounted document must not
            // create a new allowance reservation or require a fresh credit.
            if (! $current->credit_accounted_at) {
                app(EntitlementService::class)->reserveDocument($current);
            }

            $attempt = $recorder->start($current, $stage, $this->job?->uuid(), $pendingId);
            $this->intelligenceStageAttemptId = $attempt->id;

            return $attempt;
        });
    }

    private function finalizeIntelligenceFailure(string $stage, \Throwable $e): void
    {
        $attempt = null;
        if ($this->intelligenceStageAttemptId) {
            $attempt = ProcessingJob::find($this->intelligenceStageAttemptId);
        } elseif ($this->job?->uuid()) {
            $attempt = ProcessingJob::where('document_id', $this->documentId)
                ->where('stage', $stage)->where('input->queue_job_uuid', $this->job->uuid())
                ->orderByDesc('created_at')->orderByDesc('id')->first();
        }
        if (! $attempt && ($this->queuedStageId ?? null)) {
            $attempt = ProcessingJob::find($this->queuedStageId);
        }

        if ($attempt && $attempt->document_id === $this->documentId && $attempt->stage === $stage) {
            app(PipelineStageRecorder::class)->fail($attempt, 'Analysis attempt failed or timed out. Please retry this section.');
        }
    }

    private function persistIntelligence(
        Document $document,
        ProcessingJob $stage,
        PipelineStageRecorder $recorder,
        callable $persist,
        array $output,
    ): void {
        try {
            DB::transaction(function () use ($document, $stage, $recorder, $persist, $output) {
                Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if (! $recorder->isCurrent($stage)) {
                    return;
                }
                $persist();
                $recorder->complete($stage, $output);
            });
        } catch (\Throwable $e) {
            $recorder->fail($stage, 'Analysis results could not be saved. Please retry this section.');
            throw $e;
        }
    }

    private function abandonIntelligence(Document $document, ProcessingJob $stage): bool
    {
        if (! app(PipelineStageRecorder::class)->isCurrent($stage)) {
            return true;
        }
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
