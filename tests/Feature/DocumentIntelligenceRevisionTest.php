<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Models\Document;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentKpi;
use App\Models\OcrResult;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentIntelligenceRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.revision_v1' => true]);
    }

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();

        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Revision.docx', 'type' => 'DOCX',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'fixture')]);
    }

    private function ready(Document $document, string $revisionId, string $text, string $value): void
    {
        $document->forceFill(['extracted_text' => $text, 'status' => 'Ready',
            'insights' => [$value], 'ai_pipeline' => ['route' => 'normal']])->save();
        DocumentKpi::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revisionId, 'label' => 'Revenue', 'value' => $value]);
        DocumentIntelligenceSummary::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revisionId, 'executive_summary' => $value,
            'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
            'important_entities' => [], 'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revisionId, 'stage' => $stage, 'status' => 'completed']);
        }
    }

    public function test_candidate_is_invisible_and_publication_switches_every_typed_read(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $first = $publisher->begin($document);
        $document->forceFill(['pages' => 2])->save();
        $this->ready($document->fresh(), $first->id, 'Old text 100', '100');
        $candidateView = app(DocumentIntelligenceService::class)->publishedView($document->fresh());
        self::assertSame('Processing', $candidateView->status);
        self::assertNull($candidateView->extracted_text);
        self::assertCount(0, $candidateView->kpis);

        $publisher->publish($document, $first->id);
        $second = $publisher->begin($document->fresh());
        $document->fresh()->forceFill(['pages' => 3])->save();
        $this->ready($document->fresh(), $second->id, 'New text 200', '200');
        $oldView = app(DocumentIntelligenceService::class)->publishedView($document->fresh());
        self::assertSame('Old text 100', $oldView->extracted_text);
        self::assertSame(2, $oldView->pages);
        self::assertSame('100', $oldView->kpis->sole()->value);
        self::assertSame('100', $oldView->intelligenceSummary->executive_summary);

        $publisher->publish($document, $second->id);
        self::assertSame($first->id, $second->fresh()->previous_published_revision_id);
        self::assertSame('reprocess', $second->fresh()->publication_reason);
        $newView = app(DocumentIntelligenceService::class)->publishedView($document->fresh());
        self::assertSame('New text 200', $newView->extracted_text);
        self::assertSame(3, $newView->pages);
        self::assertSame('200', $newView->kpis->sole()->value);
        self::assertSame('200', $newView->intelligenceSummary->executive_summary);
        self::assertSame('Old text 100', $oldView->extracted_text);
        self::assertSame('100', $oldView->kpis->sole()->value);
    }

    public function test_later_generation_blocks_older_publication_and_failed_candidate_leaves_old_pointer(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $first = $publisher->begin($document);
        $this->ready($document->fresh(), $first->id, 'First text', '100');
        $publisher->publish($document, $first->id);
        $old = $document->fresh()->published_intelligence_revision_id;

        $stale = $publisher->begin($document->fresh());
        $current = $publisher->begin($document->fresh());
        self::assertFalse($publisher->owns($document->fresh(), $stale->id));
        self::assertTrue($publisher->owns($document->fresh(), $current->id));
        $this->expectException(\LogicException::class);
        try {
            $publisher->publish($document, $stale->id);
        } finally {
            $publisher->fail($document, $current->id, 'superseded_by_newer_run');
            self::assertSame($old, $document->fresh()->published_intelligence_revision_id);
            self::assertSame('First text', app(DocumentIntelligenceService::class)
                ->publishedView($document->fresh())->extracted_text);
        }
    }

    public function test_superseded_summary_attempt_cannot_replace_the_new_pipeline_metadata(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $old = $publisher->begin($document);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental', 'key' => 'old-key']])->save();
        $staleSnapshot = $document->fresh();
        $publisher->begin($staleSnapshot);
        $document->fresh()->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'new-key', 'synthesis_reductions' => 0]])->save();

        // Invoke the write at the race boundary: the job passed its initial owner check,
        // then a newer revision became active before it saved synthesis metadata.
        $write = (new \ReflectionClass(GenerateDocumentSummaryJob::class))->getMethod('updateOwnedPipeline');
        self::assertFalse($write->invoke(new GenerateDocumentSummaryJob($document->id, revisionId: $old->id),
            $staleSnapshot, ['synthesis_reductions' => 3]));
        self::assertEqualsCanonicalizing(['route' => 'incremental', 'key' => 'new-key', 'synthesis_reductions' => 0],
            $document->fresh()->ai_pipeline);
    }

    public function test_publication_retry_is_idempotent_and_ready_status_alone_is_insufficient(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $revision = $publisher->begin($document);
        $document->forceFill(['status' => 'Ready', 'extracted_text' => 'Unfinished'])->save();
        self::assertNull($document->fresh()->published_intelligence_revision_id);
        $this->expectException(\LogicException::class);
        $publisher->publish($document, $revision->id);
    }

    public function test_old_stage_attempts_cannot_satisfy_a_new_candidate(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $first = $publisher->begin($document);
        $this->ready($document->fresh(), $first->id, 'Old', '100');
        $publisher->publish($document, $first->id);
        $second = $publisher->begin($document->fresh());
        $document->fresh()->forceFill(['extracted_text' => 'New', 'status' => 'Ready'])->save();
        DocumentIntelligenceSummary::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $second->id, 'executive_summary' => 'new',
            'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
            'important_entities' => [], 'recommended_attention' => [], 'prompt_version' => 'test']);

        $this->expectException(\LogicException::class);
        try {
            $publisher->publish($document, $second->id);
        } finally {
            self::assertSame($first->id, $document->fresh()->published_intelligence_revision_id);
            self::assertSame('Old', app(DocumentIntelligenceService::class)
                ->publishedView($document->fresh())->extracted_text);
        }
    }

    public function test_first_failed_revision_has_no_visible_candidate_intelligence(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $candidate = $publisher->begin($document);
        $document->forceFill(['extracted_text' => 'Unpublished text', 'insights' => ['unpublished'],
            'status' => 'Failed'])->save();
        $publisher->fail($document, $candidate->id, 'extraction_failed');
        $view = app(DocumentIntelligenceService::class)->publishedView($document->fresh());
        self::assertSame('Failed', $view->status);
        self::assertNull($view->extracted_text);
        self::assertSame([], $view->kpis->all());
        self::assertNull($document->fresh()->published_intelligence_revision_id);
    }

    public function test_ocr_page_checkpoints_are_owned_by_generation(): void
    {
        $document = $this->document();
        $publisher = app(DocumentRevisionPublisher::class);
        $first = $publisher->begin($document);
        OcrResult::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'intelligence_revision_id' => $first->id, 'page_number' => 1, 'engine' => 'printed',
            'raw_text' => 'old']);
        $second = $publisher->begin($document->fresh());
        OcrResult::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'intelligence_revision_id' => $second->id, 'page_number' => 1, 'engine' => 'printed',
            'raw_text' => 'new']);
        self::assertSame('new', $document->ocrResults()->where('intelligence_revision_id', $second->id)
            ->orderBy('page_number')->pluck('raw_text')->implode("\f"));
        self::assertSame(2, $document->ocrResults()->count());
    }

    public function test_summary_job_publishes_one_complete_normal_revision_without_a_live_provider(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        Queue::fake([GenerateEmbeddingsJob::class]);
        $document = $this->document();
        $revision = app(DocumentRevisionPublisher::class)->begin($document);
        $document->forceFill(['extracted_text' => 'Revenue was 100.', 'ai_pipeline' => ['route' => 'normal'],
            'credit_accounted_at' => now()])->save();
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
        }
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => json_encode([
                'executive_summary' => 'Revenue was 100.', 'key_findings' => [],
                'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
                'recommended_attention' => [], 'material_findings' => [], 'trends' => [],
                'tensions' => [], 'questions' => [],
            ])]],
        ])]);

        (new GenerateDocumentSummaryJob($document->id, revisionId: $revision->id))
            ->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        self::assertSame($revision->id, $document->fresh()->published_intelligence_revision_id);
        self::assertSame('Revenue was 100.', app(DocumentIntelligenceService::class)
            ->publishedView($document->fresh())->intelligenceSummary->executive_summary);
        Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
    }
}
