<?php

namespace Tests\Unit\Services;

use App\Services\AI\ExtractionBenchmarkScorer;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PeriodParser;
use Tests\TestCase;

class NorthstarSourceGoldTest extends TestCase
{
    private const SHA256 = '4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f';

    public function test_gold_measurements_are_tied_to_the_original_pdf_rows_headers_and_pages(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf';
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2)
            .'/Fixtures/intelligence-v2/northstar-source-gold.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::SHA256, hash_file('sha256', $path));
        self::assertSame(self::SHA256, $manifest['source_sha256']);
        $extractor = new DocumentTextExtractor;
        $pages = explode("\f", $extractor->extractPdfText($path));
        self::assertCount(7, $pages);
        self::assertCount(29, $manifest['cases'][0]['gold']);

        $headers = [
            'Financial results by quarter (KES million)' => ['Measure', 'Q1 2026', 'Q2 2026', 'Q3 2026'],
            'Operational indicator' => ['Operational indicator', 'Q1', 'Q2', 'Q3', 'Q3 target'],
            'Q3 operating costs by function (KES million)' => ['Logistics', 'Payroll', 'Warehousing', 'Technology', 'Total'],
            'Q3 revenue by region (KES million)' => ['North', 'East', 'West', 'Total'],
        ];
        foreach ($manifest['cases'][0]['gold'] as $item) {
            $page = $pages[$item['page'] - 1];
            $context = $item['id'];
            self::assertSame(1, substr_count($page, $item['row']), $context.' row occurrence');
            self::assertStringContainsString($item['table'], $page, $context.' table heading');
            self::assertStringContainsString('NORTHSTAR DISTRIBUTION LTD', $page, $context.' subject');
            if (isset($headers[$item['table']])) {
                $header = $headers[$item['table']];
                self::assertStringContainsString(implode("\t", $header), $page, $context.' column headings');
                $column = array_search($item['column'], $header, true);
                self::assertNotFalse($column, $context.' column');
                $cells = explode("\t", $item['row']);
                $actual = $cells[$column];
                if ($item['id'] === 'complaints-ceiling-q3') {
                    self::assertSame('≤ 90', $actual);
                } else {
                    self::assertSame($item['source_value'] ?? $item['value'], $actual, $context.' cell value');
                }
            } else {
                self::assertSame('service-recovery-proposed', $context);
                self::assertSame('2.40', explode("\t", $item['row'])[1]);
                self::assertStringContainsString("Proposed funding (KES\nmillion)", $page);
            }
            self::assertCount(1, $item['source_ids']);
        }

        self::assertStringContainsString('Customer complaints are counted as cases received', $pages[1]);
        self::assertStringContainsString('Funding is a proposal, not an approved expenditure', $pages[4]);
        self::assertStringContainsString('The proposed budget is separate from Q3 actual operating costs', $pages[4]);
        self::assertStringContainsString('No decision is recorded in this pre-meeting pack', $pages[5]);
        self::assertSame('proposed', $manifest['cases'][0]['gold'][25]['value_basis']);
        self::assertNull($manifest['cases'][0]['gold'][25]['period']);
        self::assertSame(41, 17 + 12 + 7 + 5);
        self::assertSame(8, 95 - 87); // percentage points, not a relative-percent change
        self::assertSame(43, 133 - 90); // complaint cases above the target ceiling
    }

    public function test_benchmark_has_no_unreviewed_model_recordings_or_invented_scores(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2)
            .'/Fixtures/intelligence-v2/northstar-source-gold.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $manifest['cases'][0]['runs']);
        $report = app(ExtractionBenchmarkScorer::class)->score($manifest);
        foreach ($manifest['models'] as $model) {
            self::assertSame('pending_recordings', $report['models'][$model]['status']);
            self::assertSame(29, $report['models'][$model]['expected']);
            self::assertSame(1, $report['models'][$model]['pending_cases']);
            self::assertNull($report['models'][$model]['completeness']);
        }
    }

    public function test_all_twelve_v7_production_candidates_are_compared_with_source_and_remain_unverified(): void
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/intelligence-v2/';
        $production = json_decode(file_get_contents($directory.'northstar-production-v7-kpis.json'),
            true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents($directory.'northstar-source-gold.json'),
            true, flags: JSON_THROW_ON_ERROR);
        $gold = array_column($manifest['cases'][0]['gold'], null, 'id');
        self::assertCount(12, $production['kpis']);
        self::assertSame('01a15341-11c8-72be-9e20-62bcd1ac0cab', $production['published_revision_id']);
        $periodMismatches = [];
        $basisConflicts = [];
        foreach ($production['kpis'] as $candidate) {
            $expected = $gold[$candidate['source_gold_id']];
            $context = 'production KPI '.$candidate['id'];
            self::assertSame($candidate['unit'], $expected['unit'], $context.' unit');
            self::assertSame($candidate['scope'], $expected['subject'], $context.' subject');
            self::assertSame((float) str_replace(',', '', $candidate['value']),
                (float) rtrim(str_replace(',', '', $expected['value']), '%'), $context.' value');
            self::assertSame([], $candidate['evidence'], $context.' historical evidence');
            $measurement = app(MeasurementParser::class)->parse($candidate['value'], $candidate['unit'],
                $candidate['label']);
            $integrity = app(MeasurementIntegrity::class)->assess($measurement, $candidate['value'],
                $candidate['unit'], ['metric_type' => $candidate['metric_type'],
                    'value_basis' => $candidate['value_basis'], 'period' => $candidate['period']], [], false);
            self::assertFalse($integrity['analyticalEligible'], $context.' cannot inherit PDF proof');
            self::assertContains('missing_source_reference', $integrity['reasons']);

            if ($candidate['period'] !== $expected['period']) {
                if ($expected['period'] === null) {
                    self::assertSame('Q3 2026', $candidate['period']);
                } else {
                    self::assertSame('Q3', $candidate['period']);
                    self::assertSame($expected['period'], $candidate['identity_period']);
                    self::assertNull(app(PeriodParser::class)->parse($candidate['period']));
                }
                $periodMismatches[] = $candidate['id'];
            }
            if ($candidate['value_basis'] !== $expected['value_basis']) {
                $basisConflicts[] = $candidate['id'];
            }
        }
        self::assertSame([4575, 4576, 4577, 4578, 4579, 4580, 4582], $periodMismatches);
        self::assertSame([4580], $basisConflicts);
        self::assertNull($production['kpis'][7]['comparison_operator']);
        self::assertSame('<=', $gold['complaints-ceiling-q3']['comparison_operator']);
        self::assertSame('proposed', $gold['service-recovery-proposed']['value_basis']);
        self::assertNull($gold['service-recovery-proposed']['period']);
    }
}
