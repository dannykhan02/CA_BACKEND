<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\VerifiedPdfFindingPresenter;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use App\Support\QueueTopology;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\WorkerOptions;
use Tests\TestCase;

/** Real HTTP upload and job handlers, with only provider HTTP and dispatch transport replaced. */
class VerifiedPdfAppleWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private const HASH = 'e333dd821d9ef827e82d8e0b696493a127c4ee171ea63aca3a80dc1dfea60e89';

    private array $stageMs = [];

    private function timed(string $stage, callable $action): mixed
    {
        $start = hrtime(true);
        $result = $action();
        $this->stageMs[$stage] = round((hrtime(true) - $start) / 1e6, 1);

        return $result;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('documents');
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['document_intelligence.pdf_table_structure' => true,
            'document_intelligence.incremental' => true,
            'document_intelligence.large_tokens' => 100,
            'document_processing.clamav_enabled' => false,
            'services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 1000]),
            '*/messages' => Http::response($this->provider(['records' => [[
                'kind' => 'metric', 'label' => 'Operating income', 'value' => '72,421',
                'subject' => 'Apple Inc.', 'quote' => 'Operating income  29,589    27,900    72,421    68,273  ',
                'reference' => '', 'unit' => '$ millions', 'period' => 'Six Months Ended March 29, 2025',
                'entity_type' => null, 'metric_type' => null, 'value_basis' => null,
                'aggregation' => null, 'quantity_kind' => null, 'date_type' => null,
                'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => [],
            ], [
                'kind' => 'metric', 'label' => 'Operating income', 'value' => '72,422',
                'subject' => 'Apple Inc.', 'quote' => 'Operating income  29,589    27,900    72,421    68,273  ',
                'reference' => '', 'unit' => '$ millions', 'period' => 'Six Months Ended March 29, 2025',
                'entity_type' => null, 'metric_type' => null, 'value_basis' => null,
                'aggregation' => null, 'quantity_kind' => null, 'date_type' => null,
                'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => [],
            ]],
                'executive_summary' => 'Apple reported results in the supplied statement.',
                'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
                'important_entities' => [], 'recommended_attention' => []]), 200,
                ['request-id' => 'apple-local-test'])]);
    }

    private function provider(array $data): array
    {
        return ['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]]];
    }

    private function upload(): array
    {
        $path = base_path('tests/Fixtures/apple_fy25_q2_original.pdf');
        self::assertSame(self::HASH, hash_file('sha256', $path));
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user = $user->fresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 10]);
        $upload = new UploadedFile($path, 'apple_fy25_q2_original.pdf', 'application/pdf', null, true);
        $response = $this->timed('http_upload_ms', fn () => $this->actingAs($user, 'sanctum')
            ->postJson('/api/documents', ['file' => $upload, 'classification' => 'Internal'])
            ->assertAccepted());
        $document = Document::findOrFail($response->json('data.id'));
        self::assertSame(self::HASH, $document->file_hash);
        $this->postJson('/api/documents', ['file' => $upload, 'classification' => 'Internal'])
            ->assertStatus(409);

        return [$user, $document];
    }

    private function runUntilMerge(Document $document): DocumentChunk
    {
        $revision = $document->active_intelligence_revision_id;
        $this->timed('scan_job_ms', fn () => (new ScanUploadedFileJob($document->id, $revision))
            ->handle(app(PipelineStageRecorder::class)));
        $this->timed('extract_job_ms', fn () => (new ExtractDocumentTextJob($document->id, $revision))->handle(
            app(DocumentTextExtractor::class), app(PipelineStageRecorder::class),
            app(OcrEngineResolver::class), app(PdfRasterizer::class)));
        $this->timed('plan_job_ms', fn () => (new GenerateInsightsJob($document->id, revisionId: $revision))
            ->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class)));
        $pipeline = app(IncrementalPipeline::class);
        $chunksStart = hrtime(true);
        for ($pass = 0; $pass < 40; $pass++) {
            $unit = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')
                ->whereIn('status', ['queued', 'pending'])->orderBy('start_offset')->first();
            if ($unit === null) {
                break;
            }
            (new ProcessDocumentChunkJob($unit->id, $unit->dispatch_token))
                ->handle(app(AnthropicClient::class), $pipeline);
        }
        $this->stageMs['mocked_extraction_jobs_ms'] = round((hrtime(true) - $chunksStart) / 1e6, 1);
        self::assertSame(0, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'extraction')->whereIn('status', ['queued', 'pending', 'running'])->count());

        $merges = DocumentChunk::where('document_id', $document->id)->where('stage', 'merge')->get();
        self::assertCount(1, $merges, json_encode(DocumentChunk::where('document_id', $document->id)
            ->get(['stage', 'status', 'failure_class'])->toArray(), JSON_THROW_ON_ERROR));

        return $merges->sole();
    }

    public function test_http_upload_processes_exact_apple_pdf_and_publishes_verified_chart_contract(): void
    {
        [, $document] = $this->upload();
        $merge = $this->runUntilMerge($document);
        $this->timed('merge_publication_job_ms', fn () => (new MergeDocumentEvidenceJob($merge->id, $merge->dispatch_token))
            ->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class)));
        self::assertSame('completed', $merge->fresh()->status);
        self::assertSame(4, DB::table('document_charts')->where('document_id', $document->id)
            ->where('source_metadata->origin', 'verified_pdf_table')->count());
        self::assertSame(18, DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'like', 'pdf-cell:%')->get()
            ->filter(fn ($row) => ($row->data['measurement_verification'] ?? null) === 'verified')->count());
        $this->timed('mocked_synthesis_job_ms', fn () => (new GenerateDocumentSummaryJob($document->id,
            revisionId: $document->active_intelligence_revision_id))
            ->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class)));
        $document->refresh();
        self::assertSame('Ready', $document->status, json_encode([
            $document->error_message, $document->ai_pipeline,
            DocumentChunk::where('document_id', $document->id)->where('stage', 'synthesis')
                ->get(['status', 'failure_class'])->toArray(),
        ], JSON_THROW_ON_ERROR));
        $budget = app(EvidenceBudget::class)->forDocument($document);
        self::assertSame(2, $budget['coverage']['evidence_total']);
        self::assertStringNotContainsString('pdf-cell:', json_encode($budget, JSON_THROW_ON_ERROR));
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'pdf-cell:'));
        $documentResponse = $this->timed('http_document_ms', fn () =>
            $this->getJson("/api/documents/{$document->id}")->assertOk());
        $charts = $documentResponse->json('data.charts');
        self::assertCount(4, $charts);
        foreach ($charts as $chart) {
            self::assertSame('verified_pdf_table', $chart['sourceOrigin']);
            self::assertTrue($chart['renderEligible'], json_encode($chart));
            self::assertNotEmpty($chart['logicalChartId']);
            self::assertCount(2, $chart['data']);
            foreach ($chart['data'] as $point) {
                self::assertNotEmpty($point['sourceIds']);
                self::assertContains($point['source']['page'], [1, 3]);
                self::assertSame($point['sourceValue'], DB::table('source_anchors')
                    ->where('id', $point['source']['anchor_id'])->value('source_text'));
            }
        }
        $intelligenceResponse = $this->timed('http_intelligence_ms', fn () =>
            $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk());
        $analysis = $intelligenceResponse->json('data.analysis');
        self::assertNotNull($analysis);
        self::assertFalse($analysis['limitations']['pdfFinancialTables']['completeDocumentAnalysis']);
        self::assertGreaterThanOrEqual(1, $analysis['stats']['reconciledEquivalentFindings'],
            json_encode(array_map(static fn ($item) => [
                $item->sourceId, $item->label, $item->conceptKey, $item->subject,
                $item->period?->label, $item->measurement->exactMagnitude,
                $item->measurement->kind, $item->measurement->family,
                $item->measurement->currency, $item->integrity['governingOwner'] ?? null,
            ], array_values(array_filter(app(MetricCollector::class)->collect($document)['observations'],
                static fn ($item) => $item->label === 'Operating income'
                    && $item->measurement->exactMagnitude === '72421000000'))), JSON_THROW_ON_ERROR));
        $displayed = collect($analysis['analysisGroups'])->flatMap(fn ($group) => $group['items']);
        self::assertTrue($displayed->contains(fn ($item) => $item['label'] === 'Operating income'
            && $item['value'] === '72,422'
            && ! ($item['measurementIntegrity']['analyticalEligible'] ?? false)));
        $evidence = DocumentEvidence::where('document_id', $document->id)->get();
        $reconciled = app(VerifiedPdfFindingPresenter::class)->reconcile($document, $evidence,
            app(MetricCollector::class)->collect($document)['observations']);
        $equivalent = $evidence->first(fn ($row) => ! str_starts_with($row->source_id, 'pdf-cell:')
            && ($row->data['value'] ?? null) === '72,421');
        $conflict = $evidence->first(fn ($row) => ! str_starts_with($row->source_id, 'pdf-cell:')
            && ($row->data['value'] ?? null) === '72,422');
        self::assertContains($equivalent->source_id, $reconciled['hidden']);
        self::assertTrue($reconciled['rows']->contains(fn ($row) => $row->id === $conflict->id));
        self::assertTrue($reconciled['rows']->contains(fn ($row) => str_starts_with($row->source_id, 'pdf-cell:')
            && ($row->data['value'] ?? null) === '72,421'));
        self::assertCount(4, $analysis['visualAnalysis']['charts']);
        self::assertSame([], array_diff(array_column($charts, 'logicalChartId'),
            array_column($analysis['visualAnalysis']['charts'], 'logicalChartId')));
        self::assertFalse(collect($analysis['visualAnalysis']['charts'])
            ->contains(fn ($chart) => str_contains($chart['title'], 'Total assets')));
        $sourceId = $charts[0]['data'][0]['sourceIds'][0];
        $navigationResponse = $this->getJson("/api/documents/{$document->id}/sources/".rawurlencode($sourceId));
        self::assertSame(200, $navigationResponse->status(), $navigationResponse->getContent().' '.json_encode(
            DocumentEvidence::where('document_id', $document->id)->where('source_id', $sourceId)
                ->get(['id', 'sources'])->toArray(), JSON_THROW_ON_ERROR));
        $navigation = $navigationResponse->json('data');
        self::assertSame('CURRENT', $navigation['state']);
        self::assertSame($charts[0]['data'][0]['source']['page'], $navigation['roles']['value'][0]['page']);
        self::assertSame($charts[0]['data'][0]['sourceValue'], $navigation['source']['text']);
        self::assertSame(['start' => $charts[0]['data'][0]['source']['start_offset'],
            'end' => $charts[0]['data'][0]['source']['end_offset']], $navigation['source']['range']);
        $capture = getenv('DOCINTEL_PHASE7_RESPONSE_PATH');
        if (is_string($capture) && str_starts_with($capture, '/tmp/')) {
            file_put_contents($capture, json_encode(['document' => $documentResponse->json(),
                'intelligence' => $intelligenceResponse->json(), 'navigation' => $navigationResponse->json()],
                JSON_THROW_ON_ERROR));
        }
        $metricsPath = getenv('DOCINTEL_PHASE7_METRICS_PATH');
        if (is_string($metricsPath) && str_starts_with($metricsPath, '/tmp/')) {
            $setId = DB::table('source_provenance_sets')->where('document_id', $document->id)
                ->where('published', true)->value('id');
            $rows = [];
            $payload = [];
            foreach (['structured_extractions', 'document_evidence', 'source_anchors',
                'source_relationships', 'evidence_role_bindings', 'document_charts'] as $table) {
                $graph = in_array($table, ['source_anchors', 'source_relationships',
                    'evidence_role_bindings'], true);
                $query = DB::table($table.' as item')->where('item.'.($graph ? 'set_id' : 'document_id'),
                    $graph ? $setId : $document->id);
                $rows[$table] = (clone $query)->count();
                $payload[$table] = (int) $query->selectRaw('COALESCE(SUM(pg_column_size(item)), 0) AS bytes')
                    ->value('bytes');
            }
            $rows['document_chart_points'] = DB::table('document_chart_points as point')
                ->join('document_charts as chart', 'chart.id', '=', 'point.document_chart_id')
                ->where('chart.document_id', $document->id)->count();
            file_put_contents($metricsPath, json_encode([
                'stages_ms' => $this->stageMs, 'rows' => $rows, 'payload_bytes' => $payload,
                'pdf_file_bytes' => filesize(base_path('tests/Fixtures/apple_fy25_q2_original.pdf')),
                'http_document_bytes' => strlen($documentResponse->getContent()),
                'http_intelligence_bytes' => strlen($intelligenceResponse->getContent()),
                'raw_findings' => $analysis['stats']['acceptedFindings'],
                'presented_findings' => $analysis['stats']['presentedFindings'],
                'reconciled_equivalents' => $analysis['stats']['reconciledEquivalentFindings'],
                'persisted_charts' => count($charts),
                'generated_charts' => count($analysis['visualAnalysis']['charts']),
                'php_peak_bytes' => memory_get_peak_usage(true),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);
        $this->actingAs($outsider->fresh(), 'sanctum')
            ->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertForbidden();
        config()->set('document_intelligence.pdf_table_structure', false);
        $this->actingAs($document->uploader, 'sanctum');
        $disabled = $this->getJson("/api/documents/{$document->id}")->assertOk()->json('data.charts');
        self::assertFalse($disabled[0]['renderEligible']);
        self::assertSame([], $disabled[0]['data']);
        config()->set('document_intelligence.pdf_table_structure', true);
        $document->forceFill(['file_hash' => hash('sha256', 'changed Apple bytes')])->save();
        self::assertFalse($this->getJson("/api/documents/{$document->id}")
            ->assertOk()->json('data.charts.0.renderEligible'));
        self::assertSame([], $this->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.analysis.visualAnalysis.charts'));
        $sourceEvidence = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', $sourceId)->firstOrFail();
        self::assertFalse(app(MeasurementIntegrity::class)->forEvidence($sourceEvidence)['analyticalEligible']);
        $this->getJson("/api/documents/{$document->id}/sources/".rawurlencode($sourceId))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');
        $document->forceFill(['file_hash' => self::HASH])->save();
        $document->forceFill(['extracted_text' => $document->extracted_text."\nChanged source revision."])->save();
        $stale = $this->getJson("/api/documents/{$document->id}")->assertOk()->json('data.charts');
        self::assertFalse($stale[0]['renderEligible']);
        self::assertSame([], $stale[0]['data']);
        $staleAnalysis = $this->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.analysis');
        self::assertSame([], $staleAnalysis['visualAnalysis']['charts']);
        $this->getJson("/api/documents/{$document->id}/sources/".rawurlencode($sourceId))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');
    }

    public function test_interrupted_merge_retries_without_publishing_candidates_or_duplicating_graphs(): void
    {
        [$user, $document] = $this->upload();
        $otherUser = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($otherUser);
        $other = Document::create(['workspace_id' => $otherUser->fresh()->current_workspace_id,
            'uploaded_by' => $otherUser->id, 'name' => 'Unrelated.pdf', 'type' => 'PDF',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026]);
        $merge = $this->runUntilMerge($document);
        $realCollector = app(PdfSourceCandidateCollector::class);
        app()->instance(PdfSourceCandidateCollector::class, new class($realCollector)
        {
            public bool $interrupt = true;

            public function __construct(private PdfSourceCandidateCollector $real) {}

            public function collect(Document $document): array
            {
                $result = $this->real->collect($document);
                if ($this->interrupt) {
                    $this->interrupt = false;
                    throw new \RuntimeException('Controlled stop after source collection');
                }

                return $result;
            }
        });
        try {
            (new MergeDocumentEvidenceJob($merge->id, $merge->dispatch_token))
                ->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
            self::fail('The controlled interruption did not fire.');
        } catch (\RuntimeException $error) {
            self::assertSame('Controlled stop after source collection', $error->getMessage());
        }
        self::assertSame('failed', $merge->fresh()->status);
        self::assertSame(132, DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'like', 'pdf-cell:%')->count());
        self::assertSame(0, DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'like', 'pdf-cell:%')->where('data->measurement_verification', 'verified')->count());
        self::assertSame(0, DB::table('document_charts')->where('document_id', $document->id)->count());
        self::assertSame(0, DB::table('source_provenance_sets')->where('document_id', $document->id)->count());
        self::assertSame([], $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}")->assertOk()->json('data.charts'));
        $partial = $this->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.analysis');
        self::assertSame([], $partial['visualAnalysis']['charts']);
        self::assertSame(0, $partial['stats']['chartableFindings']);
        self::assertFalse(collect($partial['analysisGroups'])->flatMap(fn ($group) => $group['items'])
            ->contains(fn ($item) => $item['measurementIntegrity']['analyticalEligible'] ?? false));

        Bus::swap(Bus::getFacadeRoot()->dispatcher);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'pgsql']);
        MergeDocumentEvidenceJob::dispatch($merge->id, $merge->dispatch_token)
            ->onQueue(QueueTopology::SYNTHESIS);
        self::assertSame(1, DB::table('jobs')->where('queue', QueueTopology::SYNTHESIS)->count());
        app('queue.worker')->runNextJob('database', QueueTopology::SYNTHESIS,
            new WorkerOptions('docintel-phase7-retry', 0, 512, 180, 0, 2, true));
        self::assertSame('completed', $merge->fresh()->status);
        $counts = [
            DB::table('document_evidence')->where('document_id', $document->id)->count(),
            DB::table('source_provenance_sets')->where('document_id', $document->id)->count(),
            DB::table('source_anchors')->where('document_id', $document->id)->count(),
            DB::table('source_relationships')->where('document_id', $document->id)->count(),
            DB::table('document_charts')->where('document_id', $document->id)->count(),
            DB::table('document_chart_points')->where('workspace_id', $document->workspace_id)->count(),
        ];
        self::assertSame(4, $counts[4]);
        self::assertSame(8, $counts[5]);
        (new MergeDocumentEvidenceJob($merge->id, $merge->dispatch_token))
            ->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame($counts, [
            DB::table('document_evidence')->where('document_id', $document->id)->count(),
            DB::table('source_provenance_sets')->where('document_id', $document->id)->count(),
            DB::table('source_anchors')->where('document_id', $document->id)->count(),
            DB::table('source_relationships')->where('document_id', $document->id)->count(),
            DB::table('document_charts')->where('document_id', $document->id)->count(),
            DB::table('document_chart_points')->where('workspace_id', $document->workspace_id)->count(),
        ]);
        self::assertSame('Ready', $other->fresh()->status);
        self::assertSame(0, DB::table('document_evidence')->where('document_id', $other->id)->count());
    }

    public function test_database_queue_worker_processes_uploaded_apple_through_public_chart_api(): void
    {
        // Exercise the serialized queue transport in-process so provider HTTP remains fake.
        Bus::swap(Bus::getFacadeRoot()->dispatcher);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'pgsql']);
        [, $document] = $this->upload();
        self::assertGreaterThan(0, DB::table('jobs')->count());
        $worker = app('queue.worker');
        $options = new WorkerOptions('docintel-phase7', 0, 512, 180, 0, 2, true);
        for ($step = 0; $step < 160; $step++) {
            if ($document->fresh()->status === 'Ready'
                && DB::table('document_charts')->where('document_id', $document->id)->count() === 4) {
                break;
            }
            $worker->runNextJob('database', 'default,extraction,synthesis', $options);
            if (DB::table('jobs')->count() === 0 && $document->fresh()->status !== 'Ready') {
                break;
            }
        }
        self::assertSame('Ready', $document->fresh()->status,
            json_encode(DB::table('failed_jobs')->get(['exception'])->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('failed_jobs')->count());
        $charts = $this->getJson("/api/documents/{$document->id}")->assertOk()->json('data.charts');
        self::assertCount(4, $charts);
        self::assertTrue($charts[0]['renderEligible']);
        self::assertSame('verified_pdf_table', $charts[0]['sourceOrigin']);
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'pdf-cell:'));
    }
}
