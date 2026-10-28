<?php

namespace Tests\Feature;

use App\Http\Resources\ChartResource;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentIntelligenceSummary;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\DocumentIntelligenceService;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\PdfCategoricalCandidateCollector;
use App\Services\Intelligence\PdfCategoricalChartBuilder;
use App\Services\Intelligence\PdfCategoricalFieldVerifier;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\Intelligence\VerifiedPdfChartPersister;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class VerifiedPdfChartRevisionIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.revision_v1' => true,
            'document_intelligence.pdf_table_structure' => true,
            'document_intelligence.pdf_basic_table_structure' => false]);
    }

    public function test_failed_candidate_preserves_published_chart_and_hides_its_own_chart(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');

        self::assertSame(['charts' => 1, 'points' => 2], $this->persister()->persist($candidate, 'set-b'));
        self::assertSame([$published->id], $this->visibleChartIds($document));
        app(DocumentRevisionPublisher::class)->fail($document, $second->id, 'test_failure');

        self::assertSame([$published->id], $this->visibleChartIds($document));
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertSame(1, DocumentChart::where('document_id', $document->id)
            ->where('intelligence_revision_id', $second->id)->count());
    }

    public function test_failed_reanalysis_preserves_a_source_verified_published_pdf_chart(): void
    {
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $path = base_path('tests/Fixtures/synthetic_single_period_costs.pdf');
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        $document = $this->newDocument();
        $document->forceFill(['file_hash' => hash_file('sha256', $path)])->save();
        $first = app(DocumentRevisionPublisher::class)->begin($document);
        $document = $this->setPipeline($document->fresh(), $first, 'pipeline-a');
        app(StructuredExtractionStore::class)->publish($document, $source, $first->id);
        $document = $document->fresh();
        app(PdfCategoricalCandidateCollector::class)->collect($document);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 7, 'rejected' => 0],
            app(PdfCategoricalFieldVerifier::class)->verify($document, $setId));
        self::assertSame(['charts' => 1, 'points' => 7],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        $this->complete($document, $first);
        app(DocumentRevisionPublisher::class)->publish($document, $first->id);
        $published = DocumentChart::where('intelligence_revision_id', $first->id)->sole();
        self::assertSame($setId, $first->fresh()->provenance_set_id);
        self::assertSame('source_verified', $this->chartStatus($document, $published));

        [$second, $candidate] = $this->candidate($document->fresh(), 'pipeline-b');
        config()->set('document_intelligence.pdf_basic_table_structure', false);
        self::assertSame(['charts' => 1, 'points' => 2], $this->persister()->persist($candidate, 'set-b'));
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        self::assertSame('source_verified', $this->chartStatus($document, $published->fresh()));
        app(DocumentRevisionPublisher::class)->fail($candidate, $second->id, 'test_failure');

        self::assertSame([$published->id], $this->visibleChartIds($document));
        self::assertSame('source_verified', $this->chartStatus($document, $published->fresh()));
        $document->fresh()->forceFill(['file_hash' => hash('sha256', 'changed')])->save();
        self::assertSame('unavailable', $this->chartStatus($document, $published->fresh()));
    }

    public function test_successful_publication_switches_visibility_without_deleting_history(): void
    {
        [$document, $first, $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');
        $this->persister()->persist($candidate, 'set-b');
        $replacement = DocumentChart::where('intelligence_revision_id', $second->id)->sole();
        $this->complete($candidate, $second);
        app(DocumentRevisionPublisher::class)->publish($candidate, $second->id);

        self::assertSame([$replacement->id], $this->visibleChartIds($document));
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertSame($first->id, $published->fresh()->intelligence_revision_id);
        self::assertSame($second->id, $replacement->fresh()->intelligence_revision_id);
    }

    public function test_empty_candidate_and_retraction_touch_only_the_active_revision_and_pipeline(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');
        $own = $this->chart($candidate, $second->id, 'pipeline-b', 'Old candidate');
        $otherPipeline = $this->chart($candidate, $second->id, 'unrelated-pipeline', 'Other pipeline');
        $model = DocumentChart::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $second->id,
            'type' => 'bar', 'title' => 'Model chart', 'description' => '', 'data' => [],
            'source_metadata' => ['origin' => 'model_unverified']]);

        self::assertSame(['charts' => 0, 'points' => 0], $this->persister([])->persist($candidate, 'set-b'));
        self::assertFalse(DocumentChart::whereKey($own->id)->exists());
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertTrue(DocumentChart::whereKey($otherPipeline->id)->exists());
        self::assertTrue(DocumentChart::whereKey($model->id)->exists());
        $this->chart($candidate, $second->id, 'pipeline-b', 'Retract candidate');
        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($candidate, null));
        self::assertSame([$published->id], $this->visibleChartIds($document));
        self::assertTrue(DocumentChart::whereKey($otherPipeline->id)->exists());
        self::assertSame(1, DocumentChart::where('intelligence_revision_id', $second->id)
            ->where('source_metadata->origin', 'model_unverified')->count());
    }

    public function test_retry_replaces_own_rows_without_duplicates_or_other_pipeline_deletion(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');
        $otherPipeline = $this->chart($candidate, $second->id, 'unrelated-pipeline', 'Other pipeline');
        $partial = $this->chart($candidate, $second->id, 'pipeline-b', 'Partial attempt');
        DocumentChartPoint::create(['document_chart_id' => $partial->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Old', 'value' => 99, 'sort_order' => 0]);
        $persister = $this->persister();
        self::assertSame(['charts' => 1, 'points' => 2], $persister->persist($candidate, 'set-b'));
        self::assertSame(['charts' => 1, 'points' => 2], $persister->persist($candidate, 'set-b'));

        self::assertSame(1, DocumentChart::where('intelligence_revision_id', $second->id)
            ->where('source_metadata->pipeline_key', 'pipeline-b')->count());
        self::assertFalse(DocumentChart::whereKey($partial->id)->exists());
        self::assertSame(2, DocumentChartPoint::where('workspace_id', $document->workspace_id)
            ->whereIn('document_chart_id', DocumentChart::where('intelligence_revision_id', $second->id)
                ->where('source_metadata->pipeline_key', 'pipeline-b')->pluck('id'))->count());
        self::assertTrue(DocumentChart::whereKey($otherPipeline->id)->exists());
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
    }

    public function test_superseded_candidate_cannot_replace_new_candidate_or_published_charts(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $stale] = $this->candidate($document, 'pipeline-b');
        [$third, $current] = $this->candidate($document, 'pipeline-c');
        $currentChart = $this->chart($current, $third->id, 'pipeline-c', 'Current candidate');

        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($stale, 'set-b'));
        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($stale, null));
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertTrue(DocumentChart::whereKey($currentChart->id)->exists());
        self::assertSame($third->id, $document->fresh()->active_intelligence_revision_id);
        self::assertSame('superseded', $second->fresh()->state);
    }

    public function test_foreign_workspace_snapshot_cannot_modify_chart_rows(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');
        $foreign = $this->newDocument();
        $forged = clone $candidate;
        $forged->forceFill(['workspace_id' => $foreign->workspace_id]);

        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($forged, 'set-b'));
        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($forged, null));
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertSame(0, DocumentChart::where('intelligence_revision_id', $second->id)->count());
    }

    public function test_changed_source_cannot_trigger_candidate_replacement_or_retraction(): void
    {
        [$document, , $published] = $this->publishedDocument();
        [$second, $candidate] = $this->candidate($document, 'pipeline-b');
        $own = $this->chart($candidate, $second->id, 'pipeline-b', 'Candidate');
        $document->fresh()->forceFill(['file_hash' => hash('sha256', 'changed')])->save();

        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($candidate, 'set-b'));
        self::assertSame(['charts' => 0, 'points' => 0], $this->persister()->persist($candidate, null));
        self::assertTrue(DocumentChart::whereKey($published->id)->exists());
        self::assertTrue(DocumentChart::whereKey($own->id)->exists());
    }

    private function publishedDocument(): array
    {
        $document = $this->newDocument();
        $publisher = app(DocumentRevisionPublisher::class);
        $first = $publisher->begin($document);
        $document = $this->setPipeline($document, $first, 'pipeline-a');
        $published = $this->chart($document, $first->id, 'pipeline-a', 'Published PDF chart');
        $this->complete($document, $first);
        $publisher->publish($document, $first->id);

        return [$document->fresh(), $first, $published];
    }

    private function newDocument(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'revision.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => hash('sha256', 'source'), 'extracted_text' => 'source']);
    }

    private function candidate(Document $document, string $pipeline): array
    {
        $revision = app(DocumentRevisionPublisher::class)->begin($document->fresh());
        $snapshot = $this->setPipeline($document->fresh(), $revision, $pipeline);
        foreach (['one', 'two'] as $index => $name) {
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => $pipeline,
                'identity' => hash('sha256', $pipeline.$name), 'kind' => 'metric',
                'source_id' => $pipeline.':'.$name,
                'data' => ['measurement_verification' => 'verified',
                    'source_provenance_set_id' => 'set-b',
                    'source_revision' => hash('sha256', (string) $snapshot->extracted_text),
                    'source_claim_fingerprint' => 'claim-'.$name,
                    'pdf_value_anchor_id' => 'anchor-'.$name],
                'sources' => [['roles' => ['value'], 'span_id' => 'anchor-'.$name,
                    'page' => 1, 'start_offset' => $index, 'end_offset' => $index + 1]]]);
        }

        return [$revision, $snapshot];
    }

    private function setPipeline(Document $document, DocumentIntelligenceRevision $revision, string $key): Document
    {
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => $key]])->save();
        $revision->forceFill(['pipeline_key' => $key])->save();

        return $document->fresh();
    }

    private function complete(Document $document, DocumentIntelligenceRevision $revision): void
    {
        DocumentIntelligenceSummary::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
            'executive_summary' => 'Summary', 'key_findings' => [], 'critical_risks' => [],
            'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
                'stage' => $stage, 'status' => 'completed']);
        }
    }

    private function chart(Document $document, string $revision, string $pipeline, string $title): DocumentChart
    {
        return DocumentChart::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $revision,
            'type' => 'line', 'title' => $title, 'description' => '', 'data' => [],
            'source_metadata' => ['origin' => 'verified_pdf_table', 'pipeline_key' => $pipeline]]);
    }

    private function persister(?array $candidates = null): VerifiedPdfChartPersister
    {
        $metrics = Mockery::mock(MetricCollector::class);
        $metrics->shouldReceive('collect')->andReturn(['observations' => []]);
        $builder = Mockery::mock(ChartCandidateBuilder::class);
        $builder->shouldReceive('build')->andReturn(['candidates' => $candidates ?? [[
            'id' => 'candidate-b', 'basis' => 'time_series', 'sourceVerification' => 'verified',
            'unitVerification' => 'verified', 'numericVerification' => 'parsed', 'type' => 'line',
            'title' => 'Candidate PDF chart', 'description' => '', 'sourceIds' => ['pipeline-b:one', 'pipeline-b:two'],
            'points' => [['label' => 'Q1 2026', 'value' => 1, 'sourceIds' => ['pipeline-b:one']],
                ['label' => 'Q2 2026', 'value' => 2, 'sourceIds' => ['pipeline-b:two']]],
            'series' => [],
        ]]]);
        $extractions = Mockery::mock(StructuredExtractionStore::class);
        $extractions->shouldReceive('active')->andReturn(new StructuredDocument(
            'PDF', 'test+poppler-grid.v1', 'source', [], []));

        return new VerifiedPdfChartPersister($metrics, $builder, $extractions,
            app(PdfCategoricalChartBuilder::class));
    }

    private function visibleChartIds(Document $document): array
    {
        return app(DocumentIntelligenceService::class)->publishedView($document->fresh())
            ->charts()->pluck('id')->all();
    }

    private function chartStatus(Document $document, DocumentChart $chart): string
    {
        $request = Request::create('/api/documents/'.$document->id);
        $request->attributes->set('chart_published_document',
            app(DocumentIntelligenceService::class)->publishedView($document->fresh()));

        return (new ChartResource($chart))->toArray($request)['evidenceStatus'];
    }
}
