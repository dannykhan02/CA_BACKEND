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

    public function test_expired_pending_attempt_is_failed_and_late_job_cannot_restart_it(): void
    {
        $document = $this->document();
        $pending = $this->attempt($document, 'entities', 'pending');
        $pending->forceFill(['created_at' => now()->subMinutes(61)])->save();

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
}
