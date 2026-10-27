<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\DocumentResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\DocumentChart;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\ResponseValidator;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * Synthetic four-page Kisiwa analogue. The original PDF and its Claude response are unavailable;
 * these records test deterministic route contracts and the stored-evidence chart path only.
 */
class KisiwaVisualCoverageTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private const EXTRACTED_TEXT = "Kisiwa Ridge operating receipts: Q1 2025 KES 100 million; Q2 2025 KES 110 million; Q3 2025 KES 120 million; Q4 2025 KES 130 million.\f"
        ."Land-use allocation 2025: Residential 50%; Commercial 30%; Community 20%.\f"
        ."Land instalment of KES 12 million is due 30 June 2025 to Seller; payment status planned.\f"
        .'Operating budget 2025: Personnel KES 45 million; Maintenance KES 35 million; Services KES 20 million.';

    public function test_incremental_shaped_postverification_evidence_accounts_for_four_opportunities(): void
    {
        $document = $this->intelligenceDocument('Synthetic Kisiwa Ridge report.pdf');
        $document->forceFill(['pages' => 4, 'extracted_text' => self::EXTRACTED_TEXT])->save();

        foreach ([['Q1 2025', '100'], ['Q2 2025', '110'], ['Q3 2025', '120'], ['Q4 2025', '130']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period);
        }
        foreach (['Residential' => '50', 'Commercial' => '30', 'Community' => '20'] as $subject => $value) {
            $this->metricFinding($document, 'Land-use allocation', $value, '%', '2025', ['subject' => $subject]);
        }
        foreach (['Personnel' => '45', 'Maintenance' => '35', 'Services' => '20'] as $subject => $value) {
            $this->metricFinding($document, 'Operating budget', $value, 'KES million', '2025', ['subject' => $subject]);
        }
        // A dated obligation can be retained, but no metric observation or source field proof
        // links its amount to a payment row. Do not turn this into a payment chart.
        $this->evidenceRow($document, 'obligation', ['label' => 'Land instalment',
            'value' => 'KES 12 million planned', 'subject' => 'Seller',
            'due_date' => '2025-06-30', 'date_type' => 'explicit'], null);

        $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh());
        $diagnostics = $analysis['visualAnalysis']['diagnostics'];
        self::assertSame(10, $diagnostics['storedMetricFindings']);
        self::assertSame(10, $diagnostics['eligibleMeasurements']);
        self::assertSame(1, $diagnostics['structuredEventFindings']);
        self::assertSame(3, $diagnostics['candidateVisualizations']);
        self::assertSame(3, $diagnostics['renderableCandidates']);
        self::assertSame(0, $diagnostics['omittedByLimit']);
        self::assertSame(0, $diagnostics['duplicateSourceSetCandidates']);
        self::assertEqualsCanonicalizing(['Operating receipts', 'Land-use allocation · 2025', 'Operating budget · 2025'],
            array_column($analysis['visualAnalysis']['charts'], 'title'));
        self::assertNotContains('Land instalment', array_column($analysis['visualAnalysis']['charts'], 'title'));

        $datasets = $analysis['analyticalDatasets']['items'];
        self::assertCount(3, $datasets);
        self::assertSame(0, $analysis['analyticalDatasets']['diagnostics']['omittedByLimit']);
        $receipts = collect($datasets)->firstWhere('title', 'Operating receipts');
        self::assertNotNull($receipts);
        self::assertSame(['line', 'column', 'table'], array_column($receipts['views'], 'id'));
        self::assertSame(['100000000', '110000000', '120000000', '130000000'],
            array_column($receipts['points'], 'exactValue'));
        self::assertSame(['Q1 2025', 'Q2 2025', 'Q3 2025', 'Q4 2025'],
            array_column($receipts['points'], 'period'));
        self::assertSame([100.0, 110.0, 120.0, 130.0],
            array_column($receipts['points'], 'displayValue'));
        self::assertSame('KES million', $receipts['unit']);
        self::assertSame(['reported'], array_values(array_unique(array_column($receipts['points'], 'derivation'))));
        self::assertSame(4, count(array_unique(array_merge(...array_column($receipts['points'], 'sourceIds')))));
        $land = collect($datasets)->firstWhere('title', 'Land-use allocation · 2025');
        self::assertSame(['bar', 'table'], array_column($land['views'], 'id'));
        self::assertEqualsCanonicalizing(['Residential', 'Commercial', 'Community'],
            array_column($land['points'], 'category'));
        self::assertNotContains('Land instalment', array_column($datasets, 'title'));

        $api = (new DocumentIntelligenceResource($document->fresh()))
            ->resolve(Request::create('/api/documents/'.$document->id.'/intelligence'));
        self::assertCount(3, $api['analysis']['visualAnalysis']['charts']);
        self::assertCount(3, $api['analysis']['analyticalDatasets']['items']);
        $withoutDatasets = $api['analysis'];
        unset($withoutDatasets['analyticalDatasets']);
        $addedBytes = strlen(json_encode($api['analysis'], JSON_THROW_ON_ERROR))
            - strlen(json_encode($withoutDatasets, JSON_THROW_ON_ERROR));
        self::assertLessThan(12_000, $addedBytes);
        fwrite(STDERR, json_encode(['fixture' => 'stage2-kisiwa-synthetic',
            'dataset_count' => count($datasets), 'additional_analysis_bytes' => $addedBytes],
            JSON_THROW_ON_ERROR)."\n");
    }

    public function test_legacy_response_can_persist_four_model_charts_despite_three_chart_prompt_budget(): void
    {
        Queue::fake();
        config(['document_intelligence.revision_v1' => false,
            'document_processing.insights_max_charts' => 3]);
        $document = $this->intelligenceDocument('Synthetic Kisiwa Ridge legacy.pdf', 'Processing', false);
        $document->forceFill(['pages' => 4, 'extracted_text' => self::EXTRACTED_TEXT])->save();
        $document->uploader->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $chart = fn (string $title): array => ['type' => 'bar', 'title' => $title,
            'description' => '', 'data' => [['label' => 'Reported', 'value' => 1]]];
        $response = ['kpis' => [], 'charts' => array_map($chart,
            ['Operating receipts', 'Land-use allocation', 'Operating budget', 'Alternative receipts view']),
            'insights' => ['A dated payment obligation is reported.'], 'prompt_version' => 9];
        $response = app(ResponseValidator::class)->validateInsights($response);
        self::assertCount(4, $response['charts']);
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
            ->once()->andReturn($response));
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        self::assertSame(4, DocumentChart::where('document_id', $document->id)->count());
        $api = (new DocumentResource($document->fresh()))->resolve(Request::create('/api/documents/'.$document->id));
        $charts = $api['charts']->resolve(Request::create('/api/documents/'.$document->id));
        self::assertCount(4, $charts);
        self::assertSame(['model_unverified'], array_values(array_unique(array_column($charts, 'sourceOrigin'))));
    }

    public function test_v10_contract_retains_observations_but_never_accepts_chart_json(): void
    {
        $observation = ['label' => 'Operating receipts', 'value' => '100', 'unit' => 'KES million',
            'trend' => null, 'trendValue' => null,
            'identity' => ['concept' => 'Operating receipts', 'scope' => 'Kisiwa Ridge',
                'metric_type' => 'actual', 'quantity_kind' => 'amount', 'aggregation' => 'total',
                'value_basis' => 'total', 'period' => 'Q1 2025'],
            'source_context' => ['value_span_id' => 'E001', 'unit_span_id' => 'E001',
                'period_span_id' => 'E001', 'subject_span_id' => 'E001']];
        $response = app(ResponseValidator::class)->validateInsights(['kpis' => [$observation],
            'insights' => [], 'charts' => []], true);
        app(ResponseValidator::class)->validateV10Observations($response['kpis']);
        self::assertCount(1, $response['kpis']);
        self::assertSame([], $response['charts']);
        $this->expectException(\RuntimeException::class);
        app(ResponseValidator::class)->validateInsights(['kpis' => [$observation], 'insights' => [],
            'charts' => [['type' => 'bar', 'title' => 'Unsupported model chart']]], true);
    }

    public function test_incremental_schema_retains_dated_obligation_without_inventing_metric_link(): void
    {
        $quote = 'Land instalment of KES 12 million is due 30 June 2025.';
        $record = ['label' => 'Land instalment', 'value' => 'KES 12 million planned',
            'subject' => 'Seller', 'quote' => $quote, 'reference' => '',
            'entity_type' => null, 'unit' => null, 'period' => null,
            'date_type' => 'explicit', 'due_date' => '2025-06-30', 'severity' => null,
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null,
            'quantity_kind' => null, 'kind' => 'obligation', 'confidence' => 0.9, 'aliases' => []];
        $validated = EvidenceSchema::validate(['records' => [$record]], $quote);
        self::assertSame(1, $validated['_validation']['records_kept']);
        self::assertSame('obligation', $validated['records'][0]['kind']);
        self::assertNull($validated['records'][0]['unit']);
        self::assertNull($validated['records'][0]['period']);
    }
}
