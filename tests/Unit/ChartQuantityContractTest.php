<?php

namespace Tests\Unit;

use App\Services\Intelligence\MeasurementParser;
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
}
