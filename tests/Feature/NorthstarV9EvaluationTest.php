<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\KpiResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV9;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDO;
use RuntimeException;
use Tests\Support\NorthstarGoldScorer;
use Tests\Support\NorthstarV9Diagnostics;
use Tests\Support\NorthstarV9RequestGuard;
use Tests\TestCase;

/** One PDF, one insights job, at most two provider HTTP requests. */
class NorthstarV9EvaluationTest extends TestCase
{
    use DatabaseTransactions;

    private NorthstarV9Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (getenv('DOCINTEL_V9_EVAL_RUN') !== '1') {
            self::markTestSkipped('V9 evaluation requires DOCINTEL_V9_EVAL_RUN=1 and its isolated local database.');
        }
        // Check before the test opens a transaction on the isolated local database.
        if (getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_PORT') !== '55449'
            || getenv('DB_DATABASE') !== 'docintel_v9_eval'
            || getenv('DB_URL') !== ''
            || getenv('APP_DEMO_MODE') !== '0') {
            throw new RuntimeException('northstar_eval_requires_explicit_disposable_local_database');
        }
        $preflight = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=docintel_v9_eval',
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
        if ($preflight->query("select current_setting('data_directory')")->fetchColumn()
            !== dirname(__DIR__, 2).'/storage/framework/testing/docintel-v9-pg') {
            throw new RuntimeException('northstar_eval_database_cluster_mismatch');
        }
        parent::setUp();
        $db = config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql'
            || $db['host'] !== '127.0.0.1'
            || $db['database'] !== 'docintel_v9_eval'
            || ! empty($db['url'])) {
            throw new RuntimeException('northstar_eval_database_config_mismatch');
        }
    }

    public function test_one_northstar_v9_pipeline_with_a_recorded_response_or_explicitly_authorized_live_call(): void
    {
        $this->evaluate(getenv('DOCINTEL_V9_EVAL_LIVE') === '1');
    }

    public function test_recorded_truncation_preserves_both_attempts_and_the_completed_pipeline(): void
    {
        $this->evaluate(false, 'truncated');
        $report = $this->diagnosticReport();
        self::assertSame('success', $report['outcome']);
        self::assertSame(['max_tokens', 'end_turn'], array_column($report['provider_attempts'], 'stop_reason'));
        self::assertSame(['truncated', null], array_column($report['provider_attempts'], 'error_category'));
        self::assertSame(['truncated', 'success'], array_column($report['provider_attempts'], 'validation_status'));
        self::assertSame([6400, 8192], array_column($report['request_bounds'], 'max_output_tokens'));
        self::assertSame([6400, 200], array_column(array_column($report['provider_attempts'], 'usage'), 'output_tokens'));
        self::assertTrue($report['cost_complete']);
        self::assertNull($report['api_serialization_error']);
        self::assertLessThanOrEqual(0.02, $report['maximum_reserved_usd']);
    }

    public function test_recorded_malformed_output_records_the_real_parser_verdict_for_each_attempt(): void
    {
        $this->evaluate(false, 'malformed');
        $report = $this->diagnosticReport();
        self::assertSame(['end_turn', 'end_turn'], array_column($report['provider_attempts'], 'stop_reason'));
        self::assertSame(['malformed_output', 'success'], array_column($report['provider_attempts'], 'validation_status'));
        self::assertSame(['malformed_output', null], array_column($report['provider_attempts'], 'error_category'));
        self::assertSame([6400, 6400], array_column($report['request_bounds'], 'max_output_tokens'));
        self::assertSame(2, $report['requests']);
    }

    public function test_recorded_provider_error_persists_diagnostics_before_rollback(): void
    {
        try {
            $this->evaluate(false, 'provider_error');
            self::fail('Provider failure must propagate.');
        } catch (AiProcessingException $error) {
            self::assertSame('authentication', $error->classification);
        }
        $report = $this->diagnosticReport();
        self::assertSame('failed', $report['outcome']);
        self::assertSame('authentication', $report['error_category']);
        self::assertSame(1, $report['requests']);
        self::assertSame(401, $report['provider_attempts'][0]['http_status']);
        self::assertSame('authentication', $report['provider_attempts'][0]['error_category']);
        self::assertNull($report['provider_attempts'][0]['stop_reason']);
        self::assertNull($report['provider_attempts'][0]['usage']);
        self::assertFalse($report['cost_complete']);
        self::assertSame(0, DocumentKpi::count());
        self::assertSame(0, DocumentEvidence::count());
        self::assertStringNotContainsString('SYNTHETIC_PROVIDER_SECRET', file_get_contents($this->diagnostics->path()));
    }

    public function test_recorded_transport_retries_cannot_exceed_two_and_preserve_exception_diagnostics(): void
    {
        try {
            $this->evaluate(false, 'connection_error');
            self::fail('Transport failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('northstar_eval_budget_or_request_limit', $error->getMessage());
        }
        $report = $this->diagnosticReport();
        self::assertSame('failed', $report['outcome']);
        self::assertSame('budget_or_request_limit', $report['error_category']);
        self::assertSame(2, $report['requests']);
        self::assertCount(2, $report['provider_attempts']);
        self::assertSame(['connection_error', 'connection_error'], array_column($report['provider_attempts'], 'error_category'));
        self::assertSame([null, null], array_column($report['provider_attempts'], 'usage'));
        self::assertFalse($report['cost_complete']);
        self::assertLessThanOrEqual(0.02, $report['maximum_reserved_usd']);
        self::assertStringNotContainsString('SYNTHETIC_TRANSPORT_SECRET', file_get_contents($this->diagnostics->path()));
    }

    private function diagnosticReport(): array
    {
        return json_decode(file_get_contents($this->diagnostics->path()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function evaluate(bool $live, string $recordedScenario = 'success'): void
    {
        Queue::fake();
        config(['services.anthropic.extraction_model' => 'claude-haiku-5-5',
            'document_intelligence.structured_output_tokens.insights' => 6400,
            'services.anthropic.structured_max_tokens_ceiling' => 8192,
            'kpi_identity.semantic_matching' => false,
            'document_intelligence.revision_v1' => false]);
        $budget = (float) (getenv('DOCINTEL_V9_EVAL_BUDGET_USD') ?: 0);
        $evaluationKey = $live ? $this->dedicatedEvaluationKey() : null;
        if ($live && ($budget <= 0 || $budget > 0.02
            || $evaluationKey === null || strlen($evaluationKey) < 10)) {
            throw new RuntimeException('northstar_eval_live_requires_key_and_approved_budget');
        }
        if ($live) {
            config(['services.anthropic.api_key' => $evaluationKey]);
        }
        $this->seed(DocumentInsightsPromptSeederV9::class);
        $prompt = AiPrompt::activate('document_insights', 9); // This database only.
        self::assertSame(9, AiPrompt::active('document_insights')->version);
        $path = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        $goldPath = base_path('tests/Fixtures/intelligence-v2/northstar-source-gold.json');
        $sourceHash = hash_file('sha256', $path);
        self::assertSame('4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f', $sourceHash);
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        self::assertSame('a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da', hash('sha256', $text));
        self::assertLessThanOrEqual((int) config('document_intelligence.large_tokens'), strlen($text));
        self::assertLessThanOrEqual((int) config('document_processing.max_extraction_chars'), mb_strlen($text));

        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026,
            'pages' => 7, 'file_hash' => $sourceHash, 'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:northstar-v9-eval',
            'normal_span_grounding' => app(NormalRouteProvenance::class)->eligible($document, $text),
            'text_hash' => hash('sha256', $text), 'model' => 'claude-haiku-5-5',
            'tokens' => strlen($text), 'count_method' => 'byte_upper_bound']])->save();
        self::assertTrue($document->ai_pipeline['normal_span_grounding']);
        $spans = app(EvidenceGrounding::class)->spans($document);
        self::assertSame(126, $spans->count());
        $guard = new NorthstarV9RequestGuard($live ? $budget : 0.02);
        $this->diagnostics = new NorthstarV9Diagnostics($guard, [
            'mode' => $live ? 'live' : 'recorded', 'model' => 'claude-haiku-5-5',
            'prompt_version' => 9, 'prompt_sha256' => hash('sha256', $prompt->template),
            'source_sha256' => $sourceHash, 'extracted_text_sha256' => hash('sha256', $text),
            'source_revision' => app(EvidenceGrounding::class)->version($document),
            'span_count' => $spans->count(),
        ]);
        $this->diagnostics->capture(function () use ($live, $recordedScenario, $guard, $prompt, $sourceHash, $text, $document, $spans, $goldPath) {
            Event::listen('eloquent.created: '.DocumentAiRun::class, function (DocumentAiRun $run) use ($document) {
                if ($run->document_id === $document->id && $run->purpose === 'insights') {
                    $this->diagnostics->recordValidationOutcome($run->status);
                }
            });
            $requestHashes = [];
            Http::globalRequestMiddleware(function ($request) use (&$requestHashes) {
                self::assertStringNotContainsString('page:3:financial:revenue:q3', (string) $request->getBody());
                self::assertStringNotContainsString('northstar-q3-2026', (string) $request->getBody());
                $requestHashes[] = hash('sha256', (string) $request->getBody());

                return $request;
            });
            Http::globalMiddleware($this->diagnostics->middleware());
            if ($live) {
                Http::preventStrayRequests(false);
            } else {
                $fake = ['kpis' => [
                    $this->candidate('Revenue', '51.0', 'KES million', 'Q3 2026', 'quarterly revenue', 'E029', 'E027', 'E028', 'E025'),
                    $this->candidate('Revenue', '999.0', 'KES million', 'Q3 2026', 'quarterly revenue', 'E029', 'E027', 'E028', 'E025'),
                ], 'charts' => [], 'insights' => []];
                $response = [
                    'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 7000, 'output_tokens' => 200],
                    'content' => [['type' => 'text', 'text' => json_encode($fake, JSON_THROW_ON_ERROR)]],
                ];
                $sequence = Http::sequence();
                if (in_array($recordedScenario, ['truncated', 'malformed'], true)) {
                    $sequence->push(array_replace($response, ['stop_reason' => $recordedScenario === 'truncated' ? 'max_tokens' : 'end_turn',
                        'usage' => ['input_tokens' => 7000, 'output_tokens' => $recordedScenario === 'truncated' ? 6400 : 200],
                        'content' => [['type' => 'text', 'text' => '{"kpis":']]]));
                }
                $sequence->push($response);
                Http::fake(['api.anthropic.com/*' => match ($recordedScenario) {
                    'provider_error' => Http::response(['error' => ['type' => 'authentication_error',
                        'message' => 'SYNTHETIC_PROVIDER_SECRET']], 401),
                    'connection_error' => Http::failedConnection('SYNTHETIC_TRANSPORT_SECRET'),
                    default => $sequence,
                }]);
            }

            $started = microtime(true);
            $result = app(AnthropicClient::class)->extractDocumentInsights($text, $document->name, $document);
            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            self::assertSame(9, $result['prompt_version']);
            self::assertGreaterThanOrEqual(1, $guard->requests);
            self::assertLessThanOrEqual(2, $guard->requests);
            // The application job owns identity resolution, grounding and persistence.
            $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
                ->once()->andReturn($result));
            (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
            $document = $document->fresh();
            self::assertSame('Ready', $document->status);
            $observations = collect(app(MetricCollector::class)->collect($document)['observations'])->keyBy('sourceId');
            $analysis = app(DocumentAnalysisComposer::class)->compose($document);
            $request = Request::create('/api/documents/'.$document->id.'/intelligence');
            $api = null;
            $apiError = null;
            try {
                $api = (new DocumentIntelligenceResource($document))->resolve($request);
            } catch (\Throwable $e) {
                // An API serialization failure is an evaluation result, not a
                // reason to lose the grounding and persistence diagnostics.
                $apiError = ['error_category' => NorthstarV9Diagnostics::errorCategory($e)];
            }
            $gold = json_decode(file_get_contents($goldPath), true, flags: JSON_THROW_ON_ERROR);
            $diagnostics = [];
            $saved = DocumentKpi::where('document_id', $document->id)->orderBy('created_at')->orderBy('id')->get();
            foreach ($saved as $index => $kpi) {
                $sourceId = 'kpi:'.$kpi->id;
                $evidence = DocumentEvidence::where('document_id', $document->id)->where('source_id', $sourceId)->first();
                $request->attributes->set('kpi_evidence', collect([$sourceId => $evidence]));
                $serialized = (new KpiResource($kpi))->resolve($request);
                $ids = $result['kpis'][$index]['source_context'] ?? [];
                $resolved = [];
                foreach ($ids as $role => $id) {
                    $span = is_string($id) ? $spans->resolve($id) : null;
                    $resolved[$role] = $span ? ['id' => $id, 'page' => $span['page'],
                        'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
                        'text' => $span['text']] : ['id' => $id, 'resolved' => false];
                }
                $evidenceData = $evidence?->data ?? [];
                $checks = ['citation_ids_resolved' => ! empty($ids['value_span_id'])
                    && count(array_filter($resolved,
                        fn ($span) => $span['id'] !== null && ($span['resolved'] ?? true) !== false))
                        === count(array_filter($ids)),
                    'verified_field_roles' => $evidenceData['source_fields_verified'] ?? [],
                    'source_revision_matches' => isset($evidenceData['source_revision'])
                        ? $evidenceData['source_revision'] === hash('sha256', $text) : null,
                    'source_cell_value' => $evidenceData['source_cell_value'] ?? null,
                    'source_cell_offsets' => isset($evidenceData['source_cell_start_offset'],
                        $evidenceData['source_cell_end_offset'])
                        ? [$evidenceData['source_cell_start_offset'], $evidenceData['source_cell_end_offset']] : null,
                    'grounding_rejection_code' => $kpi->identity_metadata['grounding_rejection'] ?? null,
                    'unchecked_roles_after_rejection' => $evidence === null];
                $diagnostics[] = ['candidate' => $result['kpis'][$index], 'source_id' => $sourceId,
                    'source_context' => $resolved, 'rejection' => $kpi->identity_metadata['grounding_rejection'] ?? null,
                    'verification_checks' => $checks,
                    'evidence' => $evidence ? ['kind' => $evidence->kind, 'data' => $evidence->data,
                        'sources' => $evidence->sources] : null,
                    'integrity' => $observations[$sourceId]->integrity ?? null,
                    'measurement' => $observations[$sourceId]->measurement ?? null,
                    'chart_titles' => array_values(array_map(fn ($chart) => $chart['title'],
                        array_filter($analysis['visualAnalysis']['charts'],
                            fn ($chart) => in_array($sourceId, $chart['sourceIds'], true)))),
                    'comparison_count' => count(array_filter($analysis['analyticalComparisons'],
                        fn ($comparison) => in_array($sourceId, $comparison['sourceIds'], true))),
                    'api_kpi' => $serialized];
            }
            $report = ['mode' => $live ? 'live' : 'recorded', 'model' => 'claude-haiku-5-5',
                'prompt_version' => 9, 'prompt_sha256' => hash('sha256', $prompt->template),
                'source_sha256' => $sourceHash, 'extracted_text_sha256' => hash('sha256', $text),
                'source_revision' => app(EvidenceGrounding::class)->version($document),
                'span_count' => $spans->count(), 'request_body_sha256' => $requestHashes,
                'requests' => $guard->requests, 'maximum_reserved_usd' => $guard->reservedUsd,
                'request_bounds' => $guard->attempts,
                'latency_ms' => $latencyMs, 'usage' => DocumentAiRun::where('document_id', $document->id)
                    ->where('purpose', 'insights')->get(['status', 'model', 'input_tokens', 'output_tokens',
                        'estimated_cost_usd', 'duration_ms', 'stop_reason'])->toArray(),
                'candidates' => $diagnostics, 'gold_case_count' => count($gold['cases'][0]['gold']),
                'source_gold_score' => (new NorthstarGoldScorer)->score($gold['cases'][0]['gold'], $diagnostics),
                'derived_analysis' => $analysis,
                'api_analysis' => $api['analysis'] ?? null, 'api_evidence' => $api['evidence'] ?? null,
                'api_serialization_error' => $apiError];
            $this->diagnostics->recordResult($report);
            self::assertCount(count($result['kpis']), $diagnostics);
            self::assertSame(29, $report['gold_case_count']);
            if (! $live) {
                self::assertSame(1, $report['source_gold_score']['exact_gold_matches']);
                self::assertSame([1], $report['source_gold_score']['unmatched_candidate_indexes_for_review']);
                self::assertTrue($diagnostics[0]['api_kpi']['analyticalEligible']);
                self::assertFalse($diagnostics[1]['api_kpi']['analyticalEligible']);
                self::assertSame('value_column_mismatch', $diagnostics[1]['rejection']);
                self::assertSame(0, $analysis['stats']['chartCandidates']);
                self::assertSame(0, $analysis['stats']['analyticalComparisons']);
            }
            self::assertNull($apiError, 'The actual composite API resource must serialize verified metric evidence.');
            self::assertNotNull($api['analysis']);
            if (! $live) {
                $acceptedId = 'kpi:'.$saved[0]->id;
                $rejectedId = 'kpi:'.$saved[1]->id;
                self::assertSame("Revenue\t42.0\t46.0\t51.0", $api['evidence']->$acceptedId['quote']);
                self::assertSame(['E029', 'E027', 'E028', 'E025'],
                    array_column($api['evidence']->$acceptedId['sources'], 'span_id'));
                self::assertSame(3, $api['sourcePages']->$acceptedId);
                self::assertFalse(property_exists($api['evidence'], $rejectedId));
                self::assertSame([], $api['analysis']['visualAnalysis']['charts']);
                self::assertSame([], $api['analysis']['analyticalComparisons']);

                // A referenced metric with missing or stale source text keeps its API shape,
                // but must not expose an excerpt or page that cannot be resolved.
                $acceptedEvidence = DocumentEvidence::where('document_id', $document->id)
                    ->where('source_id', $acceptedId)->firstOrFail();
                $originalSources = $acceptedEvidence->sources;
                $missingQuote = $originalSources;
                unset($missingQuote[0]['quote']);
                $acceptedEvidence->update(['sources' => $missingQuote]);
                $missing = (new DocumentIntelligenceResource($document->fresh()))->resolve($request);
                self::assertSame('', $missing['evidence']->$acceptedId['quote']);
                self::assertSame([], $missing['evidence']->$acceptedId['sources']);
                self::assertFalse(property_exists($missing['sourcePages'], $acceptedId));

                $staleData = $acceptedEvidence->data;
                $staleData['source_revision'] = str_repeat('0', 64);
                $acceptedEvidence->update(['sources' => $originalSources, 'data' => $staleData]);
                $stale = (new DocumentIntelligenceResource($document->fresh()))->resolve($request);
                self::assertSame('', $stale['evidence']->$acceptedId['quote']);
                self::assertSame([], $stale['evidence']->$acceptedId['sources']);
                self::assertFalse(property_exists($stale['sourcePages'], $acceptedId));

                DocumentEvidence::where('document_id', $document->id)->delete();
                DocumentKpi::where('document_id', $document->id)->delete();
                $empty = (new DocumentIntelligenceResource($document->fresh()))->resolve($request);
                self::assertSame(0, $empty['analysis']['stats']['chartableFindings']);
                self::assertSame([], $empty['analysis']['visualAnalysis']['charts']);
                self::assertSame([], $empty['analysis']['analyticalComparisons']);
                self::assertSame([], (array) $empty['evidence']);
            }
        });
        $persisted = $this->diagnosticReport();
        self::assertSame('success', $persisted['outcome']);
        self::assertCount($guard->requests, $persisted['provider_attempts']);
        foreach ($persisted['provider_attempts'] as $attempt) {
            self::assertIsInt($attempt['latency_ms']);
            self::assertGreaterThanOrEqual(0, $attempt['latency_ms']);
        }
    }

    private function dedicatedEvaluationKey(): ?string
    {
        $key = getenv('DOCINTEL_V9_EVAL_ANTHROPIC_KEY');
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }

        // PHPUnit boots with APP_ENV=testing, so Laravel loads .env.testing instead
        // of .env. Parse only for this dedicated value; never load the file's other
        // settings into the test process or alter the isolated database connection.
        $path = base_path('.env');
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        try {
            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException('northstar_eval_credential_file_unreadable');
            }
            $key = Dotenv::parse($contents)['DOCINTEL_V9_EVAL_ANTHROPIC_KEY'] ?? null;
        } catch (\Throwable) {
            throw new RuntimeException('northstar_eval_credential_file_invalid');
        }

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function candidate(string $label, string $value, string $unit, string $period,
        string $concept, string $valueId, string $unitId, string $periodId, string $subjectId): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit,
            'trend' => null, 'trendValue' => null,
            'identity' => ['concept' => $concept, 'scope' => 'Northstar Distribution Ltd',
                'metric_type' => 'amount', 'quantity_kind' => 'absolute',
                'aggregation' => 'total', 'value_basis' => 'actual', 'period' => $period],
            'source_context' => ['value_span_id' => $valueId, 'unit_span_id' => $unitId,
                'period_span_id' => $periodId, 'subject_span_id' => $subjectId]];
    }
}
