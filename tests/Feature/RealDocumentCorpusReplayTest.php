<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\KpiResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV10;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Opt-in replay of a private, independently reviewed corpus case. No network requests. */
class RealDocumentCorpusReplayTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('DOCINTEL_REAL_CORPUS_RUN') !== '1') {
            self::markTestSkipped('Real corpus replay requires explicit local opt-in.');
        }
        if (getenv('APP_DEMO_MODE') !== '0' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55449'
            || getenv('DB_DATABASE') !== 'docintel_v9_eval' || getenv('DB_URL') !== '') {
            throw new RuntimeException('real_corpus_requires_explicit_disposable_local_database');
        }
        $preflight = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=docintel_v9_eval',
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
        if ($preflight->query("select current_setting('data_directory')")->fetchColumn()
            !== base_path('storage/framework/testing/docintel-v9-pg')) {
            throw new RuntimeException('real_corpus_database_cluster_mismatch');
        }
        parent::setUp();
        if (config('database.default') !== 'pgsql' || User::count() !== 0 || Document::count() !== 0) {
            throw new RuntimeException('real_corpus_database_not_empty_or_not_isolated');
        }
    }

    public function test_registered_case_replays_without_paid_inference(): void
    {
        $id = (string) getenv('DOCINTEL_CORPUS_CASE_ID');
        if (! preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $id)) {
            throw new RuntimeException('Invalid corpus case ID');
        }
        $directory = base_path('storage/framework/testing/docintel-evaluation-corpus/'.$id);
        $manifest = $this->readJson($this->caseFile($directory, 'manifest.json'));
        $snapshot = $this->readJson($this->caseFile($directory, 'snapshot.json'));
        $truth = $this->readJson($this->caseFile($directory, 'truth.json'));
        if ($manifest['id'] !== $id || $manifest['review_status'] !== 'human_verified'
            || $truth['review_status'] !== 'human_verified'
            || ! in_array($manifest['category'], ['real_recorded_model', 'real_human_observations'], true)) {
            throw new RuntimeException('Unreviewed or misclassified corpus case');
        }
        if (hash_file('sha256', $this->caseFile($directory, 'snapshot.json')) !== $manifest['snapshot_sha256']
            || hash_file('sha256', $this->caseFile($directory, 'truth.json')) !== $manifest['truth_sha256']
            || empty($manifest['authorization_reference']) || empty($manifest['provenance'])) {
            throw new RuntimeException('Private corpus provenance or truth hash mismatch');
        }
        $path = $this->caseFile($directory, $manifest['source_file']);
        if (hash_file('sha256', $path) !== $manifest['source_sha256']) {
            throw new RuntimeException('Original source hash mismatch');
        }
        $structured = match ($manifest['format']) {
            'PDF' => app(DocumentTextExtractor::class)->extractPdfStructured($path),
            'DOCX' => app(DocumentTextExtractor::class)->extractDocxStructured($path),
            'XLSX' => app(SpreadsheetTextExtractor::class)->extractStructured($path),
            default => throw new RuntimeException('Unsupported corpus source format'),
        };
        if (hash('sha256', $structured->text) !== $manifest['extraction_sha256']
            || $structured->text !== $snapshot['text']
            || $structured->parserVersion !== $manifest['parser_version']) {
            throw new RuntimeException('Extraction changed since human review');
        }
        $enabled = getenv('DOCINTEL_CORPUS_FEATURE') === 'on';
        if (! $enabled && getenv('DOCINTEL_CORPUS_FEATURE') !== 'off') {
            throw new RuntimeException('Feature state must be off or on');
        }
        config(['cache.default' => 'array', 'document_intelligence.server_evidence_resolution' => $enabled,
            'kpi_identity.semantic_matching' => false, 'intelligence_v2.enabled' => false,
            'document_intelligence.revision_v1' => false]);
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => $manifest['original_filename'],
            'type' => $manifest['format'], 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'pages' => $manifest['format'] === 'PDF'
                ? substr_count($structured->text, "\f") + 1 : 1,
            'file_hash' => $manifest['source_sha256'], 'extracted_text' => $structured->text]);
        if (in_array($manifest['format'], ['DOCX', 'XLSX'], true)) {
            app(StructuredExtractionStore::class)->publish($document, $structured);
        }
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:real-corpus-'.$id,
            'normal_span_grounding' => app(NormalRouteProvenance::class)->eligible($document, $structured->text),
            'text_hash' => hash('sha256', $structured->text), 'model' => 'claude-haiku-5-5',
            'budget_usd' => 0.01, 'tokens' => strlen($structured->text),
            'count_method' => 'byte_upper_bound']])->save();
        if (! $document->ai_pipeline['normal_span_grounding']) {
            throw new RuntimeException('Document exceeds normal-route span bounds; adapter cannot replay it');
        }
        $spans = app(EvidenceGrounding::class)->spans($document);
        if ($spans->count() !== $manifest['span_count']
            || app(EvidenceGrounding::class)->version($document) !== $manifest['source_span_revision']) {
            throw new RuntimeException('Persisted source spans differ from reviewed snapshot');
        }
        $started = hrtime(true);
        $candidateIds = [];
        $creationOrder = [];
        Event::listen('eloquent.created: '.DocumentKpi::class, function (DocumentKpi $kpi) use (&$creationOrder, $document): void {
            if ($kpi->document_id === $document->id) {
                $creationOrder[] = (string) $kpi->id;
            }
        });
        $modelCount = 0;
        if ($manifest['category'] === 'real_recorded_model') {
            if (($manifest['recorded_prompt_version'] ?? null) !== 10) {
                throw new RuntimeException('Recorded replay requires a V10 response');
            }
            $responsePath = $this->caseFile($directory, $manifest['recorded_response_file']);
            if (hash_file('sha256', $responsePath) !== $manifest['recorded_response_sha256']) {
                throw new RuntimeException('Recorded provider response hash mismatch');
            }
            $envelope = $this->readJson($responsePath);
            config(['services.anthropic.api_key' => 'SYNTHETIC_PROVIDER_SECRET',
                'services.anthropic.extraction_model' => 'claude-haiku-5-5']);
            $this->seed(DocumentInsightsPromptSeederV10::class);
            AiPrompt::activate('document_insights', 10);
            Http::fake(['api.anthropic.com/*' => Http::response($envelope)]);
            $calls = 0;
            Http::assertSentCount(0);
            $result = app(AnthropicClient::class)->extractDocumentInsights(
                $structured->text, $document->name, $document, singleResponse: true);
            Http::assertSentCount(1);
            $calls++;
            $modelCount = count($result['kpis'] ?? []);
            $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
                ->once()->andReturn($result));
            (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class),
                app(PipelineStageRecorder::class));
            Http::assertSentCount($calls);
        } else {
            $candidatePath = $this->caseFile($directory, $manifest['candidate_file']);
            if (hash_file('sha256', $candidatePath) !== $manifest['candidate_sha256']) {
                throw new RuntimeException('Human candidate hash mismatch');
            }
            $input = $this->readJson($candidatePath);
            if (($input['origin'] ?? null) !== 'human_prepared_observations') {
                throw new RuntimeException('Backend-only input must be labeled human prepared');
            }
            foreach ($input['claims'] ?? [] as $claim) {
                $kpi = DocumentKpi::create(['document_id' => $document->id,
                    'workspace_id' => $document->workspace_id, 'label' => $claim['label'],
                    'value' => $claim['value'], 'unit' => $claim['unit'], 'period' => $claim['period'],
                    'identity_metadata' => ['concept' => $claim['concept'], 'scope' => $claim['scope'],
                        'metric_type' => $claim['metric_type'], 'value_basis' => $claim['basis'],
                        'period' => $claim['period']]]);
                $rejection = app(NormalKpiGrounder::class)->record($document, $kpi,
                    ['source_context' => $claim['source_context']]);
                if ($rejection !== null) {
                    $kpi->forceFill(['identity_metadata' => [...$kpi->identity_metadata,
                        'grounding_rejection' => $rejection]])->save();
                }
                $candidateIds[(string) $kpi->id] = $claim['id'];
            }
            $document->forceFill(['status' => 'Ready'])->save();
        }
        $document = $document->fresh();
        $collected = app(MetricCollector::class)->collect($document);
        $analysis = app(DocumentAnalysisComposer::class)->compose($document);
        $request = Request::create('/api/documents/'.$document->id.'/intelligence');
        $api = (new DocumentIntelligenceResource($document))->resolve($request);
        foreach ((array) ($api['evidence'] ?? []) as $mapping) {
            self::assertNotEmpty($mapping['quote']);
            foreach ($mapping['sources'] ?? [] as $source) {
                self::assertSame($source['quote'], mb_substr($structured->text,
                    $source['start_offset'], $source['end_offset'] - $source['start_offset']));
            }
        }
        $observationMap = collect($collected['observations'])->keyBy('sourceId');
        $rows = [];
        $saved = DocumentKpi::where('document_id', $document->id)->get()->keyBy(fn (DocumentKpi $kpi) => (string) $kpi->id);
        self::assertCount(count($creationOrder), $saved);
        foreach ($creationOrder as $index => $kpiId) {
            $kpi = $saved[$kpiId];
            $sourceId = 'kpi:'.$kpi->id;
            $evidence = DocumentEvidence::where('document_id', $document->id)
                ->where('source_id', $sourceId)->where('kind', 'metric')->first();
            if ($evidence !== null) {
                self::assertSame(hash('sha256', $structured->text), $evidence->data['source_revision']);
                foreach ($evidence->sources ?? [] as $source) {
                    self::assertSame($source['quote'], mb_substr($structured->text,
                        $source['start_offset'], $source['end_offset'] - $source['start_offset']));
                }
            }
            $request->attributes->set('kpi_evidence', collect([$sourceId => $evidence]));
            $request->attributes->set('document_source_revision', hash('sha256', $structured->text));
            $card = (new KpiResource($kpi))->resolve($request);
            $rows[] = ['candidate_index' => $index, 'human_candidate_id' => $candidateIds[(string) $kpi->id] ?? null,
                'origin' => $manifest['category'] === 'real_recorded_model'
                    ? ($index < $modelCount ? 'recorded_model' : 'server_expanded') : 'human_prepared',
                'source_id' => $sourceId, 'label' => $kpi->label, 'value' => $kpi->value,
                'unit' => $kpi->unit, 'period' => $kpi->period,
                'identity' => $kpi->identity_metadata,
                'source_linked' => $evidence !== null,
                'exact_cell_proven' => is_int($evidence?->data['source_cell_start_offset'] ?? null),
                'analytical_eligible' => $card['analyticalEligible'],
                'rejection' => $kpi->identity_metadata['grounding_rejection'] ?? null,
                'limitation_reasons' => $card['limitationReasons'],
                'integrity' => $observationMap[$sourceId]->integrity ?? null];
        }
        $output = (string) getenv('DOCINTEL_CORPUS_RESULT');
        if (realpath(dirname($output)) !== base_path('storage/framework/testing/docintel-evidence-first-gate')
            || basename($output) !== basename(str_replace('\\', '/', $output))
            || ! preg_match('/^[a-z0-9-]+-(?:off|on)-[0-9]+\.json$/D', basename($output))) {
            throw new RuntimeException('Unsafe corpus result path');
        }
        $report = ['case_id' => $id, 'category' => $manifest['category'], 'feature_enabled' => $enabled,
            'source_sha256' => $manifest['source_sha256'], 'extraction_sha256' => $manifest['extraction_sha256'],
            'source_span_revision' => $manifest['source_span_revision'],
            'model_observations' => $modelCount,
            'human_prepared_observations' => count($candidateIds),
            'server_expanded_observations' => max(0, count($rows) - $modelCount - count($candidateIds)),
            'candidates' => $rows, 'chart_count' => $analysis['stats']['chartCandidates'],
            'comparison_count' => $analysis['stats']['analyticalComparisons'],
            'api_evidence_count' => count((array) ($api['evidence'] ?? [])),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1000000), 'paid_inference_calls' => 0];
        file_put_contents($output, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($output, 0600);
        self::assertFileExists($output);
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Missing private corpus file');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function caseFile(string $directory, mixed $name): string
    {
        if (! is_string($name) || basename($name) !== $name || $name === ''
            || ! is_file($directory.'/'.$name)
            || ! str_starts_with((string) realpath($directory.'/'.$name), realpath($directory).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe private corpus file');
        }

        return $directory.'/'.$name;
    }
}
