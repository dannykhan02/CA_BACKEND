<?php

namespace Tests\Feature;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentIntelligenceRevision;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Reprocessing an already-analysed document with revisions on. The reprocess runs the same
 * file, so the four optional stage jobs find a matching successful AI run from the previous
 * generation. They must still record their own result against the new candidate revision:
 * publish() gates on those stage rows, so a stage that skips itself makes the new generation
 * unpublishable, and the summary job then fails the revision — with the document left Ready
 * on its old published generation and nothing saying why.
 */
class RevisionReprocessStageSkipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.revision_v1' => true, 'ai_credits.enabled' => false]);
    }

    private function workspace(int $balance = 5): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $balance]);

        return $workspace;
    }

    /** A DOCX that has already been analysed, delivered and charged for. */
    private function settledDocument(Workspace $workspace): Document
    {
        $document = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id,
            'name' => 'Lease.docx', 'type' => 'DOCX', 'status' => 'Ready', 'classification' => 'Internal',
            'size_kb' => 12, 'year' => 2026, 'file_hash' => hash('sha256', 'lease-docx'),
            'extracted_text' => 'Rent is payable on 1 March 2026. The tenant indemnifies the landlord.',
            'insights' => ['Rent escalates annually.'], 'has_structured_data' => false]);
        // A DOCX carries no page count, and the charge for this document has already been taken.
        $document->forceFill(['ai_pipeline' => ['route' => 'normal'], 'pages' => 0,
            'credit_accounted_at' => now()->subDay()])->save();

        return $document->fresh();
    }

    /**
     * What the earlier successful analysis of this same file left behind: one successful AI run
     * per purpose and a completed stage row, which is exactly what the reprocessing cost guard
     * matches on.
     */
    private function previousGeneration(Document $document, string $revisionId): void
    {
        foreach (['insights' => 'ai_analysis', 'document_type' => 'document_type', 'entities' => 'entities',
            'risks' => 'risks', 'deadlines' => 'deadlines'] as $purpose => $stage) {
            DocumentAiRun::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'purpose' => $purpose, 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001',
                'status' => 'success', 'file_hash' => $document->file_hash, 'created_at' => now()->subDay()]);
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revisionId, 'stage' => $stage, 'status' => 'completed',
                'started_at' => now()->subDay(), 'completed_at' => now()->subDay()]);
        }
    }

    private function client(): AnthropicClient
    {
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('classifyDocumentType')->once()->andReturn([
            'document_type' => 'contract', 'confidence' => 0.9, 'reasoning' => 'Lease terms.',
            'prompt_version' => 1]);
        $client->shouldReceive('extractDocumentEntities')->once()->andReturn([
            'entities' => [['entity_type' => 'organization', 'value' => 'Acme', 'confidence' => 0.9]],
            'prompt_version' => 1]);
        $client->shouldReceive('detectDocumentRisks')->once()->andReturn([
            'risks' => [['title' => 'Indemnity', 'description' => 'Broad indemnity.', 'severity' => 'high',
                'confidence' => 0.8, 'evidence' => 'The tenant indemnifies the landlord.']],
            'prompt_version' => 1]);
        $client->shouldReceive('detectDocumentDeadlines')->once()->andReturn([
            'deadlines' => [['title' => 'Rent due', 'description' => 'Monthly rent.', 'due_date' => '2026-03-01',
                'date_type' => 'explicit', 'confidence' => 0.8, 'evidence' => 'Rent is payable on 1 March 2026.']],
            'prompt_version' => 1]);
        $client->shouldReceive('generateDocumentSummary')->once()->andReturn([
            'executive_summary' => 'A lease with a broad indemnity.', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'executive_assessment' => null, 'material_findings' => [],
            'trends' => [], 'tensions' => [], 'questions' => [], 'prompt_version' => 3]);

        return $client;
    }

    /** The four optional jobs and the summary job as the intelligence chain dispatches them. */
    private function runGeneration(Document $document, string $revisionId, AnthropicClient $client): void
    {
        $recorder = app(PipelineStageRecorder::class);
        foreach ([ClassifyDocumentTypeJob::class, ExtractDocumentEntitiesJob::class,
            DetectDocumentRisksJob::class, DetectDocumentDeadlinesJob::class] as $jobClass) {
            (new $jobClass($document->id, revisionId: $revisionId))->handle($client, $recorder);
        }
        (new GenerateDocumentSummaryJob($document->id, revisionId: $revisionId))->handle($client, $recorder);
    }

    private function stageStatus(Document $document, string $stage, string $revisionId): ?string
    {
        return ProcessingJob::where('document_id', $document->id)->where('stage', $stage)
            ->where('intelligence_revision_id', $revisionId)
            ->orderByDesc('created_at')->orderByDesc('id')->value('status');
    }

    /**
     * The production failure: generation 2 of an already-settled DOCX, every stage job reporting
     * success, no queue failure — and a failed revision.
     */
    public function test_reprocessed_settled_document_publishes_its_new_generation(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->settledDocument($workspace);
        $publisher = app(DocumentRevisionPublisher::class);

        $first = $publisher->begin($document);
        $this->previousGeneration($document->fresh(), $first->id);
        $publisher->fail($document->fresh(), $first->id, 'stages_incomplete');
        self::assertSame('failed', $first->fresh()->state);

        // documents:reprocess: a new candidate generation over the same file. The settled
        // document keeps its reservation-free, already-charged state.
        $second = $publisher->begin($document->fresh());
        $document->fresh()->forceFill(['status' => 'Processing', 'progress' => 0])->save();
        ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'intelligence_revision_id' => $second->id, 'stage' => 'ai_analysis', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now()]);
        $document->fresh()->forceFill(['status' => 'Ready', 'progress' => 100])->save();

        $this->runGeneration($document->fresh(), $second->id, $this->client());

        foreach (['document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            self::assertSame('completed', $this->stageStatus($document, $stage, $second->id),
                "Stage {$stage} must record its own result for the new revision.");
        }
        self::assertSame('published', $second->fresh()->state);
        self::assertSame($second->id, $document->fresh()->published_intelligence_revision_id);
        self::assertSame('Ready', $document->fresh()->status);
        self::assertNull($document->fresh()->active_intelligence_revision_id);
        self::assertSame('A lease with a broad indemnity.',
            $document->fresh()->intelligenceSummary()->where('intelligence_revision_id', $second->id)
                ->sole()->executive_summary);
    }

    /** Publishing a settled document's new generation needs no reservation and no second charge. */
    public function test_republication_of_a_settled_document_charges_nothing(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->settledDocument($workspace);
        $settledAt = $document->credit_accounted_at;
        $publisher = app(DocumentRevisionPublisher::class);

        $first = $publisher->begin($document);
        $this->previousGeneration($document->fresh(), $first->id);
        $publisher->fail($document->fresh(), $first->id, 'stages_incomplete');

        $second = $publisher->begin($document->fresh());
        ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'intelligence_revision_id' => $second->id, 'stage' => 'ai_analysis', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now()]);

        $this->runGeneration($document->fresh(), $second->id, $this->client());

        self::assertSame('published', $second->fresh()->state);
        self::assertSame(5, (int) WorkspaceCredit::where('workspace_id', $workspace->id)
            ->firstOrFail()->documents_remaining);
        self::assertSame(0, (int) DB::table('credit_ledger')->where('workspace_id', $workspace->id)
            ->where('direction', 'debit')->sum('amount'));
        self::assertEquals($settledAt, $document->fresh()->credit_accounted_at);
        // No reservation was taken for a document that is already paid for.
        self::assertDatabaseCount('billing_operations', 0);
    }

    /** A refusal to publish must never be silent. */
    public function test_failing_a_revision_logs_and_records_the_condition(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->settledDocument($workspace);
        $publisher = app(DocumentRevisionPublisher::class);
        $revision = $publisher->begin($document);

        // Generation 2 reaches the summary job with its deadlines stage still unrecorded.
        foreach (['ai_analysis', 'document_type', 'entities', 'risks'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed',
                'started_at' => now(), 'completed_at' => now()]);
        }
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            if ($message->level === 'warning') {
                $logged[] = $message;
            }
        });

        (new GenerateDocumentSummaryJob($document->id, revisionId: $revision->id))
            ->handle($this->mock(AnthropicClient::class), app(PipelineStageRecorder::class));

        $failed = DocumentIntelligenceRevision::findOrFail($revision->id);
        self::assertSame('failed', $failed->state);
        self::assertNotNull($failed->failed_at);
        self::assertSame('stages_incomplete', $failed->publication_reason);
        $warning = collect($logged)->first(fn ($message) => ($message->context['revision_id'] ?? null) === $revision->id);
        self::assertNotNull($warning, 'A refused publication must log at warning level.');
        self::assertSame($document->id, $warning->context['document_id']);
        self::assertSame($revision->id, $warning->context['revision_id']);
        self::assertSame('stages_incomplete', $warning->context['condition']);
    }
}
