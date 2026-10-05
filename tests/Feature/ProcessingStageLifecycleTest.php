<?php

namespace Tests\Feature;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\DocumentEntity;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\DocumentIntelligenceService;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessingStageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Ready',
            'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026,
            'extracted_text' => 'Usable extracted text.',
        ]);
    }

    private function attempt(Document $document, string $stage, string $status): ProcessingJob
    {
        return ProcessingJob::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'stage' => $stage, 'status' => $status, 'started_at' => now(),
        ]);
    }

    private function summaryResult(): array
    {
        return [
            'executive_summary' => 'Debt increased.', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'executive_assessment' => null,
            'material_findings' => [], 'trends' => [], 'tensions' => [], 'questions' => [],
            'prompt_version' => 3,
        ];
    }

    private function queueJob(string $uuid, int $attempts, bool $fails = false): QueueJob
    {
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn($uuid);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        if ($fails) {
            $queueJob->shouldReceive('fail')->once();
        }

        return $queueJob;
    }

    public function test_optional_intelligence_jobs_fail_on_timeout(): void
    {
        foreach ([ClassifyDocumentTypeJob::class, ExtractDocumentEntitiesJob::class,
            DetectDocumentRisksJob::class, DetectDocumentDeadlinesJob::class,
            GenerateDocumentSummaryJob::class, GenerateInsightsJob::class] as $jobClass) {
            $this->assertTrue((new $jobClass('document-id'))->failOnTimeout, $jobClass);
        }
    }

    public function test_retry_reuses_pending_attempt_and_supersedes_older_processing(): void
    {
        $document = $this->document();
        $older = $this->attempt($document, 'entities', 'processing');
        $pending = $this->attempt($document, 'entities', 'pending');

        $current = app(PipelineStageRecorder::class)->start($document, 'entities', null, $pending->id);
        $this->assertSame($pending->id, $current->id);
        $this->assertSame('skipped', $older->fresh()->status);
        $this->assertNotNull($older->fresh()->completed_at);
        $this->assertSame('processing', $pending->fresh()->status);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'entities')->count());
        $this->assertSame('processing', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
    }

    public function test_latest_completed_attempt_wins_and_closes_historical_processing(): void
    {
        $document = $this->document();
        $older = $this->attempt($document, 'entities', 'processing');
        $latest = $this->attempt($document, 'entities', 'completed');

        $this->assertSame('completed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
        $this->assertSame('skipped', $older->fresh()->status);
        $this->assertSame('completed', $latest->fresh()->status);
    }

    public function test_latest_failed_attempt_wins_over_historical_processing(): void
    {
        $document = $this->document();
        $older = $this->attempt($document, 'entities', 'processing');
        $this->attempt($document, 'entities', 'failed');

        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
        $this->assertSame('skipped', $older->fresh()->status);
    }

    public function test_orphaned_processing_attempt_expires_without_keeping_document_updating(): void
    {
        $document = $this->document();
        $attempt = $this->attempt($document, 'entities', 'processing');
        $attempt->forceFill(['started_at' => now()->subMinutes(16)])->save();

        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
        $this->assertNotNull($attempt->fresh()->completed_at);
    }

    public function test_entity_timeout_failure_hook_finalizes_its_queued_attempt(): void
    {
        $document = $this->document();
        $pending = $this->attempt($document, 'entities', 'pending');
        $job = new ExtractDocumentEntitiesJob($document->id, true, $pending->id);
        app(PipelineStageRecorder::class)->start($document, 'entities', null, $pending->id);

        $job->failed(new \RuntimeException('Job timed out.'));

        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->completed_at);
        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
    }

    public function test_deserialized_timeout_hook_finds_attempt_by_queue_uuid(): void
    {
        $document = $this->document();
        $attempt = app(PipelineStageRecorder::class)->start($document, 'entities', 'queue-123');
        $job = new ExtractDocumentEntitiesJob($document->id);
        $queueJob = $this->mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn('queue-123');
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $job->setJob($queueJob);

        $job->failed(new \RuntimeException('Worker timeout.'));

        $this->assertSame('failed', $attempt->fresh()->status);
    }

    public function test_late_failure_from_superseded_attempt_cannot_replace_newer_completion(): void
    {
        $document = $this->document();
        $recorder = app(PipelineStageRecorder::class);
        $older = $recorder->start($document, 'entities', 'queue-old');
        $newer = $recorder->start($document, 'entities', 'queue-new');
        $recorder->complete($newer);
        $job = new ExtractDocumentEntitiesJob($document->id);
        $queueJob = $this->mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn('queue-old');
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $job->setJob($queueJob);

        $job->failed(new \RuntimeException('Late worker failure.'));

        $this->assertSame('skipped', $older->fresh()->status);
        $this->assertSame('completed', $newer->fresh()->status);
        $this->assertSame('completed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
    }

    public function test_queue_retry_of_failed_reserved_attempt_creates_new_history_row(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $reserved = $this->attempt($document, 'entities', 'failed');
        $job = new ExtractDocumentEntitiesJob($document->id, true, $reserved->id);
        $queueJob = $this->mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn('queue-retry');
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $job->setJob($queueJob);
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('extractDocumentEntities')->once()->andReturn([
            'entities' => [], 'prompt_version' => 1,
        ]);

        $job->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('failed', $reserved->fresh()->status);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'entities')->count());
        $this->assertSame('completed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
    }

    public function test_summary_first_execution_reuses_its_pending_reservation_and_supersedes_older_work(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $older = $this->attempt($document, 'document_summary', 'processing');
        $pending = $this->attempt($document, 'document_summary', 'pending');
        $job = new GenerateDocumentSummaryJob($document->id, true, $pending->id);
        $job->setJob($this->queueJob('summary-uuid', 1));
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('generateDocumentSummary')->once()->andReturn($this->summaryResult());

        $job->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('skipped', $older->fresh()->status);
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame(['queue_job_uuid' => 'summary-uuid', 'queue_attempt' => 1], $pending->fresh()->input);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'document_summary')->count());
        $this->assertSame('Debt increased.', $document->intelligenceSummary()->sole()->executive_summary);
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_summary_queue_retry_creates_a_new_attempt_and_failed_hook_targets_it(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $pending = $this->attempt($document, 'document_summary', 'pending');
        $first = new GenerateDocumentSummaryJob($document->id, true, $pending->id);
        $first->setJob($this->queueJob('summary-retry-uuid', 1, true));
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('generateDocumentSummary')->twice()->andThrow(new \RuntimeException('Invalid summary evidence.'));

        $first->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('failed', $pending->fresh()->status);

        $retry = new GenerateDocumentSummaryJob($document->id, true, $pending->id);
        $retry->setJob($this->queueJob('summary-retry-uuid', 2, true));
        $retry->handle($client, app(PipelineStageRecorder::class));
        $retryAttempt = $document->processingJobs()->where('stage', 'document_summary')
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        $this->assertNotSame($pending->id, $retryAttempt->id);
        $this->assertSame('failed', $retryAttempt->status);
        $this->assertNotNull($retryAttempt->completed_at);
        $this->assertSame(['queue_job_uuid' => 'summary-retry-uuid', 'queue_attempt' => 2], $retryAttempt->input);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'document_summary')->count());
        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['document_summary']);

        // Laravel deserializes a fresh job instance for failed(). It must
        // resolve this execution by UUID and attempt number, not reservation.
        $restored = new GenerateDocumentSummaryJob($document->id, true, $pending->id);
        $restored->setJob($this->queueJob('summary-retry-uuid', 2));
        $restored->failed(new \RuntimeException('Queue failure.'));
        $this->assertSame('failed', $retryAttempt->fresh()->status);
        $this->assertSame('failed', $pending->fresh()->status);
    }

    public function test_summary_retry_of_still_processing_reservation_supersedes_it(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $reserved = $this->attempt($document, 'document_summary', 'processing');
        $job = new GenerateDocumentSummaryJob($document->id, true, $reserved->id);
        $job->setJob($this->queueJob('summary-timeout-uuid', 2));
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('generateDocumentSummary')->once()->andReturn($this->summaryResult());

        $job->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('skipped', $reserved->fresh()->status);
        $this->assertNotNull($reserved->fresh()->completed_at);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'document_summary')->count());
        $this->assertSame('completed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['document_summary']);
    }

    public function test_summary_failure_hook_records_unclaimed_queue_retry_without_touching_newer_attempts(): void
    {
        $document = $this->document();
        $reserved = $this->attempt($document, 'document_summary', 'failed');
        $job = new GenerateDocumentSummaryJob($document->id, true, $reserved->id);
        $job->setJob($this->queueJob('unclaimed-retry-uuid', 2));

        $job->failed(new \RuntimeException('Worker failed before stage claim.'));

        $retry = $document->processingJobs()->where('stage', 'document_summary')
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($reserved->id, $retry->id);
        $this->assertSame('failed', $retry->status);
        $this->assertNotNull($retry->completed_at);
        $this->assertSame(['queue_job_uuid' => 'unclaimed-retry-uuid', 'queue_attempt' => 2], $retry->input);

        $newer = app(PipelineStageRecorder::class)->start($document, 'document_summary', 'newer-uuid', null, 1);
        $job->failed(new \RuntimeException('Late failure from old attempt.'));
        $this->assertSame('processing', $newer->fresh()->status);
        $this->assertSame(3, $document->processingJobs()->where('stage', 'document_summary')->count());
    }

    public function test_deserialized_summary_failure_hook_uses_queue_attempt_not_original_reservation(): void
    {
        $document = $this->document();
        $reserved = $this->attempt($document, 'document_summary', 'failed');
        $retry = app(PipelineStageRecorder::class)->start($document, 'document_summary',
            'same-queue-uuid', null, 2);
        $restored = new GenerateDocumentSummaryJob($document->id, true, $reserved->id);
        $restored->setJob($this->queueJob('same-queue-uuid', 2));

        $restored->failed(new \RuntimeException('Retry timed out.'));

        $this->assertSame('failed', $retry->fresh()->status);
        $this->assertNotNull($retry->fresh()->completed_at);
        $this->assertSame('failed', $reserved->fresh()->status);
        $this->assertSame(2, $document->processingJobs()->where('stage', 'document_summary')->count());
    }

    public function test_expired_pending_attempt_is_failed_and_late_job_cannot_restart_it(): void
    {
        $document = $this->document();
        $pending = $this->attempt($document, 'entities', 'pending');
        $pending->forceFill(['created_at' => now()->subMinutes(61), 'started_at' => null])->save();

        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
        $client = $this->mock(AnthropicClient::class);
        $client->shouldNotReceive('extractDocumentEntities');
        (new ExtractDocumentEntitiesJob($document->id, true, $pending->id))
            ->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('failed', $pending->fresh()->status);
    }

    public function test_entity_persistence_failure_after_ai_success_fails_stage(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('extractDocumentEntities')->once()->andReturn([
            'entities' => [['entity_type' => 'organization', 'value' => 'Acme', 'confidence' => 0.9]],
            'prompt_version' => 1,
        ]);
        DocumentEntity::creating(function () {
            throw new \RuntimeException('Simulated entity database failure.');
        });

        try {
            (new ExtractDocumentEntitiesJob($document->id, true))->handle($client, app(PipelineStageRecorder::class));
            $this->fail('Entity persistence failure should escape for queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated entity database failure.', $e->getMessage());
        }

        $this->assertDatabaseCount('document_entities', 0);
        $this->assertSame('failed', $document->processingJobs()->where('stage', 'entities')->sole()->status);
    }

    public function test_superseded_entity_worker_cannot_persist_its_late_response(): void
    {
        $document = $this->document();
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $recorder = app(PipelineStageRecorder::class);
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('extractDocumentEntities')->once()->andReturnUsing(function () use ($document, $recorder) {
            $recorder->start($document, 'entities');

            return ['entities' => [['entity_type' => 'organization', 'value' => 'Stale', 'confidence' => 0.9]],
                'prompt_version' => 1];
        });

        (new ExtractDocumentEntitiesJob($document->id, true))->handle($client, $recorder);

        $this->assertDatabaseCount('document_entities', 0);
        $this->assertSame('processing', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['entities']);
        $this->assertSame(1, $document->processingJobs()->where('stage', 'entities')->where('status', 'skipped')->count());
    }

    public function test_completed_summary_without_persisted_result_is_reconciled_as_failed(): void
    {
        $document = $this->document();
        $attempt = $this->attempt($document, 'document_summary', 'completed');

        $this->assertSame('failed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['document_summary']);
        $this->assertSame('failed', $attempt->fresh()->status);
    }

    public function test_api_reports_old_summary_attempt_pattern_as_failed_instead_of_updating(): void
    {
        $document = $this->document();
        $base = now()->subHours(3);
        $olderFailed = $this->attempt($document, 'document_summary', 'failed');
        $pending = $this->attempt($document, 'document_summary', 'pending');
        $processingOne = $this->attempt($document, 'document_summary', 'processing');
        $processingTwo = $this->attempt($document, 'document_summary', 'processing');
        foreach ([$olderFailed, $pending, $processingOne, $processingTwo] as $index => $attempt) {
            $timestamp = $base->copy()->addSeconds($index * 30);
            $attempt->forceFill(['created_at' => $timestamp, 'started_at' => $timestamp])->save();
        }
        Sanctum::actingAs(User::findOrFail($document->uploaded_by));

        $this->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->assertJsonPath('data.processing.document_summary', 'failed');

        $this->assertSame('failed', $processingTwo->fresh()->status);
        $this->assertNotNull($processingTwo->fresh()->completed_at);
        $this->assertSame('skipped', $pending->fresh()->status);
        $this->assertSame('skipped', $processingOne->fresh()->status);
        $this->assertSame('failed', $olderFailed->fresh()->status);
    }
}
