<?php

namespace Tests\Unit;

use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PeriodParser;
use App\Services\Kpis\KpiLabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two deterministic readers the chart layer is built on. Both are biased towards rejection:
 * a value or a period that cannot be read without guessing is simply not chartable.
 */
class IntelligenceNormalizationTest extends TestCase
{
    private MeasurementParser $measurements;

    private PeriodParser $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->measurements = new MeasurementParser(new KpiLabelNormalizer);
        $this->periods = new PeriodParser;
    }

    public static function measurements(): array
    {
        return [
            'written scale beside the number' => ['12.4', 'USD billion', 12.4e9, 'currency', 'USD', 'USD'],
            'written scale inside the unit' => ['9,800', 'USD million', 9.8e9, 'currency', 'USD', 'USD'],
            'currency inside the value' => ['USD 12.4 billion', null, 12.4e9, 'currency', 'USD', 'USD'],
            'percent sign' => ['31%', null, 31.0, 'percent', null, 'percent'],
            'percent unit' => ['31', 'percentage', 31.0, 'percent', null, 'percent'],
            'percentage points stay their own measure' => ['2.1', 'percentage points', 2.1, 'change', null, 'percentage points'],
            'accounting negative' => ['(1,234)', 'USD million', -1.234e9, 'currency', 'USD', 'USD'],
            'counted noun' => ['1,350', 'employees', 1350.0, 'count', null, 'employees'],
            'duration' => ['48', 'hours', 48.0, 'duration', null, 'hours'],
            'ratio' => ['3.2', 'ratio', 3.2, 'ratio', null, 'ratio'],
            'another currency is another family' => ['4.5', 'KES billion', 4.5e9, 'currency', 'KES', 'KES'],
            'a ratio denominator is part of the family' => ['2.40', 'USD per share', 2.4, 'currency', 'USD', 'USD per share'],
            'unitless figure' => ['1,234', null, 1234.0, 'unknown', null, ''],
        ];
    }

    #[DataProvider('measurements')]
    public function test_a_readable_value_becomes_a_measurement(?string $value, ?string $unit, float $magnitude,
        string $kind, ?string $currency, string $family): void
    {
        $measurement = $this->measurements->parse($value, $unit);

        $this->assertNotNull($measurement, 'value should have been readable');
        $this->assertEqualsWithDelta($magnitude, $measurement->magnitude, abs($magnitude) * 1e-9 + 1e-9);
        $this->assertSame([$kind, $currency, $family], [$measurement->kind, $measurement->currency, $measurement->family]);
    }

    public static function unreadable(): array
    {
        return [
            'no number' => ['not disclosed', 'USD billion'],
            'empty' => ['', 'USD'],
            'a second number makes it ambiguous' => ['USD 12.4bn (2023: 10.7bn)', null],
            'a comparison is two values' => ['10 of 20', null],
            'a comma used as a decimal mark' => ['12,5', 'USD'],
            'a page number is not a figure' => ['42', 'pages'],
            'a clause number is not a figure' => ['7', 'clause'],
            'a bare year is a date' => ['2024', null],
            'two measurements in one string' => ['12% of USD 4bn', null],
            'contradicting scales' => ['12.4 million', 'USD billion'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_an_ambiguous_value_is_rejected(?string $value, ?string $unit): void
    {
        $this->assertNull($this->measurements->parse($value, $unit));
    }

    public function test_a_bare_dollar_sign_is_not_assumed_to_be_any_particular_currency(): void
    {
        $dollar = $this->measurements->parse('$10m', null);
        $usd = $this->measurements->parse('USD 10 million', null);

        $this->assertSame('$', $dollar->currency);
        $this->assertSame('USD', $usd->currency);
        $this->assertFalse($dollar->compatibleWith($usd), 'an unattributed dollar must not join a USD series');
    }

    public function test_one_currency_at_two_scales_is_one_measurement(): void
    {
        $millions = $this->measurements->parse('9,800', 'USD million');
        $billions = $this->measurements->parse('12.4', 'USD billion');

        $this->assertTrue($millions->compatibleWith($billions));
        $this->assertSame([9.8e9, 12.4e9], [$millions->magnitude, $billions->magnitude]);
    }

    public function test_unlike_measurements_are_never_compatible(): void
    {
        $pairs = [
            [['4.2', 'USD billion'], ['63', '%']],
            [['4.2', 'USD billion'], ['1,240', 'employees']],
            [['4.2', 'USD billion'], ['4.2', 'KES billion']],
            [['2.40', 'USD per share'], ['2.40', 'USD per employee']],
            [['48', 'hours'], ['48', 'days']],
        ];
        foreach ($pairs as [$left, $right]) {
            $a = $this->measurements->parse(...$left);
            $b = $this->measurements->parse(...$right);
            $this->assertNotNull($a);
            $this->assertNotNull($b);
            $this->assertFalse($a->compatibleWith($b), implode(' ', $left).' vs '.implode(' ', $right));
        }
    }

    public static function periods(): array
    {
        return [
            ['2024', 'year', 'calendar', '2024'],
            ['FY2024', 'year', 'fiscal', 'FY2024'],
            ['FY 2025', 'year', 'fiscal', 'FY2025'],
            ['FY2024/25', 'year', 'fiscal', 'FY2024/25'],
            ['2024/25', 'year', 'fiscal', 'FY2024/25'],
            ['Q3 2024', 'quarter', 'calendar', 'Q3 2024'],
            ['Q3 FY25', 'quarter', 'fiscal', 'Q3 2025'],
            ['2024 Q1', 'quarter', 'calendar', 'Q1 2024'],
            ['second quarter of 2025', 'quarter', 'calendar', 'Q2 2025'],
            ['H1 2024', 'half', 'calendar', 'H1 2024'],
            ['March 2024', 'month', 'calendar', '2024-03'],
            ['2024-03', 'month', 'calendar', '2024-03'],
            ['31 March 2024', 'day', 'calendar', '2024-03-31'],
            ['as at 30 June 2024', 'day', 'calendar', '2024-06-30'],
        ];
    }

    #[DataProvider('periods')]
    public function test_a_recognisable_period_is_read(string $text, string $granularity, string $basis, string $label): void
    {
        $period = $this->periods->parse($text);

        $this->assertNotNull($period, $text);
        $this->assertSame([$granularity, $basis, $label], [$period->granularity, $period->basis, $period->label]);
    }

    public function test_periods_are_ordered_chronologically(): void
    {
        $keys = array_map(fn ($text) => $this->periods->parse($text)->sortKey,
            ['2022', '2023', '2024']);
        $this->assertSame($keys, [2022.0, 2023.0, 2024.0]);

        $quarters = array_map(fn ($text) => $this->periods->parse($text)->sortKey,
            ['Q1 2024', 'Q2 2024', 'Q4 2024']);
        $this->assertTrue($quarters[0] < $quarters[1] && $quarters[1] < $quarters[2]);
    }

    public function test_an_unreadable_period_is_rejected(): void
    {
        foreach (['', 'next year', 'the reporting period', '2023-2024', 'Q5 2024', '2024/26', '31 February 2024'] as $text) {
            $this->assertNull($this->periods->parse($text), $text);
        }
    }

    public function test_a_quarter_is_not_comparable_with_a_year_or_with_a_fiscal_year(): void
    {
        $year = $this->periods->parse('2024');
        $quarter = $this->periods->parse('Q1 2024');
        $fiscal = $this->periods->parse('FY2024');

        $this->assertFalse($year->comparableWith($quarter));
        $this->assertFalse($year->comparableWith($fiscal));
        $this->assertTrue($year->comparableWith($this->periods->parse('2023')));
    }
}
