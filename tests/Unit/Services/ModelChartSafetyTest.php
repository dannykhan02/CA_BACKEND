<?php

namespace Tests\Unit\Services;

use App\Services\Extraction\StructuredDocument;
use App\Services\Intelligence\ModelChartRowMatcher;
use App\Services\Intelligence\ModelChartStructure;
use Tests\TestCase;

class ModelChartSafetyTest extends TestCase
{
    private function point(string $label, int|float|string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /** A projected grid with real offsets and geometry, as the PDF projector stores it. */
    private function grid(array $rows, int $page = 4): StructuredDocument
    {
        $text = '';
        $nodes = [['id' => 'page:'.$page, 'type' => 'page', 'page' => $page,
            'text' => '', 'start' => null, 'end' => null]];
        $tableId = 'page:'.$page.'/table[1]';
        $nodes[] = ['id' => $tableId, 'type' => 'table', 'parent' => 'page:'.$page,
            'page' => $page, 'meta' => ['structural_type' => 'basic_relational',
                'bbox' => [10, 10, 500, 300]]];
        foreach ($rows as $index => $row) {
            $rowId = $tableId.'/row['.($index + 1).']';
            $nodes[] = ['id' => $rowId, 'type' => 'row', 'parent' => $tableId, 'page' => $page];
            foreach ($row as $column => $cell) {
                $start = mb_strlen($text);
                $text .= $cell;
                $end = mb_strlen($text);
                $nodes[] = ['id' => $rowId.'/cell['.$column.']', 'type' => 'cell',
                    'parent' => $rowId, 'page' => $page, 'text' => $cell,
                    'start' => $start, 'end' => $end,
                    'meta' => ['grid_column' => $column + 1,
                        'bbox' => [10 + $column * 110, 20 + $index * 20,
                            90 + $column * 110, 30 + $index * 20]]];
                $text .= ' ';
            }
            $text .= "\n";
        }

        return new StructuredDocument('PDF', 'smalot-page.v2+poppler-grid.v1+basic-grid.v1',
            $text, $nodes, []);
    }

    public function test_model_chart_rendering_gate_keeps_bad_shapes_out(): void
    {
        $gate = app(ModelChartStructure::class);
        $good = [$this->point('Pasture', 2.91), $this->point('Woodland', 1.22)];
        self::assertTrue($gate->inspect('bar', $good)['eligible']);
        self::assertSame('ha', $gate->inspect('bar', $good, 'Survey area (ha)')['unit']);
        self::assertFalse($gate->inspect('bar', [$this->point('Pasture (m²)', 29100),
            $this->point('Woodland (m²)', 12200)], 'Survey area (ha)')['eligible']);
        foreach ([
            [$this->point('Pasture', INF), $good[1]],
            [$this->point('Pasture', '2.91'), $good[1]],
            [$this->point('Pasture', 2.91), $this->point(' pasture ', 1.22)],
            [$this->point('Pasture', 2.91), $this->point('Total', 4.13)],
            [$this->point('Pasture (ha)', 2.91), $this->point('Woodland (m²)', 12200)],
            [$this->point('', 2.91), $good[1]],
        ] as $mutant) {
            self::assertFalse($gate->inspect('bar', $mutant)['eligible']);
        }
        self::assertFalse($gate->inspect('line', $good)['eligible']);
        self::assertFalse($gate->inspect('pie', $good)['eligible']);
        self::assertTrue($gate->inspect('pie', [$this->point('North (%)', 40),
            $this->point('South (%)', 60)])['eligible']);
        self::assertFalse($gate->inspect('pie', [$this->point('North (%)', 40),
            $this->point('South (%)', 59)])['eligible']);
        self::assertTrue($gate->inspect('bar', [$this->point('Gain', -5),
            $this->point('Loss', 10)])['eligible']);
        self::assertTrue($gate->inspect('line', [$this->point('Q4 FY2025', 10),
            $this->point('Q1 FY2026', 12)])['eligible']);
    }

    public function test_shadow_matcher_distinguishes_rows_duplicates_and_contradictions(): void
    {
        $match = app(ModelChartRowMatcher::class);
        $source = $this->grid([['Pasture', '2.91'], ['Woodland', '1.22']]);
        self::assertSame('unique_row_match', $match->match($source, [
            $this->point('Pasture', 2.91), $this->point('Woodland', 1.22)])[0]['status']);
        self::assertSame('contradiction', $match->match($source, [
            $this->point('Pasture', 2.92), $this->point('Woodland', 1.22)])[0]['status']);
        self::assertSame('contradiction', $match->match($source, [
            $this->point('Pasture', 1.22), $this->point('Woodland', 2.91)])[0]['status']);
        self::assertSame('no_match', $match->match($source, [
            $this->point('Missing', 99), $this->point('Woodland', 1.22)])[0]['status']);
        self::assertSame('no_match', $match->match($source, [
            $this->point('Pasture', 2.91), $this->point('Woodland', 1.22)], 5)[0]['status']);
        self::assertSame('no_match', $match->match($source, [
            $this->point('Pasture', 2.91) + ['page' => 5], $this->point('Woodland', 1.22)])[0]['status']);
        self::assertSame('no_match', $match->match($source, [
            $this->point('Pasture', 2.91) + ['derived' => true], $this->point('Woodland', 1.22)])[0]['status']);

        $repeated = $this->grid([['A', '10'], ['A', '10'], ['B', '20']]);
        self::assertSame('ambiguous', $match->match($repeated, [
            $this->point('A', 10), $this->point('B', 20)])[0]['status']);
        $shared = $this->grid([['A', '10'], ['B', '10']]);
        self::assertSame('ambiguous', $match->match($shared, [
            $this->point('A', 10), $this->point('B', 10)])[0]['status']);
    }

    public function test_shadow_matcher_does_not_confuse_columns_or_category_numbers(): void
    {
        $match = app(ModelChartRowMatcher::class);
        $area = $this->grid([['Pasture', '2.91', '29,100'],
            ['Woodland', '1.22', '12,200']]);
        self::assertSame('unique_row_match', $match->match($area, [
            $this->point('Pasture', 29100), $this->point('Woodland', 12200)])[0]['status']);
        // This result says row proximity only; it cannot prove the ha/m² header.
        self::assertSame('figure_without_relationship', $match->match($area, [
            $this->point('Pasture', 12200), $this->point('Woodland', 29100)])[0]['status']);
        $payment = $this->grid([['Deposit (10% of price)', '2025-11-20', 'Transferor', '1,245,000'],
            ['Final payment', '2026-01-20', 'Transferor', '11,205,000']]);
        self::assertSame('ambiguous', $match->match($payment, [
            $this->point('Deposit (10% of price)', 1245000),
            $this->point('Final payment', 11205000)])[0]['status']);
        $twoColumns = $this->grid([['A', '10', 'B', '20'], ['C', '30', 'D', '40']]);
        self::assertSame('ambiguous', $match->match($twoColumns, [
            $this->point('A', 20), $this->point('C', 40)])[0]['status']);
        $nodes = $area->nodes;
        foreach ($nodes as &$node) {
            if (($node['type'] ?? null) === 'cell' && ($node['text'] ?? null) === '29,100') {
                unset($node['meta']['bbox']);
            }
        }
        unset($node);
        $incomplete = new StructuredDocument('PDF', $area->parserVersion, $area->text,
            $nodes, []);
        self::assertSame('figure_without_relationship', $match->match($incomplete, [
            $this->point('Pasture', 29100), $this->point('Woodland', 12200)])[0]['status']);
    }

    public function test_fixture_row_matching_metrics_are_scoped_to_row_proximity(): void
    {
        $matcher = app(ModelChartRowMatcher::class);
        $source = $this->grid([['A', '1,245,000'], ['B', '(2,000)'], ['C', '1,245,000'],
            ['D', '500']]);
        $cases = [
            ['A', 1245000, 'ambiguous'], // repeated figure
            ['B', -2000, 'unique_row_match'],
            ['D', 500, 'unique_row_match'],
            ['D', 501, 'contradiction'], // one-digit mutation
            ['B', 500, 'contradiction'], // swapped relationship
            ['Missing', 500, 'figure_without_relationship'],
        ];
        $actual = [];
        foreach ($cases as [$label, $value, $expected]) {
            $result = $matcher->match($source, [$this->point($label, $value),
                $this->point('B', -2000), $this->point('D', 500)])[0]['status'];
            $actual[] = $result;
            self::assertSame($expected, $result);
        }
        $truthMatches = 2;
        $falsePositiveMatches = count(array_filter($actual,
            static fn ($status) => $status === 'unique_row_match')) - $truthMatches;
        $detectedContradictions = count(array_filter($actual,
            static fn ($status) => $status === 'contradiction'));
        self::assertSame(0, $falsePositiveMatches); // 0/4 non-match cases in this fixture
        self::assertSame(2, $detectedContradictions); // 2/2 explicit contradictions
        self::assertSame(2, $truthMatches); // 2/2 unambiguous direct pairs
    }
}
