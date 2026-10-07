<?php

namespace Tests\Unit;

use App\Services\Intelligence\Values\ValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntelligenceValueFormatterTest extends TestCase
{
    public static function values(): array
    {
        return [
            'currency with source scale' => ['$24.3 billion', 'USD billions', '$24.3 billion'],
            'currency and stated unit' => ['24.3', 'USD billion', 'USD 24.3 billion'],
            'percent sign already present' => ['50%', 'percent', '50%'],
            'percent unit' => ['50', 'percent', '50%'],
            'quantity' => ['1,350', 'employees', '1,350 employees'],
            'quantity already named' => ['1,350 employees', 'employees', '1,350 employees'],
            'ratio' => ['3.2', 'ratio', '3.2 ratio'],
            'scale already stated' => ['24.3 million', 'million', '24.3 million'],
            'range' => ['10–12', 'USD million', 'USD 10–12 million'],
            'date' => ['31 March 2025', null, '31 March 2025'],
            'null unit' => ['not disclosed', null, 'not disclosed'],
            'unknown unit' => ['not disclosed', 'unknown', 'not disclosed'],
        ];
    }

    #[DataProvider('values')]
    public function test_raw_values_never_duplicate_units(string $raw, ?string $unit, string $expected): void
    {
        self::assertSame($expected, (new ValueFormatter)->raw($raw, $unit));
    }

    public function test_normalized_numbers_keep_integer_zeroes_and_format_percent_once(): void
    {
        $formatter = new ValueFormatter;
        self::assertSame('USD 100 billion', $formatter->number(100, 'USD billion'));
        self::assertSame('50%', $formatter->number(50, '%'));
        self::assertSame('1,350 employees', $formatter->number(1350, 'employees'));
        self::assertSame('2.5 ratio', $formatter->number(2.5, 'ratio'));
    }
}
