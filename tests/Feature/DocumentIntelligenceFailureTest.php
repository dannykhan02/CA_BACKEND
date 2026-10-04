<?php

namespace Tests\Feature;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\DocumentIntelligenceService;
use App\Services\Documents\DocumentReprocessor;
use App\Services\EntitlementService;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentIntelligenceFailureTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $status = 'Processing'): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        Sanctum::actingAs($user);

        return Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Document.pdf', 'type' => 'PDF', 'status' => $status,
            'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026,
            'extracted_text' => 'Stale text must never make a failed document eligible.',
        ]);
    }

    public static function jobs(): array
    {
        return [
            [GenerateInsightsJob::class, 'extractDocumentInsights'],
            [ClassifyDocumentTypeJob::class, 'classifyDocumentType'],
            [ExtractDocumentEntitiesJob::class, 'extractDocumentEntities'],
            [DetectDocumentRisksJob::class, 'detectDocumentRisks'],
            [DetectDocumentDeadlinesJob::class, 'detectDocumentDeadlines'],
            [GenerateDocumentSummaryJob::class, 'generateDocumentSummary'],
        ];
    }

    #[DataProvider('jobs')]
    public function test_failed_documents_never_start_intelligence_or_reserve_allowance(string $jobClass, string $method): void
    {
        $document = $this->document('Failed');
        $client = $this->mock(AnthropicClient::class);
        $client->shouldNotReceive($method);
        $this->mock(EntitlementService::class)->shouldNotReceive('reserveDocument');

        (new $jobClass($document->id, true))->handle($client, app(PipelineStageRecorder::class));

        $this->assertDatabaseCount('processing_jobs', 0);
        $this->assertDatabaseCount('document_ai_runs', 0);
        $this->assertDatabaseCount('billing_operations', 0);
        $this->assertSame(5, $document->workspace->credits->documents_remaining);
    }

    #[DataProvider('jobs')]
    public function test_queued_job_rechecks_document_before_execution_without_consuming_allowance(string $jobClass, string $method): void
    {
        $document = $this->document();
        app(EntitlementService::class)->reserveDocument($document);
        $queued = serialize(new $jobClass($document->id, true));
        $document->update(['status' => 'Failed', 'error_message' => 'Extraction failed.']);
        $before = DB::table('billing_operations')->get()->toJson();
        $ledgerCount = DB::table('credit_ledger')->count();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldNotReceive($method);

        unserialize($queued)->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame($before, DB::table('billing_operations')->get()->toJson());
        $this->assertSame($ledgerCount, DB::table('credit_ledger')->count());
        $this->assertSame(5, $document->workspace->credits->documents_remaining);
        $this->assertDatabaseCount('processing_jobs', 0);
        $this->assertSame('Extraction failed.', $document->fresh()->error_message);
    }

    #[DataProvider('jobs')]
    public function test_document_failure_during_provider_call_abandons_results_and_preserves_cause(string $jobClass, string $method): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive($method)->once()->andReturnUsing(function () use ($document) {
            $document->update(['status' => 'Failed', 'error_message' => 'Validation failed.']);
            return []; // No result should be read or persisted after abandonment.
        });

        (new $jobClass($document->id, true))->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('Validation failed.', $document->fresh()->error_message);
        $this->assertSame('skipped', $document->processingJobs()->sole()->status);
        $this->assertSame(5, $document->workspace->credits->documents_remaining);
        foreach (['document_type_classifications', 'document_entities', 'document_risks', 'document_deadlines', 'document_intelligence_summaries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_dispatch_and_batch_completion_recheck_live_document(): void
    {
        Bus::fake();
        $document = $this->document();
        $dispatcher = new class {
            use DispatchesIntelligenceChain { dispatchIntelligenceChain as public dispatch; }
        };
        $dispatcher->dispatch($document);
        Bus::assertBatchCount(1);
        $document->update(['status' => 'Failed']);
        $dispatcher->dispatch($document);
        Bus::assertBatchCount(1);
        $batch = Bus::batched(fn ($batch) => true)->first();
        foreach ($batch->options['finally'] as $callback) {
            $callback();
        }
        Bus::assertNotDispatched(GenerateDocumentSummaryJob::class);
    }

    public function test_failure_contract_blocks_pending_stages_and_preserves_real_analysis_failures(): void
    {
        $document = $this->document('Failed');
        $document->update(['error_message' => 'Could not extract text: the file may be corrupted or password-protected.']);
        $recorder = app(PipelineStageRecorder::class);
        $recorder->fail($recorder->start($document, 'extract'), 'Unreadable file');
        $recorder->start($document, 'entities');
        $recorder->fail($recorder->start($document, 'risks'), 'Earlier provider failure');
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.document.status', 'Failed')
            ->assertJsonPath('data.document.processingFailure.kind', 'processing')
            ->assertJsonPath('data.document.processingFailure.recovery', 'replace')
            ->assertJsonPath('data.processing.entities', 'blocked')
            ->assertJsonPath('data.processing.document_summary', 'blocked')
            ->assertJsonPath('data.processing.risks', 'failed');
        $this->getJson("/api/documents/{$document->id}")->assertOk()
            ->assertJsonPath('data.processingFailure.stage', 'extract');
        $this->postJson("/api/documents/{$document->id}/reprocess", ['intelligence_only' => true])->assertStatus(422);
        $this->assertDatabaseCount('billing_operations', 0);

        $document->update(['status' => 'Ready', 'error_message' => null]);
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->once()->andThrow(new \RuntimeException('Provider unavailable'));
        (new DetectDocumentRisksJob($document->id, true))->handle($client, $recorder);
        $this->assertSame('Ready', $document->fresh()->status);
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.document.processingFailure', null)
            ->assertJsonPath('data.processing.risks', 'failed');
    }

    public function test_inflight_provider_error_does_not_mask_document_failure(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->once()->andReturnUsing(function () use ($document) {
            $document->update(['status' => 'Failed', 'error_message' => 'Scan failed.']);
            throw new \RuntimeException('Secondary provider error');
        });
        (new DetectDocumentRisksJob($document->id, true))->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('Scan failed.', $document->fresh()->error_message);
        $this->assertSame('skipped', $document->processingJobs()->sole()->status);
    }

    public function test_provider_retries_stop_if_document_fails_after_first_attempt(): void
    {
        $document = $this->document();
        $this->seed(\Database\Seeders\DocumentRisksPromptSeeder::class);
        Http::fake(function () use ($document) {
            $document->update(['status' => 'Failed', 'error_message' => 'Validation failed.']);
            return Http::response([], 503);
        });
        (new DetectDocumentRisksJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Http::assertSentCount(1);
        $this->assertSame('skipped', $document->processingJobs()->sole()->status);
        $this->assertDatabaseCount('document_ai_runs', 0);
    }

    public function test_inflight_insights_error_preserves_prerequisite_failure(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('extractDocumentInsights')->once()->andReturnUsing(function () use ($document) {
            $document->update(['status' => 'Failed', 'error_message' => 'Validation failed.']);
            throw new \RuntimeException('Secondary AI error');
        });
        (new GenerateInsightsJob($document->id, true))->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('Validation failed.', $document->fresh()->error_message);
        $this->assertSame('skipped', $document->processingJobs()->sole()->status);
    }

    public function test_failure_callbacks_keep_specific_file_failure_and_recovery(): void
    {
        $document = $this->document('Failed');
        $document->update(['error_message' => 'File failed malware scan and was not processed.']);
        (new ScanUploadedFileJob($document->id))->failed(new \RuntimeException('Malware detected'));
        (new ExtractDocumentTextJob($document->id))->failed(new \RuntimeException('Extraction error'));
        $this->assertSame('File failed malware scan and was not processed.', $document->fresh()->error_message);
        $this->assertSame('replace', app(DocumentIntelligenceService::class)->getDocumentFailure($document->fresh())['recovery']);
        Bus::fake();
        $this->postJson("/api/documents/{$document->id}/reprocess")->assertStatus(422);
        Bus::assertNothingDispatched();
    }

    public function test_parent_ai_failure_is_distinct_and_processing_retry_restarts_prerequisites(): void
    {
        $document = $this->document('Failed');
        $recorder = app(PipelineStageRecorder::class);
        $recorder->fail($recorder->start($document, 'ai_analysis'), 'Provider unavailable');
        $this->assertSame('analysis', app(DocumentIntelligenceService::class)->getDocumentFailure($document)['kind']);
        Bus::fake();
        app(DocumentReprocessor::class)->reprocess($document, $document->uploader);
        Bus::assertDispatched(ScanUploadedFileJob::class);
        Bus::assertNotDispatched(ClassifyDocumentTypeJob::class);
        $this->assertSame('Processing', $document->fresh()->status);
        $this->assertNull($document->fresh()->extracted_text);
    }

    public function test_targeted_deadline_retry_preserves_successful_stages_and_rejects_duplicate_request(): void
    {
        $document = $this->document('Ready');
        $document->update(['credit_accounted_at' => now(), 'insights' => ['Existing finding']]);
        $recorder = app(PipelineStageRecorder::class);
        foreach (['document_type', 'entities', 'risks', 'document_summary'] as $stage) {
            $recorder->complete($recorder->start($document, $stage));
        }
        $recorder->fail($recorder->start($document, 'deadlines'), 'Provider unavailable');
        Bus::fake();

        $this->postJson("/api/documents/{$document->id}/reprocess", [
            'intelligence_only' => true, 'stage' => 'deadlines',
        ])->assertAccepted()
            ->assertJsonPath('data.insights.0', 'Existing finding')
            ->assertJsonPath('data.status', 'Ready');

        Bus::assertBatchCount(1);
        $this->assertSame('completed', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['risks']);
        $this->assertSame('pending', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['deadlines']);
        $this->assertSame('pending', app(DocumentIntelligenceService::class)->getProcessingStatus($document)['document_summary']);
        $this->postJson("/api/documents/{$document->id}/reprocess", [
            'intelligence_only' => true, 'stage' => 'deadlines',
        ])->assertStatus(422);
        $this->assertDatabaseCount('document_ai_runs', 0);
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_needs_review_with_stale_text_cannot_start_optional_analysis(): void
    {
        $document = $this->document('Needs Review');
        $client = $this->mock(AnthropicClient::class);
        $client->shouldNotReceive('detectDocumentDeadlines');
        (new DetectDocumentDeadlinesJob($document->id, true))->handle($client, app(PipelineStageRecorder::class));
        $this->postJson("/api/documents/{$document->id}/reprocess", [
            'intelligence_only' => true, 'stage' => 'deadlines',
        ])->assertStatus(422);
        $this->assertDatabaseCount('processing_jobs', 0);
    }

    public function test_exact_evidence_excerpt_has_page_only_when_page_boundaries_are_known(): void
    {
        $document = $this->document('Ready');
        $document->update(['pages' => 2, 'extracted_text' => "Introduction.\fDebt increased in the quarter."]);
        $risk = DocumentRisk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'title' => 'Debt pressure', 'description' => 'Debt rose.', 'severity' => 'high',
            'confidence' => .9, 'evidence' => 'Debt increased in the quarter.',
            'prompt_version' => 'test',
        ]);
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath("data.sourcePages.risk:{$risk->id}", 2);
    }
}
