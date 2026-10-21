<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\ChartResource;
use App\Http\Resources\KpiResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\AiPrompt;
use App\Models\DocumentAiRun;
use App\Models\DocumentEvidence;
use App\Models\DocumentChart;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\ResponseValidator;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV10;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NorthstarV10OfflinePipelineTest extends TestCase
{
    use DatabaseTransactions;

    private function document(): Document
    {
        $path = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        self::assertSame('a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da',
            hash('sha256', $text));
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 81,
            'year' => 2026, 'pages' => 7, 'file_hash' => hash_file('sha256', $path),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:northstar-v10-offline',
            'normal_span_grounding' => app(NormalRouteProvenance::class)->eligible($document, $text),
            'text_hash' => hash('sha256', $text), 'model' => 'offline-fixture',
            'tokens' => strlen($text), 'count_method' => 'byte_upper_bound']])->save();
        self::assertTrue($document->ai_pipeline['normal_span_grounding']);
        self::assertSame(126, app(EvidenceGrounding::class)->spans($document)->count());

        return $document;
    }

    public function test_fourteen_independent_observations_build_complete_verified_analytics_and_api_evidence(): void
    {
        Queue::fake();
        config(['kpi_identity.semantic_matching' => false,
            'document_intelligence.revision_v1' => false,
            'intelligence_v2.enabled' => false]);
        $document = $this->document();
        $response = require base_path('tests/Fixtures/intelligence-v2/northstar-v10-observations.php');
        $validator = app(ResponseValidator::class);
        $response = $validator->validateInsights($response, true);
        $validator->validateV10Observations($response['kpis']);
        self::assertCount(14, $response['kpis']);
        self::assertSame([], $response['charts']);
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
            ->once()->andReturn($response + ['prompt_version' => 10]));
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $document = $document->fresh();
        self::assertSame('Ready', $document->status);
        self::assertSame(14, DocumentKpi::where('document_id', $document->id)->count());
        self::assertSame(14, DocumentEvidence::where('document_id', $document->id)
            ->where('kind', 'metric')->count());
        self::assertSame(0, $document->charts()->count());
        $collected = app(MetricCollector::class)->collect($document);
        self::assertSame(14, $collected['stats']['chartable']);
        foreach ($collected['observations'] as $observation) {
            self::assertTrue($observation->integrity['analyticalEligible'], $observation->sourceId);
            self::assertSame('verified', $observation->integrity['sourceVerification']);
        }

        $request = Request::create('/api/documents/'.$document->id.'/intelligence');
        $api = (new DocumentIntelligenceResource($document))->resolve($request);
        $documentApi = (new DocumentResource($document))->resolve($request);
        self::assertCount(14, $documentApi['kpis']);
        self::assertCount(0, $documentApi['charts']);
        $analysis = $api['analysis'];
        self::assertSame(14, $analysis['stats']['chartableFindings']);
        $charts = array_values(array_filter($analysis['visualAnalysis']['charts'],
            fn ($chart) => $chart['basis'] === 'time_series'));
        self::assertCount(4, $charts);
        $evidenceMap = (array) $api['evidence'];
        self::assertCount(14, $evidenceMap);
        foreach ($charts as $chart) {
            self::assertTrue($chart['renderEligible']);
            self::assertSame('verified', $chart['sourceVerification']);
            $points = $chart['series'][0]['data'] ?? $chart['points'];
            self::assertCount(3, $points);
            self::assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026'], array_column($points, 'label'));
            foreach ($points as $point) {
                self::assertCount(1, $point['sourceIds']);
                $sourceId = $point['sourceIds'][0];
                self::assertArrayHasKey($sourceId, $evidenceMap);
                $row = DocumentEvidence::where('source_id', $sourceId)->sole();
                $start = $row->data['source_cell_start_offset'];
                $end = $row->data['source_cell_end_offset'];
                self::assertSame($row->data['source_cell_value'],
                    mb_substr($document->extracted_text, $start, $end - $start));
                self::assertNotEmpty($evidenceMap[$sourceId]['sources']);
            }
        }
        $kinds = array_count_values(array_column($analysis['analyticalComparisons'], 'kind'));
        self::assertSame(4, $kinds['period_change'] ?? 0);
        self::assertSame(3, $kinds['financial_reconciliation'] ?? 0);
        self::assertSame(1, $kinds['actual_vs_target'] ?? 0);
        $target = collect($analysis['analyticalComparisons'])->firstWhere('kind', 'actual_vs_target');
        self::assertSame(-8.0, $target['gap']);
        self::assertSame('percentage points', $target['gapUnit']);
        self::assertSame('source_verified', $target['verification']);
        foreach ($target['sourceIds'] as $sourceId) {
            self::assertArrayHasKey($sourceId, $evidenceMap);
        }
        foreach (DocumentKpi::where('document_id', $document->id)->get() as $kpi) {
            $sourceId = 'kpi:'.$kpi->id;
            $request->attributes->set('kpi_evidence', collect([$sourceId => DocumentEvidence::where('source_id', $sourceId)->sole()]));
            $request->attributes->set('document_source_revision', hash('sha256', $document->extracted_text));
            $card = (new KpiResource($kpi))->resolve($request);
            self::assertTrue($card['analyticalEligible'], $sourceId);
            self::assertSame('verified', $card['sourceVerification']);
            self::assertSame([], $card['limitationReasons']);
        }
    }

    public function test_wrong_field_relationships_and_an_invented_value_never_gain_metric_evidence(): void
    {
        config(['kpi_identity.semantic_matching' => false,
            'document_intelligence.revision_v1' => false]);
        $document = $this->document();
        $fixture = require base_path('tests/Fixtures/intelligence-v2/northstar-v10-observations.php');
        $good = $fixture['kpis'][2]; // Q3 revenue, with correct source roles.
        $cases = [
            ['wrong subject', ['identity' => ['scope' => 'Another Distribution Ltd']], 'subject_source_mismatch'],
            ['wrong subject citation', ['source_context' => ['subject_span_id' => 'E029']], 'subject_source_mismatch'],
            ['wrong period', ['identity' => ['period' => 'Q2 2026']], 'value_column_mismatch'],
            ['wrong value', ['value' => '52.0'], 'value_column_mismatch'],
            ['wrong unit', ['unit' => 'USD million'], 'unit_source_mismatch'],
            ['wrong basis', ['identity' => ['value_basis' => 'target']], 'period_column_ambiguous'],
            ['invented value', ['value' => '999.0'], 'value_column_mismatch'],
        ];
        foreach ($cases as [$name, $change, $expected]) {
            $claim = $good;
            foreach ($change as $field => $value) {
                $claim[$field] = is_array($value) ? array_replace($claim[$field], $value) : $value;
            }
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => $claim['label'],
                'value' => $claim['value'], 'unit' => $claim['unit'],
                'period' => $claim['identity']['period'], 'identity_metadata' => $claim['identity']]);
            self::assertSame($expected, app(NormalKpiGrounder::class)->record($document, $kpi, $claim), $name);
            self::assertNull(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->first(), $name);
        }
        $collected = app(MetricCollector::class)->collect($document);
        self::assertSame(0, $collected['stats']['chartable']);
        foreach ($collected['observations'] as $observation) {
            self::assertContains('revision_proof_missing', $observation->integrity['reasons']);
            self::assertNotContains('source_revision_mismatch', $observation->integrity['reasons']);
        }
        $request = Request::create('/api/documents/'.$document->id);
        $request->attributes->set('kpi_evidence', collect());
        $request->attributes->set('document_source_revision', hash('sha256', $document->extracted_text));
        $card = (new KpiResource(DocumentKpi::where('document_id', $document->id)->firstOrFail()))->resolve($request);
        self::assertContains('revision_proof_missing', $card['limitationReasons']);
        self::assertNotContains('source_revision_mismatch', $card['limitationReasons']);
    }

    public function test_v10_job_discards_forged_model_chart_json_even_if_a_client_bypasses_validation(): void
    {
        Queue::fake();
        config(['kpi_identity.semantic_matching' => false,
            'document_intelligence.revision_v1' => false]);
        $document = $this->document();
        $response = require base_path('tests/Fixtures/intelligence-v2/northstar-v10-observations.php');
        $response['charts'] = [['type' => 'line', 'title' => 'Model-only figure',
            'data' => [['label' => 'Q3 2026', 'value' => 999.0]]]];
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
            ->once()->andReturn($response + ['prompt_version' => 10]));
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        self::assertSame(0, DocumentChart::where('document_id', $document->id)->count());
        $analysis = (new DocumentIntelligenceResource($document->fresh()))
            ->resolve(Request::create('/api/documents/'.$document->id.'/intelligence'))['analysis'];
        self::assertSame(4, $analysis['stats']['chartCandidates']);
        self::assertSame(14, $analysis['stats']['chartableFindings']);
    }

    public function test_new_model_charts_have_explicit_unverified_origin_while_historical_origin_stays_unknown(): void
    {
        Queue::fake();
        config(['document_intelligence.revision_v1' => false]);
        $document = $this->document();
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
            ->once()->andReturn(['kpis' => [], 'charts' => [['type' => 'line',
                'title' => 'Model-authored trend', 'description' => 'Unverified numbers',
                'data' => [['label' => 'Q1', 'value' => 42], ['label' => 'Q3', 'value' => 999]]]],
                'insights' => [], 'prompt_version' => 9]));
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        self::assertSame('model_unverified', $chart->source_metadata['origin']);
        $serialized = (new ChartResource($chart))->resolve(Request::create('/api/documents/'.$document->id));
        self::assertSame('model_unverified', $serialized['sourceOrigin']);
        self::assertFalse($serialized['renderEligible']);
        self::assertFalse($serialized['exportSupport']['csv']);
        self::assertFalse($serialized['exportSupport']['png']);
        self::assertFalse($serialized['exportSupport']['word']);
        self::assertStringContainsString('unverified model response', $serialized['unavailabilityReason']);

        $historical = DocumentChart::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'type' => 'bar', 'title' => 'Historical unknown',
            'description' => '', 'data' => [['label' => 'A', 'value' => 1]]]);
        $old = (new ChartResource($historical))->resolve(Request::create('/api/documents/'.$document->id));
        self::assertNull($old['sourceOrigin']);
        self::assertStringContainsString('historical chart', $old['unavailabilityReason']);
        self::assertFalse($old['exportSupport']['word']);

        $visual = DocumentChart::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'type' => 'bar', 'title' => 'Extracted figure',
            'description' => '', 'data' => [['label' => 'A', 'value' => 1]],
            'source_metadata' => ['origin' => 'document_visual', 'value_status' => 'unavailable']]);
        $visualApi = (new ChartResource($visual))->resolve(Request::create('/api/documents/'.$document->id));
        self::assertSame('document_visual', $visualApi['sourceOrigin']);
        self::assertFalse($visualApi['renderEligible']);
    }

    public function test_v10_prompt_selection_and_complete_json_parsing_use_the_expanded_output_cap_without_a_paid_call(): void
    {
        $this->seed(DocumentInsightsPromptSeederV10::class);
        AiPrompt::activate('document_insights', 10);
        config(['services.anthropic.extraction_model' => 'claude-haiku-5-5',
            'document_intelligence.structured_output_tokens.insights_v10' => 14000,
            'document_intelligence.structured_output_tokens.insights_v10_ceiling' => 20000]);
        $document = $this->document();
        $response = require base_path('tests/Fixtures/intelligence-v2/northstar-v10-observations.php');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 8266, 'output_tokens' => 9500],
            'content' => [['type' => 'text', 'text' => json_encode($response, JSON_THROW_ON_ERROR)]],
        ])]);
        $parsed = app(AnthropicClient::class)->extractDocumentInsights(
            $document->extracted_text, $document->name, $document);
        self::assertSame(10, $parsed['prompt_version']);
        self::assertCount(14, $parsed['kpis']);
        self::assertSame([], $parsed['charts']);
        Http::assertSent(fn ($request) => $request['max_tokens'] === 14000
            && str_contains((string) $request['messages'][0]['content'], 'one observation per relevant period')
            && ! str_contains((string) $request['messages'][0]['content'], 'high-value KPIs, 3 charts'));
        self::assertSame(1, DocumentAiRun::where('document_id', $document->id)
            ->where('purpose', 'insights')->count());
    }
}
