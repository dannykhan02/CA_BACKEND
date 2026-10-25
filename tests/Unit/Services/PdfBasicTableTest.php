<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Extraction\BasicTableInterpreter;
use App\Services\Extraction\PdfTableGeometryProbe;
use App\Services\Extraction\PdfTableStructureProjector;
use App\Services\Intelligence\StructuralProvenanceValidator;
use App\Services\Intelligence\StructuredProvenanceProducer;
use Tests\TestCase;

class PdfBasicTableTest extends TestCase
{
    private function fixture(string $name = 'synthetic_single_period_costs.pdf'): string
    {
        return base_path('tests/Fixtures/'.$name);
    }

    public function test_single_period_table_keeps_exact_cells_blank_total_and_chart_gate(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture());
        self::assertSame(1, $source->capabilities['projected_basic_tables']);
        self::assertSame(1, $source->capabilities['projected_tables']);
        $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
        self::assertSame('Table 8: Purchased input costs, 2025/26', $table['caption']);
        self::assertSame('FY2025/26', $table['period']);
        self::assertSame($table['caption_cell_id'], $table['period_source_cell_id']);
        self::assertSame('reconstructed_exact', $table['structural_status']);
        self::assertSame('unverified', $table['evidence_status']);
        self::assertSame('potential_only', $table['analytical_status']);
        self::assertSame(['category', 'attribute_or_measure_unresolved', 'amount', 'percentage'],
            array_column($table['columns'], 'role'));
        self::assertSame('$', $table['columns'][2]['unit']);
        self::assertSame(138400.0, $table['rows'][0]['cells'][2]['parsed_value']);
        self::assertSame('reported_total', $table['rows'][7]['aggregation_role']);
        self::assertSame('blank', $table['rows'][7]['cells'][1]['data_type']);
        self::assertNull($table['rows'][7]['cells'][1]['source']);
        self::assertSame('arithmetically_consistent', $table['arithmetic_checks'][0]['status']);
        self::assertSame('requires_evidence_verification',
            $table['potential_charts'][0]['status']);
        $cell = $table['rows'][0]['cells'][2];
        self::assertSame('138,400', mb_substr($source->text, $cell['source']['start'],
            $cell['source']['end'] - $cell['source']['start']));
        self::assertCount(4, $cell['source']['bbox']);
        [$anchors, $edges, $bindings] = app(StructuredProvenanceProducer::class)->build($source, []);
        app(StructuralProvenanceValidator::class)->validate($anchors, $edges, $bindings);
    }

    public function test_conflicting_reported_total_blocks_chart_potential(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured(
            $this->fixture('synthetic_single_period_costs_conflict.pdf'));
        $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
        self::assertSame('blocked_conflicting_total', $table['analytical_status']);
        self::assertSame([], $table['potential_charts']);
        self::assertSame('conflict', $table['arithmetic_checks'][0]['status']);
    }

    public function test_duplicate_categories_and_unstated_amount_unit_block_verified_candidates(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        foreach (['synthetic_single_period_costs_duplicate.pdf',
            'synthetic_single_period_costs_no_unit.pdf'] as $fixture) {
            $source = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture($fixture));
            $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
            self::assertSame('not_eligible_or_ambiguous', $table['analytical_status'], $fixture);
            self::assertSame([], $table['potential_charts'], $fixture);
        }
    }

    public function test_forecast_status_blocks_an_actual_cost_breakdown_chart(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured(
            $this->fixture('synthetic_single_period_costs_forecast.pdf'));
        $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
        self::assertStringContainsString('Forecast', $table['caption']);
        self::assertSame('blocked_value_status', $table['analytical_status']);
        self::assertSame([], $table['potential_charts']);
    }

    public function test_incorrect_individual_percentage_share_blocks_amount_chart(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured(
            $this->fixture('synthetic_single_period_costs_share_conflict.pdf'));
        $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
        self::assertSame('blocked_conflicting_total', $table['analytical_status']);
        self::assertSame([], $table['potential_charts']);
        self::assertContains('conflict', array_column($table['arithmetic_checks'], 'status'));
    }

    public function test_unrecovered_nearby_caption_blocks_chart_rather_than_losing_forecast_context(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured(
            $this->fixture('synthetic_single_period_costs_ambiguous_caption.pdf'));
        $table = app(BasicTableInterpreter::class)->interpret($source, 'page:1/table[1]');
        self::assertSame('blocked_ambiguous_context', $table['analytical_status']);
        self::assertSame([], $table['potential_charts']);
    }

    public function test_swapped_geometry_and_wrong_value_or_header_are_rejected(): void
    {
        config()->set('document_intelligence.pdf_table_structure', false);
        $base = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture());
        $candidate = app(PdfTableGeometryProbe::class)->extractAll($this->fixture());
        self::assertCount(1, $candidate);
        $swapped = $candidate;
        [$swapped[0]['rows'][0][2]['bbox'], $swapped[0]['rows'][0][3]['bbox']] =
            [$swapped[0]['rows'][0][3]['bbox'], $swapped[0]['rows'][0][2]['bbox']];
        $result = app(PdfTableStructureProjector::class)->augment($base, $swapped);
        self::assertSame(0, $result->capabilities['projected_tables']);
        self::assertSame(['cell_column_geometry_mismatch' => 1],
            $result->capabilities['table_rejection_reasons']);

        $changed = $candidate;
        $changed[0]['rows'][0][2]['text'] = '183,400';
        self::assertSame(0, app(PdfTableStructureProjector::class)
            ->augment($base, $changed)->capabilities['projected_tables']);
        $changed = $candidate;
        $changed[0]['header'][2]['text'] = 'Cost (EUR)';
        self::assertSame(0, app(PdfTableStructureProjector::class)
            ->augment($base, $changed)->capabilities['projected_tables']);
    }

    public function test_existing_financial_grid_and_paragraph_fixture_keep_their_boundary(): void
    {
        $northstar = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        self::assertCount(1, app(PdfTableGeometryProbe::class)->extract($northstar));
        $all = app(PdfTableGeometryProbe::class)->extractAll($northstar);
        self::assertSame(['multi_period_financial_grid', 'basic_relational'],
            array_column($all, 'structural_type'));
        self::assertSame([], app(PdfTableGeometryProbe::class)->extractAll(
            base_path('tests/Fixtures/test_5page.pdf')));
    }
}
