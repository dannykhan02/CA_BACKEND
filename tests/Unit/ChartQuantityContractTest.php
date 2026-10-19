<?php

namespace Tests\Unit;

use App\Services\Intelligence\AnalyticalComparisonBuilder;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\MetricObservation;
use App\Services\Intelligence\PeriodParser;
use Tests\TestCase;

class ChartQuantityContractTest extends TestCase
{
    public function test_source_magnitude_is_applied_once_and_kept_separate_from_the_unit(): void
    {
        $parser = app(MeasurementParser::class);
        foreach ([
            ['1.5', 'USD', 1.5, 1.0, 'USD'],
            ['1.5', 'KES million', 1500000.0, 1e6, 'KES'],
            ['12', '%', 12.0, 1.0, '%'],
            ['0.45', '%', 0.45, 1.0, '%'],
            ['2.4', 'Thousands of people', 2400.0, 1e3, 'people'],
            ['2.4', 'Million 480-Pound Bales', 2400000.0, 1e6, '480-Pound Bales'],
            ['2.4 million', 'Million 480-Pound Bales', 2400000.0, 1e6, '480-Pound Bales'],
            ['3.2', 'Billion dollars', 3200000000.0, 1e9, 'dollars'],
            ['450', 'Metric tonnes', 450.0, 1.0, 'Metric tonnes'],
            ['450', '', 450.0, 1.0, ''],
        ] as [$value, $unit, $magnitude, $scale, $canonical]) {
            $measurement = $parser->parse($value, $unit);
            self::assertNotNull($measurement, $value.' '.$unit);
            self::assertSame($magnitude, $measurement->magnitude, $value.' '.$unit);
            self::assertSame($scale, $measurement->scale, $value.' '.$unit);
            self::assertSame($canonical, $measurement->canonicalUnit, $value.' '.$unit);
            self::assertSame($unit === '' ? '' : $unit, $measurement->unitText, $value.' '.$unit);
        }
    }

    public function test_incompatible_scales_and_ambiguous_grouping_are_rejected(): void
    {
        $parser = app(MeasurementParser::class);
        self::assertNull($parser->parse('2 million', 'USD billion'));
        self::assertNull($parser->parse('1,23,456', 'KES'));
        self::assertNull($parser->parse('12', 'USD/KES'));
        self::assertNull($parser->parse('USD 12', 'KES'));
        self::assertNull($parser->parse('12', 'm'));
        self::assertNull($parser->parse('12', 'k'));
        self::assertNull($parser->parse('12', 'm/s'));
    }

    public function test_fiscal_period_labels_keep_their_basis(): void
    {
        $periods = app(PeriodParser::class);
        self::assertSame('Q1 FY2024', $periods->parse('Q1 FY2024')?->label);
        self::assertSame('H1 FY2024', $periods->parse('H1 FY2024')?->label);
        self::assertSame('FY2024-01', $periods->parse('Jan FY2024')?->label);
    }

    public function test_a_partial_quarter_requires_one_supported_year_and_basis(): void
    {
        $periods = app(PeriodParser::class);
        self::assertNull($periods->parse('Q3'));
        self::assertSame('Q3 2026', $periods->resolveQuarter('Q3', 'Metric | Q3 2026 | KES million')?->label);
        self::assertSame('Q3 FY2026', $periods->resolveQuarter('Q3', 'Metric | Q3 FY2026 | KES million')?->label);
        self::assertNull($periods->resolveQuarter('Q3', 'Metric | Q3 | KES million'));
        self::assertNull($periods->resolveQuarter('Q3', 'Q3 2025 and Q3 2026'));
        self::assertNull($periods->resolveQuarter('Q3', 'Q2 2026'));
        self::assertNull($periods->resolveQuarter('Q3', 'Q3 2026', 'Revenue Q3 2025 51'));
    }

    public function test_parsing_source_and_unit_verification_are_independent(): void
    {
        $parser = app(MeasurementParser::class);
        $integrity = app(MeasurementIntegrity::class);
        $measure = $parser->parse('51.0', 'KES million', 'Revenue');
        self::assertSame(51000000.0, $measure->magnitude);
        $source = [['quote' => 'Q3 revenue was KES million 51.0']];
        $accepted = $integrity->assess($measure, '51.0', 'KES million',
            ['source_fields_verified' => ['value', 'unit']], $source, true);
        self::assertSame('parsed', $accepted['numericVerification']);
        self::assertSame('verified', $accepted['sourceVerification']);
        self::assertSame('verified', $accepted['unitVerification']);
        self::assertTrue($accepted['analyticalEligible']);

        $quoteOnly = $integrity->assess($measure, '51.0', 'KES million', [], $source, true);
        self::assertFalse($quoteOnly['analyticalEligible']);
        self::assertSame('unverified', $quoteOnly['sourceVerification']);

        $missingSource = $integrity->assess($measure, '51.0', 'KES million', [], [], false);
        self::assertSame('unverified', $missingSource['sourceVerification']);
        self::assertSame('unit_unverified', $missingSource['unitVerification']);
        self::assertFalse($missingSource['analyticalEligible']);

        $missingUnit = $integrity->assess($parser->parse('87', null), '87', null, [], [['quote' => 'Delivery 87']], true);
        self::assertSame('unit_unverified', $missingUnit['unitVerification']);
        self::assertFalse($missingUnit['analyticalEligible']);

        $dimensionless = $integrity->assess($parser->parse('1.2', null), '1.2', null,
            ['metric_type' => 'ratio', 'source_fields_verified' => ['value']],
            [['quote' => 'Current ratio 1.2']], true);
        self::assertSame('dimensionless', $dimensionless['unitVerification']);
        self::assertTrue($dimensionless['analyticalEligible']);

        $ambiguous = $integrity->assess(null, '12', 'm', [], [['quote' => '12 m']], true);
        self::assertSame('scale_ambiguous', $ambiguous['unitVerification']);
        self::assertFalse($ambiguous['analyticalEligible']);

        $identifier = $integrity->assess($parser->parse('123456789', 'KES'), '123456789', 'KES',
            ['metric_type' => 'identifier'], [['quote' => 'Account reference 123456789 KES']], true);
        self::assertFalse($identifier['analyticalEligible']);
        self::assertContains('non_measurement_numeric', $identifier['reasons']);
    }

