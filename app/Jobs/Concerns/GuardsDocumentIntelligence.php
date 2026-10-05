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
                if (! $reserved || $latestId !== $pendingId
                    || in_array($reserved->status, ['completed', 'skipped'], true)) {
                    return null;
                }
                if ($reserved->status === 'failed' && ! $reserved->started_at && $this->attempts() === 1) {
                    // An unclaimed reservation expired while waiting in the
                    // queue. Its original delivery may not restart it.
                    return null;
                }
                if ($this->attempts() > 1 || $reserved->status === 'failed') {
                    // The reservation belongs to the first execution. A queue
                    // retry (or a replay of its failed payload) needs its own
                    // history row, even when the older row was left processing.
                    $pendingId = null;
                } elseif ($reserved->status !== 'pending') {
                    return null;
                }
            }

            // Optional retries on an already-accounted document must not
            // create a new allowance reservation or require a fresh credit.
            if (! $current->credit_accounted_at) {
                app(EntitlementService::class)->reserveDocument($current);
            }

            $attempt = $recorder->start($current, $stage, $this->job?->uuid(), $pendingId,
                $this->job ? $this->attempts() : null);
            $this->intelligenceStageAttemptId = $attempt->id;

            return $attempt;
        });
    }

    private function finalizeIntelligenceFailure(string $stage, \Throwable $e): void
    {
        $queueUuid = $this->job?->uuid();
        $queueAttempt = $this->job ? $this->attempts() : null;
        $attempt = null;
        if ($this->intelligenceStageAttemptId) {
            $attempt = ProcessingJob::find($this->intelligenceStageAttemptId);
        }
        if (! $attempt && $queueUuid) {
            $attempt = ProcessingJob::where('document_id', $this->documentId)
                ->where('stage', $stage)->where('input->queue_job_uuid', $queueUuid)
                ->where('input->queue_attempt', $queueAttempt)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
        }
        if (! $attempt && $queueUuid && $queueAttempt === 1) {
            // Rows created before queue-attempt metadata existed can still be
            // finalized, but a later retry must never claim that older row.
            $attempt = ProcessingJob::where('document_id', $this->documentId)
                ->where('stage', $stage)->where('input->queue_job_uuid', $queueUuid)
                ->whereNull('input->queue_attempt')
                ->whereIn('status', ['pending', 'processing'])
                ->orderByDesc('created_at')->orderByDesc('id')->first();
        }
        if (! $attempt && ($this->queuedStageId ?? null)) {
            DB::transaction(function () use ($stage, $queueUuid, $queueAttempt): void {
                $document = Document::whereKey($this->documentId)->lockForUpdate()->first();
                $reserved = ProcessingJob::whereKey($this->queuedStageId)
                    ->where('document_id', $this->documentId)->where('stage', $stage)->first();
                if (! $document || ! $reserved || $document->processingJobs()->where('stage', $stage)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('id') !== $reserved->id) {
                    return;
                }

                $recorder = app(PipelineStageRecorder::class);
                if (($queueAttempt ?? 1) === 1 && in_array($reserved->status, ['pending', 'processing'], true)) {
                    $recorder->fail($reserved, 'Analysis attempt failed or timed out. Please retry this section.');
                } elseif ($queueUuid && in_array($reserved->status, ['pending', 'processing', 'failed'], true)) {
                    // The worker failed before it could claim a fresh queue
                    // retry row. Keep the reservation as history and record
                    // this execution as a separate terminal attempt.
                    $retry = $recorder->start($document, $stage, $queueUuid, null, $queueAttempt);
                    $recorder->fail($retry, 'Analysis attempt failed or timed out. Please retry this section.');
                }
            });

            return;
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
