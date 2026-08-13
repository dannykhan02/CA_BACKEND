<?php

namespace App\Services\AI;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\AnthropicClient;

/**
 * Orchestration façade over the independently-proven Day 2/3/4 jobs.
 * Deliberately calls each job's handle() directly rather than dispatching
 * to the queue, so a caller (e.g. a future pipeline stage or an explicit
 * "analyze now" action) gets synchronous, ordered execution with each
 * stage's success/failure immediately visible — queued dispatch remains
 * available separately via each job's own ::dispatch() for the existing
 * standalone/async use case.
 *
 * Does NOT duplicate throttle/retry/JSON-decode/validation/AI-run-recording
 * — every stage below delegates entirely to AnthropicClient and the
 * existing jobs, which already own that logic.
 */
class DocumentIntelligenceEngine
{
    public function __construct(
        private AnthropicClient $client,
        private PipelineStageRecorder $recorder,
    ) {}

    /**
     * Runs document_type → entities → risks → deadlines → summary in order.
     * Each stage is independent: a failure in one does not prevent the next
     * from running (matches the Day 3/4 "partial extraction must not crash
     * the pipeline" principle) — summary generation is the one exception,
     * since a summary grounded in zero extracted data is still valid
     * (empty arrays), so it always runs last regardless of prior failures.
     *
     * @return array<string, bool> stage => succeeded
     */
    public function analyze(Document $document, bool $forceReprocess = false): array
    {
        $results = [];

        foreach ([
            'document_type' => ClassifyDocumentTypeJob::class,
            'entities' => ExtractDocumentEntitiesJob::class,
            'risks' => DetectDocumentRisksJob::class,
            'deadlines' => DetectDocumentDeadlinesJob::class,
        ] as $stage => $jobClass) {
            try {
                (new $jobClass($document->id, $forceReprocess))->handle($this->client, $this->recorder);
                $results[$stage] = true;
            } catch (\Throwable $e) {
                // Each job already records its own failure via
                // PipelineStageRecorder — swallow here only to let
                // subsequent independent stages still attempt to run.
                $results[$stage] = false;
            }
        }

        try {
            (new GenerateDocumentSummaryJob($document->id, $forceReprocess))->handle($this->client, $this->recorder);
            $results['document_summary'] = true;
        } catch (\Throwable $e) {
            $results['document_summary'] = false;
        }

        return $results;
    }
}
