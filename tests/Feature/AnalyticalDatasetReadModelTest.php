<?php

namespace Tests\Feature;

use App\Models\DocumentChart;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\AnalyticalDatasetReadModel;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\MetricCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class AnalyticalDatasetReadModelTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function datasets($document): array
    {
        return app(DocumentAnalysisComposer::class)->compose($document->fresh())['analyticalDatasets'];
    }

    public function test_exact_scale_and_currency_are_preserved_without_cross_currency_merging(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts', '1,000', 'USD thousand', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '1.25', 'USD million', 'Q2 2025');
        $this->metricFinding($document, 'Operating receipts', '1,000', 'KES thousand', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '1.25', 'KES million', 'Q2 2025');

        $items = $this->datasets($document)['items'];
        self::assertCount(2, $items);
        self::assertCount(2, array_unique(array_column($items, 'id')));
        self::assertEqualsCanonicalizing(['USD', 'KES'], array_column($items, 'currency'));
        foreach ($items as $item) {
            self::assertSame(['1000000', '1250000.00'], array_column($item['points'], 'exactValue'));
            self::assertSame(['line', 'column', 'table'], array_column($item['views'], 'id'));
            self::assertSame(['source_verified'], array_values(array_unique(array_column($item['points'], 'evidenceAssurance'))));
        }
        self::assertSame($items, $this->datasets($document)['items']);
        self::assertSame(0, DocumentChart::where('document_id', $document->id)->count());
    }

    public function test_large_integer_stays_exact_in_table_but_has_no_chart_coordinate(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Units processed', '9007199254740993', 'units', 'Q1 2025');
        $this->metricFinding($document, 'Units processed', '9007199254740995', 'units', 'Q2 2025');

        $dataset = $this->datasets($document)['items'][0];
        self::assertSame(['9007199254740993', '9007199254740995'],
            array_column($dataset['points'], 'exactValue'));
        self::assertSame([null, null], array_column($dataset['points'], 'displayValue'));
        self::assertSame(['table'], array_column($dataset['views'], 'id'));
        self::assertContains('numeric_conversion_unsafe', $dataset['unsupportedReasons']);
    }

    public function test_small_rates_parenthesized_negatives_and_zero_are_not_missing(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Loss rate', '(0.000000000012)', '%', 'Q1 2025');
        $this->metricFinding($document, 'Loss rate', '0', '%', 'Q2 2025');
        $dataset = $this->datasets($document)['items'][0];
        self::assertSame(['-0.000000000012', '0'], array_column($dataset['points'], 'exactValue'));
        self::assertSame([-0.000000000012, 0.0], array_column($dataset['points'], 'displayValue'));

        $missing = $this->intelligenceDocument('Missing.pdf');
        $this->metricFinding($missing, 'Loss rate', '0', '%', 'Q1 2025');
        $this->metricFinding($missing, 'Loss rate', 'not stated', '%', 'Q2 2025');
        self::assertSame([], $this->datasets($missing)['items']);
    }

    public function test_gaps_conflicts_and_mixed_temporal_bases_never_gain_a_line_view(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Q1 2025', '10'], ['Q2 2025', '20'], ['Q2 2025', '21'], ['Q3 2025', '30']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period);
        }
        $this->metricFinding($document, 'Operating receipts', '40', 'KES million', 'FY2025');
        $this->metricFinding($document, 'Operating receipts', '50', 'KES million', '2025-04');
        $datasets = $this->datasets($document);
        self::assertCount(1, $datasets['items']);
        $item = $datasets['items'][0];
        self::assertSame(['Q1 2025', 'Q3 2025'], array_column($item['points'], 'period'));
        self::assertSame(['column', 'table'], array_column($item['views'], 'id'));
        self::assertContains('non_contiguous_periods', $item['unsupportedReasons']);
    }

    public function test_unresolved_amendment_conflict_is_not_silently_selected(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '20', 'KES million', 'Q2 2025');
        $this->metricFinding($document, 'Operating receipts', '22', 'KES million', 'Q2 2025',
            ['quote' => 'Operating receipts revised to 22 KES million for Q2 2025.']);
        $this->metricFinding($document, 'Operating receipts', '30', 'KES million', 'Q3 2025');

        $dataset = $this->datasets($document)['items'][0];
        self::assertSame(['Q1 2025', 'Q3 2025'], array_column($dataset['points'], 'period'));
        self::assertSame(['column', 'table'], array_column($dataset['views'], 'id'));
    }

    public function test_forecast_claims_without_sufficient_semantic_proof_do_not_enter_actual_dataset(): void
    {
        $document = $this->intelligenceDocument();
        foreach (['actual', 'forecast'] as $status) {
            $this->metricFinding($document, 'Operating receipts '.$status, '10', 'KES million', 'Q1 2025',
                ['metric_type' => $status, 'value_basis' => $status]);
            $this->metricFinding($document, 'Operating receipts '.$status, '20', 'KES million', 'Q2 2025',
                ['metric_type' => $status, 'value_basis' => $status]);
        }
        $items = $this->datasets($document)['items'];
        self::assertCount(1, $items);
        self::assertSame('Operating receipts actual', $items[0]['title']);
        self::assertSame(['actual'], array_values(array_unique(array_column($items[0]['points'], 'valueStatus'))));
    }

    public function test_incompatible_area_units_do_not_form_one_categorical_dataset(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Land-use allocation', '12', 'hectares', '2025', ['subject' => 'Residential']);
        $this->metricFinding($document, 'Land-use allocation', '18', 'square metres', '2025', ['subject' => 'Commercial']);
        self::assertSame([], $this->datasets($document)['items']);
    }

    public function test_percentages_summing_to_one_hundred_do_not_create_a_pie_view(): void
    {
        $document = $this->intelligenceDocument();
        foreach (['Personnel' => '50', 'Maintenance' => '30', 'Services' => '20'] as $subject => $value) {
            $this->metricFinding($document, 'Budget distribution', $value, '%', '2025', ['subject' => $subject]);
        }
        $dataset = $this->datasets($document)['items'][0];
        self::assertSame(['bar', 'table'], array_column($dataset['views'], 'id'));
        self::assertNotContains('pie', array_column($dataset['views'], 'type'));
    }

    public function test_repeated_values_deduplicate_and_a_total_is_not_an_extra_category(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '20', 'KES million', 'Q2 2025');
        foreach (['Residential' => '12', 'Commercial' => '18', 'Total' => '30'] as $subject => $value) {
            $this->metricFinding($document, 'Land allocation', $value, 'hectares', '2025', ['subject' => $subject]);
        }

        $items = $this->datasets($document)['items'];
        self::assertCount(2, $items);
        $receipts = collect($items)->firstWhere('title', 'Operating receipts');
        self::assertCount(2, $receipts['points']);
        $land = collect($items)->firstWhere('title', 'Land allocation · 2025');
        self::assertEqualsCanonicalizing(['Residential', 'Commercial'], array_column($land['points'], 'category'));
    }

    public function test_strict_pdf_source_candidates_are_not_promoted_without_artifact_proof(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Q1 2025', '10'], ['Q2 2025', '20']] as [$period, $value]) {
            $kpi = $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period);
            $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
            $row->forceFill(['data' => $row->data + ['source_candidate' => true,
                'source_file_hash' => $document->file_hash]])->save();
        }
        $datasets = $this->datasets($document);
        self::assertSame([], $datasets['items']);
        self::assertSame(['pdf_artifact_proof_required' => 1], $datasets['diagnostics']['unsupported']);
    }

    public function test_long_series_is_excluded_from_initial_dataset_payload_with_reason(): void
    {
        $document = $this->intelligenceDocument();
        foreach (range(2000, 2024) as $year) {
            $this->metricFinding($document, 'Operating receipts', (string) ($year - 1990),
                'KES million', (string) $year);
        }
        $datasets = $this->datasets($document);
        self::assertSame([], $datasets['items']);
        self::assertSame(['point_count_unsupported' => 1], $datasets['diagnostics']['unsupported']);
    }

    public function test_single_measurement_has_no_reusable_chart_dataset(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Settlement amount', '0', 'KES', '2025');
        self::assertSame([], $this->datasets($document)['items']);
    }

    public function test_unpublished_revision_does_not_expose_candidate_dataset_and_model_charts_are_untouched(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '20', 'KES million', 'Q2 2025');
        $legacy = DocumentChart::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'type' => 'bar', 'title' => 'Model chart',
            'description' => '', 'data' => [['label' => 'A', 'value' => 1]],
            'source_metadata' => ['origin' => 'model_unverified']]);
        self::assertCount(1, $this->datasets($document)['items']);
        $published = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published']);
        $candidate = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'candidate']);
        $document->forceFill(['published_intelligence_revision_id' => $published->id,
            'active_intelligence_revision_id' => $candidate->id, 'intelligence_generation' => 2])->save();
        self::assertSame([], $this->datasets($document)['items']);
        self::assertSame(['unpublished_revision' => 1], $this->datasets($document)['diagnostics']['unsupported']);
        self::assertTrue($legacy->fresh()->exists);
    }

    public function test_published_view_keeps_candidate_data_separate_and_switches_after_publication(): void
    {
        $document = $this->intelligenceDocument();
        $snapshot = ['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'file_hash' => $document->file_hash, 'extracted_text' => $document->extracted_text,
            'extracted_text_hash' => hash('sha256', $document->extracted_text)];
        $a = DocumentIntelligenceRevision::create($snapshot + ['generation' => 1,
            'state' => 'published', 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']]);
        $document->forceFill(['published_intelligence_revision_id' => $a->id,
            'active_intelligence_revision_id' => $a->id, 'intelligence_generation' => 1,
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']])->save();
        foreach ([['Q1 2025', '10'], ['Q2 2025', '20']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period)
                ->forceFill(['intelligence_revision_id' => $a->id])->save();
        }
        $service = app(DocumentIntelligenceService::class);
        $publishedA = app(DocumentAnalysisComposer::class)->compose($service->publishedView($document->fresh()))['analyticalDatasets']['items'][0];
        self::assertSame(['10000000', '20000000'], array_column($publishedA['points'], 'exactValue'));

        $b = DocumentIntelligenceRevision::create($snapshot + ['generation' => 2,
            'state' => 'candidate', 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b']]);
        $document->forceFill(['active_intelligence_revision_id' => $b->id,
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b'], 'status' => 'Processing'])->save();
        foreach ([['Q1 2025', '30'], ['Q2 2025', '40']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period)
                ->forceFill(['intelligence_revision_id' => $b->id])->save();
        }
        $stillA = app(DocumentAnalysisComposer::class)->compose($service->publishedView($document->fresh()))['analyticalDatasets']['items'][0];
        self::assertSame($publishedA['id'], $stillA['id']);
        self::assertSame(['10000000', '20000000'], array_column($stillA['points'], 'exactValue'));

        $b->forceFill(['state' => 'published'])->save();
        $document->forceFill(['published_intelligence_revision_id' => $b->id,
            'active_intelligence_revision_id' => null, 'status' => 'Ready'])->save();
        $publishedB = app(DocumentAnalysisComposer::class)->compose($service->publishedView($document->fresh()))['analyticalDatasets']['items'][0];
        self::assertNotSame($publishedA['id'], $publishedB['id']);
        self::assertSame(['30000000', '40000000'], array_column($publishedB['points'], 'exactValue'));
        self::assertSame(4, DocumentEvidence::where('document_id', $document->id)->count());
    }

    public function test_equal_values_in_other_workspace_have_distinct_dataset_identity_and_sources(): void
    {
        $first = $this->intelligenceDocument('First.pdf');
        $second = $this->intelligenceDocument('Second.pdf');
        foreach ([$first, $second] as $document) {
            $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
            $this->metricFinding($document, 'Operating receipts', '20', 'KES million', 'Q2 2025');
        }
        $a = $this->datasets($first)['items'][0];
        $b = $this->datasets($second)['items'][0];
        self::assertNotSame($a['workspaceId'], $b['workspaceId']);
        self::assertNotSame($a['id'], $b['id']);
        self::assertNotSame($a['points'][0]['sourceIds'], $b['points'][0]['sourceIds']);
    }

    public function test_many_observations_stay_bounded_with_no_dataset_queries_or_duplicated_point_arrays(): void
    {
        $document = $this->intelligenceDocument('Many observations.pdf');
        foreach (range(1, 30) as $index) {
            foreach (['Q1 2025', 'Q2 2025', 'Q3 2025', 'Q4 2025'] as $quarter => $period) {
                $this->metricFinding($document, 'Operating receipts segment '.sprintf('%02d', $index),
                    (string) ($index * 100 + $quarter), 'KES million', $period);
            }
        }
        $document = $document->fresh();
        $observations = app(MetricCollector::class)->collect($document)['observations'];
        $candidates = app(ChartCandidateBuilder::class)->build($observations)['candidates'];
        $evidence = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $document->ai_pipeline['key'])->get();
        self::assertCount(120, $observations);
        self::assertCount(30, $candidates);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = app(AnalyticalDatasetReadModel::class)->build($document, $observations, $candidates, $evidence);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(0, $queries);
        self::assertSame(30, $result['diagnostics']['eligible']);
        self::assertSame(12, $result['diagnostics']['returned']);
        self::assertSame(18, $result['diagnostics']['omittedByLimit']);
        self::assertSame(48, array_sum(array_map(fn ($item) => count($item['points']), $result['items'])));
        self::assertSame(36, array_sum(array_map(fn ($item) => count($item['views']), $result['items'])));
        $bytes = strlen(json_encode($result, JSON_THROW_ON_ERROR));
        self::assertLessThan(80_000, $bytes);
        self::assertLessThan(1000, $elapsedMs);
        fwrite(STDERR, json_encode(['fixture' => 'stage2-120-observations',
            'dataset_build_ms' => round($elapsedMs, 2), 'dataset_payload_bytes' => $bytes,
            'dataset_queries' => count($queries)], JSON_THROW_ON_ERROR)."\n");
    }
}
