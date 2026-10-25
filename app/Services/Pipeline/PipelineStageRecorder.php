<?php

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\ProcessingJob;

class PipelineStageRecorder
{
    private const MANAGED_STAGES = ['document_type', 'entities', 'risks', 'deadlines', 'document_summary', 'ai_analysis'];

    public function start(Document $document, string $stage, ?string $queueJobUuid = null, ?string $pendingId = null, ?int $queueAttempt = null): ProcessingJob
    {
        $revisionId = $document->getAttribute('read_intelligence_revision_id')
            ?? $document->active_intelligence_revision_id;
        $input = $queueJobUuid ? ['queue_job_uuid' => $queueJobUuid, 'queue_attempt' => $queueAttempt ?? 1] : null;
        if (in_array($stage, self::MANAGED_STAGES, true)) {
            $open = $document->processingJobs()->where('stage', $stage)
                ->where('intelligence_revision_id', $revisionId)
                ->whereIn('status', ['pending', 'processing'])
                ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->get();
            $pending = $pendingId ? $open->firstWhere('id', $pendingId) : null;
            foreach ($open as $previous) {
                if ($previous->is($pending)) {
                    continue;
                }
                $previous->forceFill([
                    'status' => 'skipped', 'completed_at' => now(),
                    'output' => ['reason' => 'Superseded by a newer attempt.'],
                ])->save();
            }
            if ($pending) {
                $pending->forceFill(['status' => 'processing', 'started_at' => now(),
                    'input' => $input])->save();

                return $pending;
            }
        }

        return ProcessingJob::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'intelligence_revision_id' => $revisionId,
            'stage' => $stage,
            'status' => 'processing',
            'started_at' => now(),
            'input' => $input,
        ]);
    }

    public function complete(ProcessingJob $job, array $output = []): void
    {
        ProcessingJob::whereKey($job->id)->where('status', 'processing')->update([
            'status' => 'completed', 'output' => json_encode($output), 'completed_at' => now(),
        ]);
    }

    public function fail(ProcessingJob $job, string $message, array $diagnostics = []): void
    {
        $updates = ['status' => 'failed', 'error_message' => $message, 'completed_at' => now()];
        if ($diagnostics !== []) {
            $updates['output'] = json_encode(['diagnostics' => $diagnostics]);
        }
        ProcessingJob::whereKey($job->id)->whereIn('status', ['pending', 'processing'])->update($updates);
    }

    public function isCurrent(ProcessingJob $job): bool
    {
        return $job->status === 'processing'
            && ProcessingJob::where('document_id', $job->document_id)->where('stage', $job->stage)
                ->where('intelligence_revision_id', $job->intelligence_revision_id)
                ->orderByDesc('created_at')->orderByDesc('id')->value('id') === $job->id
            && ProcessingJob::whereKey($job->id)->where('status', 'processing')->exists();
    }

    public function skip(Document $document, string $stage, string $reason): void
    {
        $revisionId = $document->getAttribute('read_intelligence_revision_id')
            ?? $document->active_intelligence_revision_id;
        ProcessingJob::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'intelligence_revision_id' => $revisionId,
            'stage' => $stage,
            'status' => 'skipped',
            'output' => ['reason' => $reason],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
