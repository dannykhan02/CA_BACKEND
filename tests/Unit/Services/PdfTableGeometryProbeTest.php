<?php

namespace Tests\Unit\Services;

use App\Services\Extraction\PdfTableGeometryProbe;
use Tests\TestCase;

class PdfTableGeometryProbeTest extends TestCase
{
    public function test_reconstructs_a_financial_grid_with_source_coordinates_and_unit_heading(): void
    {
        $tables = app(PdfTableGeometryProbe::class)->extract(
            base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf'));

        self::assertCount(1, $tables);
        $table = $tables[0];
        self::assertSame(3, $table['page']);
        self::assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026'], array_values($table['periods']));
        self::assertSame('Financial results by quarter (KES million)', $table['unit_source']);
        self::assertSame('Revenue', $table['rows'][0][0]['text']);
        self::assertSame('51.0', $table['rows'][0][3]['text']);
        self::assertSame('Operating profit', $table['rows'][2][0]['text']);
        self::assertSame('10.0', $table['rows'][2][3]['text']);
        self::assertSame(4, count($table['rows']));
        self::assertCount(4, $table['rows'][0]);
        self::assertCount(4, $table['rows'][0][3]['bbox']);
        self::assertSame(hash_file('sha256', base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf')),
            $table['source_hash']);
        self::assertSame('layout_candidate_only', $table['status']);
    }

    public function test_cumulative_and_conflicting_headers_are_rejected_without_changing_negative_signs(): void
    {
        $tables = app(PdfTableGeometryProbe::class)->extract(
            base_path('tests/Fixtures/financial_layout_adversarial.pdf'));

        self::assertCount(1, $tables);
        self::assertSame(['Q3 2025', 'Q3 2026'], array_values($tables[0]['periods']));
        self::assertSame('(12)', $tables[0]['rows'][1][2]['text']);
        self::assertSame('Quarterly results (USD millions)', $tables[0]['unit_source']);
        self::assertNull($tables[0]['entity_source']);
        self::assertNull($tables[0]['accounting_basis_source']);
    }

    public function test_nonfinancial_pdf_does_not_become_a_financial_table(): void
    {
        self::assertSame([], app(PdfTableGeometryProbe::class)->extract(
            base_path('tests/Fixtures/test_5page.pdf')));
    }
}
