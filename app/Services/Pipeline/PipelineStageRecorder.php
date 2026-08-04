<?php

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\ProcessingJob;

class PipelineStageRecorder
{
    public function start(Document $document, string $stage): ProcessingJob
    {
        return ProcessingJob::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'stage' => $stage,
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function complete(ProcessingJob $job, array $output = []): void
    {
        $job->forceFill(['status' => 'completed', 'output' => $output, 'completed_at' => now()])->save();
    }

    public function fail(ProcessingJob $job, string $message): void
    {
        $job->forceFill(['status' => 'failed', 'error_message' => $message, 'completed_at' => now()])->save();
    }

    public function skip(Document $document, string $stage, string $reason): void
    {
        ProcessingJob::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'stage' => $stage,
            'status' => 'skipped',
            'output' => ['reason' => $reason],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}