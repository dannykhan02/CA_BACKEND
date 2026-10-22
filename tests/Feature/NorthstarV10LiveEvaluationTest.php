<?php

namespace Tests\Feature;

use App\Exceptions\AnthropicStructuredOutputException;
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
use App\Services\AI\PromptManager;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV10;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDO;
use RuntimeException;
use Tests\Support\NorthstarGoldScorer;
use Tests\Support\NorthstarV10Diagnostics;
use Tests\Support\NorthstarV10RequestGuard;
use Tests\TestCase;

/** Explicit opt-in live test: one V10 provider response, then the normal backend pipeline. */
class NorthstarV10LiveEvaluationTest extends TestCase
{
    use DatabaseTransactions;

    private const PROMPT_SHA256 = '2be7ab2584d154df68437fb48b0dcc20e9ebecdf07e628c76793a01c8e1f88d0';

    private const RENDERED_PROMPT_SHA256 = '9bb9433df3f53439ec41428609b7bb2ae5dc1254a435737f7dbc50ede2d50717';

    private NorthstarV10Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (getenv('DOCINTEL_V10_EVAL_RUN') !== '1') {
            self::markTestSkipped('V10 evaluation requires DOCINTEL_V10_EVAL_RUN=1 and its isolated local database.');
        }
        $database = getenv('DOCINTEL_V10_FROZEN_REPLAY') === '1'
            ? 'docintel_v9_eval' : 'docintel_v10_eval';
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_PORT') !== '55449' || getenv('DB_DATABASE') !== $database
            || getenv('DB_URL') !== '' || getenv('APP_DEMO_MODE') !== '0') {
            throw new RuntimeException('northstar_v10_eval_requires_disposable_local_database');
        }
        $preflight = new PDO('pgsql:host=127.0.0.1;port=55449;dbname='.$database,
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
        if ($preflight->query("select current_setting('data_directory')")->fetchColumn()
            !== dirname(__DIR__, 2).'/storage/framework/testing/docintel-v9-pg') {
            throw new RuntimeException('northstar_v10_eval_database_cluster_mismatch');
        }
        parent::setUp();
        $db = config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql' || $db['host'] !== '127.0.0.1'
            || $db['database'] !== $database || ! empty($db['url'])
            || User::count() !== 0 || Document::count() !== 0) {
            throw new RuntimeException('northstar_v10_eval_database_not_empty_or_not_isolated');
        }
    }

    public function test_recorded_response_exercises_one_request_parser_job_grounding_and_gold_scorer(): void
    {
        $this->evaluate('complete');
        $report = $this->report();
        self::assertSame('success', $report['outcome']);
        self::assertSame(1, $report['requests']);
        self::assertSame(23644, $report['request_bounds'][0]['input_token_bound']);
        self::assertSame(0.009956, $report['maximum_reserved_usd']);
        self::assertSame(2, $report['model_observation_count']);
        self::assertSame(1, $report['independently_eligible_count']);
        self::assertSame(1, $report['source_gold_score']['exact_gold_matches']);
        self::assertSame(0, $report['strict_complete_financial_series_count']);
        self::assertSame(['value_column_mismatch' => 1], $report['rejection_reasons']);
        self::assertSame('success', $report['provider_attempts'][0]['validation_status']);
        self::assertSame(hash_file('sha256', $this->diagnostics->rawPath()), $report['provider_response_sha256']);
        self::assertSame(0600, fileperms($this->diagnostics->path()) & 0777);
        self::assertSame(0600, fileperms($this->diagnostics->rawPath()) & 0777);
        self::assertSame(0700, fileperms(dirname($this->diagnostics->rawPath())) & 0777);
    }

    public function test_frozen_live_response_replays_without_provider_access(): void
    {
        if (! is_file($this->frozenResponsePath())) {
            self::markTestSkipped('The restricted frozen V10 provider response is unavailable locally.');
        }
        $this->evaluate('frozen');
        $report = $this->report();
        self::assertSame('success', $report['outcome']);
        self::assertSame(1, $report['requests']);
        self::assertSame(20, $report['model_observation_count']);
        self::assertSame(0, $report['server_expanded_observation_count']);
        self::assertSame(0, $report['source_linked_observation_count']);
        self::assertSame(0, $report['structurally_proven_observation_count']);
        self::assertSame(0, $report['independently_eligible_count']);
        self::assertSame(0, $report['strict_complete_financial_series_count']);
        self::assertSame(0, $report['trusted_chart_count']);
        self::assertSame(0, $report['deterministic_comparison_count']);
    }

    public function test_frozen_live_response_replays_with_server_evidence_resolution(): void
    {
        if (! is_file($this->frozenResponsePath())) {
            self::markTestSkipped('The restricted frozen V10 provider response is unavailable locally.');
        }
        $this->evaluate('frozen_resolved');
        $report = $this->report();
        self::assertSame('success', $report['outcome']);
        self::assertSame(20, $report['model_observation_count']);
        self::assertSame(4, $report['server_expanded_observation_count']);
        self::assertSame(22, $report['source_linked_observation_count']);
        self::assertSame(22, $report['structurally_proven_observation_count']);
        // The API includes evidence referenced by the composed analysis. Its
        // exact selection can vary with summary ordering, but every mapping
        // it publishes must retain a usable source quote and span.
        self::assertGreaterThanOrEqual(20, $report['api_evidence_mapping_count']);
        self::assertLessThanOrEqual(22, $report['api_evidence_mapping_count']);
        foreach ($report['api_evidence'] as $mapping) {
            self::assertNotEmpty($mapping['quote']);
            self::assertNotEmpty($mapping['sources']);
        }
        self::assertSame(4, $report['strict_complete_financial_series_count']);
        self::assertSame(4, $report['trusted_chart_count']);
        self::assertSame(8, $report['deterministic_comparison_count']);
    }

    public function test_truncated_response_is_preserved_without_a_correction_request(): void
    {
        try {
            $this->evaluate('truncated');
            self::fail('Truncated output must fail.');
        } catch (AnthropicStructuredOutputException $error) {
            self::assertSame('truncated', $error->outputStatus);
        }
        $report = $this->report();
        self::assertSame(1, $report['requests']);
        self::assertSame('max_tokens', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame('truncated', $report['provider_attempts'][0]['validation_status']);
        self::assertFileExists($this->diagnostics->rawPath());
        self::assertSame(0, DocumentKpi::count());
    }

    public function test_invalid_v10_identity_contract_is_preserved_without_a_retry(): void
    {
        try {
            $this->evaluate('invalid_schema');
            self::fail('Incomplete citations must fail validation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Invalid V10 observation', $error->getMessage());
        }
        $report = $this->report();
        self::assertSame(1, $report['requests']);
        self::assertSame('invalid_schema', $report['error_category']);
        self::assertSame('invalid_schema', $report['provider_attempts'][0]['validation_status']);
        self::assertFileExists($this->diagnostics->rawPath());
        self::assertSame(0, DocumentKpi::count());
    }

    public function test_provider_failure_is_preserved_without_a_transport_retry(): void
    {
        try {
            $this->evaluate('provider_error');
            self::fail('Provider error must propagate.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Anthropic API request failed', $error->getMessage());
        }
        $report = $this->report();
        self::assertSame(1, $report['requests']);
        self::assertSame('failed', $report['outcome']);
        self::assertSame('overloaded', $report['error_category']);
        self::assertSame(529, $report['provider_attempts'][0]['http_status']);
        self::assertSame('overloaded', $report['provider_attempts'][0]['error_category']);
        self::assertFileExists($this->diagnostics->rawPath());
        self::assertSame(0, DocumentKpi::count());
    }

    public function test_live_gate_refuses_missing_authorization_before_provider_work(): void
    {
        $previous = getenv('DOCINTEL_V10_EVAL_APPROVED');
        putenv('DOCINTEL_V10_EVAL_APPROVED=0');
        try {
            $this->expectExceptionMessage('northstar_v10_eval_live_requires_explicit_approval_and_key');
            $this->evaluate('live');
        } finally {
            putenv($previous === false ? 'DOCINTEL_V10_EVAL_APPROVED'
                : 'DOCINTEL_V10_EVAL_APPROVED='.$previous);
        }
    }

    public function test_live_once_only_after_explicit_authorization(): void
    {
        if (getenv('DOCINTEL_V10_EVAL_LIVE') !== '1') {
            self::markTestSkipped('Paid V10 call requires separate explicit authorization.');
        }
        $this->evaluate('live');
        $report = $this->report();
        self::assertSame('success', $report['outcome']);
        self::assertSame(1, $report['requests']);
        self::assertSame('end_turn', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame('success', $report['provider_attempts'][0]['validation_status']);
    }

    private function report(): array
    {
        return json_decode(file_get_contents($this->diagnostics->path()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function evaluate(string $scenario): void
    {
        $live = $scenario === 'live';
        Queue::fake();
        config(['cache.default' => 'array',
            'services.anthropic.extraction_model' => 'claude-haiku-5-5',
            'document_intelligence.structured_output_tokens.insights_v10' => 14000,
            'document_intelligence.structured_output_tokens.insights_v10_ceiling' => 14000,
            'document_intelligence.revision_v1' => false,
            'document_intelligence.server_evidence_resolution' => $scenario === 'frozen_resolved',
            'kpi_identity.semantic_matching' => false,
            'intelligence_v2.enabled' => false]);
        $budget = $live ? (float) getenv('DOCINTEL_V10_EVAL_BUDGET_USD') : 0.01;
        $key = $live ? $this->dedicatedEvaluationKey() : 'SYNTHETIC_PROVIDER_SECRET';
        if ($live && (getenv('DOCINTEL_V10_EVAL_APPROVED') !== '1'
            || ! is_string($key) || strlen(trim($key)) < 10)) {
            throw new RuntimeException('northstar_v10_eval_live_requires_explicit_approval_and_key');
        }
        if (! is_finite($budget) || $budget <= 0 || $budget > 0.01) {
            throw new RuntimeException('northstar_v10_eval_invalid_budget');
        }
        config(['services.anthropic.api_key' => $key]);
        $this->seed(DocumentInsightsPromptSeederV10::class);
        $prompt = AiPrompt::activate('document_insights', 10); // Rolled back with this test transaction.
        self::assertSame(10, AiPrompt::active('document_insights')->version);
        self::assertSame(self::PROMPT_SHA256, hash('sha256', $prompt->template));
        self::assertSame('claude-haiku-5-5', app(AnthropicClient::class)->modelFor('insights'));
        self::assertStringContainsString('18. Independent observations:', $prompt->template);
        self::assertStringContainsString('19. Citation integrity:', $prompt->template);

        $path = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        $goldPath = base_path('tests/Fixtures/intelligence-v2/northstar-source-gold.json');
        $sourceHash = hash_file('sha256', $path);
        self::assertSame('4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f', $sourceHash);
        self::assertSame('82e7204118b729ea7a7ee89668f8c72cd4abce10b942a298de60a488325477c3',
            hash_file('sha256', $goldPath));
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        self::assertSame('a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da',
            hash('sha256', $text));
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026,
            'pages' => 7, 'file_hash' => $sourceHash, 'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:northstar-v10-eval',
            'normal_span_grounding' => app(NormalRouteProvenance::class)->eligible($document, $text),
            'text_hash' => hash('sha256', $text), 'model' => 'claude-haiku-5-5',
            'budget_usd' => $budget, 'tokens' => strlen($text), 'count_method' => 'byte_upper_bound']])->save();
        self::assertTrue($document->ai_pipeline['normal_span_grounding']);
        $spans = app(EvidenceGrounding::class)->spans($document);
        self::assertSame(126, $spans->count());
        $expectedPrompt = $this->expectedPrompt($prompt, $document, $text);
        self::assertSame(self::RENDERED_PROMPT_SHA256, hash('sha256', $expectedPrompt));
        $guard = new NorthstarV10RequestGuard($budget, hash('sha256', $expectedPrompt));
        $this->diagnostics = new NorthstarV10Diagnostics($guard, [
            'mode' => $live ? 'live' : 'recorded', 'model' => 'claude-haiku-5-5',
            'prompt_version' => 10, 'prompt_sha256' => hash('sha256', $prompt->template),
            'rendered_prompt_sha256' => hash('sha256', $expectedPrompt),
            'source_sha256' => $sourceHash, 'gold_sha256' => hash_file('sha256', $goldPath),
            'extracted_text_sha256' => hash('sha256', $text),
            'source_revision' => app(EvidenceGrounding::class)->version($document),
            'span_count' => $spans->count(),
        ]);
        $this->diagnostics->capture(function () use ($live, $scenario, $guard, $document, $text, $spans, $goldPath) {
            Event::listen('eloquent.created: '.DocumentAiRun::class, function (DocumentAiRun $run) use ($document) {
                if ($run->document_id === $document->id && $run->purpose === 'insights') {
                    $this->diagnostics->recordValidationOutcome($run->status);
                }
            });
            Http::globalMiddleware($this->diagnostics->middleware());
            if ($live) {
                Http::preventStrayRequests(false);
            } else {
                Http::fake(['api.anthropic.com/*' => $scenario === 'provider_error'
                    ? Http::response(['error' => ['type' => 'overloaded_error',
                        'message' => 'Synthetic provider failure']], 529)
                    : Http::response($this->recordedEnvelope($scenario))]);
            }
            $started = hrtime(true);
            $result = app(AnthropicClient::class)->extractDocumentInsights($text, $document->name,
                $document, singleResponse: true);
            $latencyMs = (int) ((hrtime(true) - $started) / 1000000);
            self::assertSame(1, $guard->requests);
            self::assertSame(10, $result['prompt_version']);
            // Reuse the exact parsed provider result. The normal job owns identity,
            // grounding, evidence persistence and downstream composition.
            $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
                ->once()->andReturn($result));
            (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class),
                app(PipelineStageRecorder::class));
            $document = $document->fresh();
            self::assertSame('Ready', $document->status);
            $collected = app(MetricCollector::class)->collect($document);
            $observations = collect($collected['observations'])->keyBy('sourceId');
            $analysis = app(DocumentAnalysisComposer::class)->compose($document);
            $request = Request::create('/api/documents/'.$document->id.'/intelligence');
            $api = (new DocumentIntelligenceResource($document))->resolve($request);
            $candidates = [];
            $rejections = [];
            $eligible = 0;
            $saved = DocumentKpi::where('document_id', $document->id)->orderBy('created_at')->orderBy('id')->get();
            foreach ($saved as $index => $kpi) {
                $sourceId = 'kpi:'.$kpi->id;
                $evidence = DocumentEvidence::where('document_id', $document->id)
                    ->where('source_id', $sourceId)->first();
                $request->attributes->set('kpi_evidence', collect([$sourceId => $evidence]));
                $request->attributes->set('document_source_revision', hash('sha256', $text));
                $card = (new KpiResource($kpi))->resolve($request);
                $eligible += (int) $card['analyticalEligible'];
                $rejection = $kpi->identity_metadata['grounding_rejection'] ?? null;
                $reasons = $card['analyticalEligible'] ? []
                    : ($rejection !== null ? [$rejection]
                        : ($card['limitationReasons'] ?: ['ineligible_without_reason']));
                foreach ($reasons as $reason) {
                    $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
                }
                $resolved = [];
                $candidate = $result['kpis'][$index] ?? [
                    'label' => $kpi->label, 'value' => $kpi->value, 'unit' => $kpi->unit,
                    'identity' => $kpi->identity_metadata,
                    'source_context' => $evidence?->data['evidence_resolution']['model_source_context']
                        ?? $evidence?->data['evidence_resolution']['inherited_model_anchor']
                        ?? $kpi->identity_metadata['candidate_source_context'] ?? [],
                ];
                foreach ($candidate['source_context'] ?? [] as $role => $id) {
                    $span = is_string($id) ? $spans->resolve($id) : null;
                    $resolved[$role] = $span ? ['id' => $id, 'page' => $span['page'],
                        'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
                        'text' => $span['text']] : ['id' => $id, 'resolved' => false];
                }
                $candidates[] = ['candidate' => $candidate, 'source_id' => $sourceId,
                    'source_context' => $resolved, 'rejection' => $rejection,
                    'ineligibility_reasons' => $reasons,
                    'evidence' => $evidence ? ['kind' => $evidence->kind,
                        'data' => $evidence->data, 'sources' => $evidence->sources] : null,
                    'integrity' => $observations[$sourceId]->integrity ?? null,
                    'api_kpi' => $card];
            }
            $gold = json_decode(file_get_contents($goldPath), true, flags: JSON_THROW_ON_ERROR);
            $series = array_values(array_filter($analysis['visualAnalysis']['charts'],
                fn ($chart) => ($chart['basis'] ?? null) === 'time_series'));
            $goldScore = (new NorthstarGoldScorer)->score($gold['cases'][0]['gold'], $candidates);
            $this->diagnostics->recordResult([
                'model_observation_count' => count($result['kpis']),
                'server_expanded_observation_count' => max(0, $saved->count() - count($result['kpis'])),
                'source_linked_observation_count' => DocumentEvidence::where('document_id', $document->id)
                    ->where('kind', 'metric')->count(),
                'structurally_proven_observation_count' => DocumentEvidence::where('document_id', $document->id)
                    ->where('kind', 'metric')->get()->filter(fn ($row) => is_int($row->data['source_cell_start_offset'] ?? null)
                        && is_int($row->data['source_cell_end_offset'] ?? null))->count(),
                'api_evidence_mapping_count' => count((array) $api['evidence']),
                'independently_eligible_count' => $eligible,
                'rejected_observation_count' => count($result['kpis']) - $eligible,
                'rejection_reasons' => $rejections,
                'gold_case_count' => count($gold['cases'][0]['gold']),
                'source_gold_score' => $goldScore,
                'candidates' => $candidates, 'financial_time_series' => $series,
                'strict_complete_financial_series_count' => $this->strictFinancialSeriesCount(
                    $goldScore, $candidates, $series),
                'trusted_chart_count' => $analysis['stats']['chartCandidates'],
                'deterministic_comparison_count' => $analysis['stats']['analyticalComparisons'],
                'derived_analysis' => $analysis, 'api_analysis' => $api['analysis'],
                'api_evidence' => $api['evidence'], 'latency_ms' => $latencyMs,
                'pipeline_latency_ms' => (int) ((hrtime(true) - $started) / 1000000),
                'usage' => DocumentAiRun::where('document_id', $document->id)
                    ->where('purpose', 'insights')->get(['status', 'model', 'input_tokens',
                        'output_tokens', 'estimated_cost_usd', 'duration_ms', 'stop_reason'])->toArray(),
                'factual_correctness_requires_manual_review' => true,
                'historical_v9_live' => ['model_observations' => 7, 'manual_source_correct' => 7,
                    'independently_eligible' => 1, 'trusted_charts' => 0, 'comparisons' => 0],
            ]);
            self::assertCount($saved->count(), $candidates);
            self::assertSame(29, count($gold['cases'][0]['gold']));
            self::assertSame(1, $guard->requests);
            if (! $live && ! str_starts_with($scenario, 'frozen')) {
                self::assertSame(1, $eligible);
            }
        });
    }

    private function expectedPrompt(AiPrompt $prompt, Document $document, string $text): string
    {
        $sourceText = app(NormalRouteProvenance::class)->promptText($document, $text);
        $rendered = app(PromptManager::class)->render($prompt, [
            '{{document_name}}' => $document->name,
            '{{document_text}}' => mb_substr($sourceText, 0, config('document_processing.max_extraction_chars')),
            '{{document_classification}}' => $document->classification,
        ]);

        return $rendered."\n\nFor this response, select at most "
            .(int) config('document_processing.insights_max_v10_observations')
            .' independently cited high-value observations and '
            .(int) config('document_processing.insights_max_observations')
            .' insights. Prioritize comparable measurements and required operands; keep the V10 JSON schema complete.';
    }

    private function recordedEnvelope(string $scenario): array
    {
        if (str_starts_with($scenario, 'frozen')) {
            $path = $this->frozenResponsePath();
            self::assertSame('622cca54e2ea149e36ada8fb3d177d93ea00613cc853f204d42d83bf023de6aa',
                hash_file('sha256', $path));
            return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
        $fixture = require base_path('tests/Fixtures/intelligence-v2/northstar-v10-observations.php');
        $valid = $fixture['kpis'][2];
        $invalid = $valid;
        $invalid['value'] = '52.0';
        $response = ['kpis' => [$valid, $invalid], 'insights' => []];
        if ($scenario === 'invalid_schema') {
            unset($response['kpis'][0]['source_context']);
        }

        return ['model' => 'claude-haiku-5-5',
            'stop_reason' => $scenario === 'truncated' ? 'max_tokens' : 'end_turn',
            'usage' => ['input_tokens' => 8266,
                'output_tokens' => $scenario === 'truncated' ? 14000 : 350],
            'content' => [['type' => 'text', 'text' => $scenario === 'truncated'
                ? '{"kpis":' : json_encode($response, JSON_THROW_ON_ERROR)]]];
    }

    private function frozenResponsePath(): string
    {
        return base_path('storage/framework/testing/docintel-v10-evaluation/'
            .'live-20261021-204955-e0085f8755c8.json.provider-response.json');
    }

    private function strictFinancialSeriesCount(array $score, array $candidates, array $series): int
    {
        $byGoldId = collect($score['gold_coverage'])->keyBy('gold_id');
        $complete = 0;
        foreach (['revenue', 'costs', 'profit', 'margin'] as $metric) {
            $indexes = [];
            foreach ([1, 2, 3] as $quarter) {
                $index = $byGoldId[$metric.'-q'.$quarter]['accepted_indexes'][0] ?? null;
                if ($index === null) {
                    continue 2;
                }
                $indexes[] = $index;
            }
            $ids = array_map(fn ($index) => $candidates[$index]['source_id'], $indexes);
            foreach ($series as $chart) {
                $points = $chart['series'][0]['data'] ?? $chart['points'] ?? [];
                if (array_column($points, 'label') === ['Q1 2026', 'Q2 2026', 'Q3 2026']
                    && count(array_intersect($ids, $chart['sourceIds'] ?? [])) === 3) {
                    $complete++;
                    break;
                }
            }
        }

        return $complete;
    }

    private function dedicatedEvaluationKey(): ?string
    {
        foreach (['DOCINTEL_V10_EVAL_ANTHROPIC_KEY', 'DOCINTEL_V9_EVAL_ANTHROPIC_KEY'] as $name) {
            $key = getenv($name);
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }
        $path = base_path('.env');
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        try {
            $values = Dotenv::parse(file_get_contents($path));
        } catch (\Throwable) {
            throw new RuntimeException('northstar_v10_eval_credential_file_invalid');
        }
        foreach (['DOCINTEL_V10_EVAL_ANTHROPIC_KEY', 'DOCINTEL_V9_EVAL_ANTHROPIC_KEY'] as $name) {
            $key = $values[$name] ?? null;
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }
}
