<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Extraction\PdfTableGeometryProbe;
use App\Services\Extraction\PdfTableStructureProjector;
use App\Services\Intelligence\StructuralProvenanceValidator;
use App\Services\Intelligence\StructuredProvenanceProducer;
use Tests\TestCase;

class PdfTableStructureProjectorTest extends TestCase
{
    private function fixture(): string
    {
        return base_path('tests/Fixtures/nasdaq_q3_2026_income_statement_page.pdf');
    }

    public function test_official_income_statement_projects_cells_and_valid_source_edges(): void
    {
        $path = $this->fixture();
        $base = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(['page'], array_unique(array_column($base->nodes, 'type')));

        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        self::assertCount(1, $layout);
        self::assertSame(['Q3 2026', 'Q3 2025', '9M 2026', '9M 2025'],
            array_values($layout[0]['periods']));
        $source = app(PdfTableStructureProjector::class)->augment($base, $layout);
        self::assertSame($base->text, $source->text);
        self::assertSame(1, $source->capabilities['projected_tables']);
        self::assertSame('projected', $source->capabilities['table_projection_status']);
        self::assertSame(1, $source->capabilities['table_candidate_count']);
        self::assertSame(0, $source->capabilities['table_rejected_count']);

        $rows = array_values(array_filter($source->nodes,
            static fn ($node) => $node['type'] === 'row' && $node['id'] !== 'page:1/table[1]/row[0]'));
        self::assertCount(32, $rows);
        $cases = [
            'Revenues less transaction-based expenses' => ['1,481', '1,315'],
            'Total operating expenses' => ['749', '729'],
            'Operating income' => ['732', '586'],
        ];
        foreach ($cases as $label => $values) {
            $row = array_values(array_filter($source->nodes, static fn ($node) => $node['type'] === 'cell'
                && $node['text'] === $label));
            self::assertCount(1, $row);
            $rowId = $row[0]['parent'];
            foreach ($values as $column => $value) {
                $cell = $source->node($rowId.'/cell['.($column + 1).']');
                self::assertSame($value, $cell['text']);
                self::assertSame($value, mb_substr($source->text, $cell['start'],
                    $cell['end'] - $cell['start']));
                self::assertCount(3, $cell['meta']['header_path_ids']);
                self::assertCount(4, $cell['meta']['bbox']);
            }
        }

        [$anchors, $edges, $bindings] = app(StructuredProvenanceProducer::class)->build($source, []);
        app(StructuralProvenanceValidator::class)->validate($anchors, $edges, $bindings);
        $counts = array_count_values(array_column($edges, 'type'));
        self::assertSame(128, $counts['row_header']);
        self::assertSame(640, $counts['column_header']);
        self::assertSame(192, $counts['table_parent']);
        self::assertGreaterThanOrEqual(160, count(array_filter($anchors,
            static fn ($anchor) => isset($anchor['geometry']))));
    }

    public function test_period_and_column_tampering_cannot_project_a_table(): void
    {
        $path = $this->fixture();
        $base = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        $layout[0]['periods'][2] = 'Q3 2026';
        $rejected = app(PdfTableStructureProjector::class)->augment($base, $layout);
        self::assertSame(0, $rejected->capabilities['projected_tables']);
        self::assertSame('candidates_rejected', $rejected->capabilities['table_projection_status']);
        self::assertSame(1, $rejected->capabilities['table_rejected_count']);
        self::assertSame(['period_path_mismatch' => 1], $rejected->capabilities['table_rejection_reasons']);

        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        $layout[0]['header'][1]['bbox'] = null;
        self::assertSame(0, app(PdfTableStructureProjector::class)
            ->augment($base, $layout)->capabilities['projected_tables']);

        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        $layout[0]['rows'][7][1]['bbox'] = null;
        $projected = app(PdfTableStructureProjector::class)->augment($base, $layout);
        self::assertNull($projected->node('page:1/table[1]/row[8]/cell[1]'));

        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        $layout[0]['header'][1]['source_path'][0] = $layout[0]['header'][3]['source_path'][0];
        self::assertSame(0, app(PdfTableStructureProjector::class)
            ->augment($base, $layout)->capabilities['projected_tables']);
    }

    public function test_default_off_retains_page_only_extraction(): void
    {
        config()->set('document_intelligence.pdf_table_structure', false);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture());
        self::assertSame(['page'], array_unique(array_column($source->nodes, 'type')));
    }

    public function test_swapped_value_columns_do_not_project_as_a_valid_relationship(): void
    {
        $path = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        config()->set('document_intelligence.pdf_table_structure', false);
        $base = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        $layout = app(PdfTableGeometryProbe::class)->extract($path);
        self::assertCount(1, $layout);
        foreach ($layout[0]['rows'] as &$row) {
            [$row[1], $row[2]] = [$row[2], $row[1]];
        }
        unset($row);

        $source = app(PdfTableStructureProjector::class)->augment($base, $layout);
        self::assertSame(0, $source->capabilities['projected_tables']);
        self::assertSame('candidates_rejected', $source->capabilities['table_projection_status']);
        self::assertSame(['insufficient_exact_rows' => 1], $source->capabilities['table_rejection_reasons']);
        self::assertNotContains('table', array_column($source->nodes, 'type'));
    }

    public function test_no_candidates_are_distinguished_from_rejected_candidates(): void
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured(
            base_path('tests/Fixtures/test_5page.pdf'));
        self::assertSame('no_candidates_detected', $source->capabilities['table_projection_status']);
        self::assertSame(0, $source->capabilities['table_candidate_count']);
        self::assertSame(0, $source->capabilities['table_rejected_count']);
        self::assertSame([], $source->capabilities['table_rejection_reasons']);
    }

    public function test_opt_in_projects_tables_without_changing_prose_text(): void
    {
        config()->set('document_intelligence.pdf_table_structure', false);
        $base = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture());
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($this->fixture());
        self::assertSame($base->text, $source->text);
        self::assertSame(1, $source->capabilities['projected_tables']);
        self::assertContains('table', array_column($source->nodes, 'type'));
    }
}