    public function test_a_grounding_rejection_disqualifies_otherwise_verified_measurement(): void
    {
        $parser = app(MeasurementParser::class);
        $result = app(MeasurementIntegrity::class)->assess(
            $parser->parse('51.0', 'KES million', 'Revenue'),
            '51.0', 'KES million',
            ['source_fields_verified' => ['value', 'unit', 'period'],
                'grounding_rejection' => 'period_source_mismatch'],
            [['quote' => 'Revenue | 51.0 | KES million | Q2 2026']], true
        );

        self::assertSame('verified', $result['sourceVerification']);
        self::assertSame('verified', $result['unitVerification']);
        self::assertFalse($result['analyticalEligible']);
        self::assertContains('period_source_mismatch', $result['reasons']);
    }

    public function test_financial_reconciliation_uses_exact_decimal_values_before_chart_presentation(): void
    {
        $parser = app(MeasurementParser::class);
        self::assertSame('9007199254740993', $parser->parse('9007199254740993', 'KES')?->exactMagnitude);
        self::assertSame('51000000.0', $parser->parse('51.0', 'KES million')?->exactMagnitude);
        $period = app(PeriodParser::class)->parse('Q3 2026');
        $observation = static fn (string $concept, string $value): MetricObservation => new MetricObservation(
            'kpi:'.$concept, $concept, $value, $parser->parse($value, 'KES'), $period,
            'Northstar Distribution Ltd', 1.0, null, $concept, $concept, 3, true,
            ['analyticalEligible' => true], $concept, 'actual', 'absolute', 'total', $concept.' '.$value.' KES'
        );
        $rows = [$observation('revenue', '9007199254740993'),
            $observation('operating costs', '1'),
            $observation('operating profit', '9007199254740993')];
        $bad = app(AnalyticalComparisonBuilder::class)->build($rows);
        self::assertSame([], array_values(array_filter($bad,
            static fn (array $item): bool => $item['kind'] === 'financial_reconciliation')));

        $rows[2] = $observation('operating profit', '9007199254740992');
        $good = app(AnalyticalComparisonBuilder::class)->build($rows);
        self::assertCount(1, array_values(array_filter($good,
            static fn (array $item): bool => $item['kind'] === 'financial_reconciliation')));
    }

    public function test_chart_builder_rechecks_eligibility_even_when_given_parseable_legacy_series(): void
    {
        $parser = app(MeasurementParser::class);
        $periods = app(PeriodParser::class);
        $observations = [];
        foreach (['Q1 2026' => '42.0', 'Q2 2026' => '46.0', 'Q3 2026' => '51.0'] as $period => $value) {
            $observations[] = new MetricObservation('kpi:'.$period, 'Revenue', $value,
                $parser->parse($value, 'KES million'), $periods->parse($period), 'Northstar',
                0.9, null, 'revenue', 'revenue', 3, true,
                ['analyticalEligible' => false, 'reasons' => ['missing_source_reference']],
                'revenue', 'actual', 'absolute', 'total');
        }
        self::assertSame([], app(ChartCandidateBuilder::class)->build($observations)['candidates']);
        $verified = array_map(static fn (MetricObservation $item): MetricObservation => new MetricObservation(
            $item->sourceId, $item->label, $item->rawValue, $item->measurement, $item->period,
            $item->subject, $item->confidence, $item->definitionId, $item->conceptKey,
            $item->measureKey, $item->page, $item->isTotal,
            ['analyticalEligible' => true], $item->semanticConcept, $item->valueBasis,
            $item->quantityKind, $item->aggregation), $observations);
        $charts = app(ChartCandidateBuilder::class)->build($verified)['candidates'];
        self::assertNotEmpty($charts);
        self::assertSame([42.0, 46.0, 51.0], array_column($charts[0]['points'], 'value'));
        self::assertCount(3, $charts[0]['sourceIds']);
    }
}
