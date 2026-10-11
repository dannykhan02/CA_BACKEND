<?php

namespace Tests\Unit;

use App\Services\Intelligence\MatchingTextNormalizer;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\SemanticCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntelligenceSemanticCompatibilityTest extends TestCase
{
    private function value(string $raw, ?string $unit = null, ?string $currency = null): array
    {
        return ['raw' => $raw, 'unit' => $unit, 'currency' => $currency, 'number' => 1,
            'unit_kind' => 'count'];
    }

    #[DataProvider('compatibleCases')]
    public function test_explicit_compatible_semantics(string $claim, string $source,
        ?string $unit = null, ?string $currency = null): void
    {
        self::assertSame(['ok' => true, 'reason' => 'compatible'],
            app(SemanticCompatibility::class)->compare($claim, $this->value($source, $unit, $currency)));
    }

    public static function compatibleCases(): array
    {
        return [
            'same percent' => ['5%', '5%', 'percent'],
            'same points' => ['5 percentage points', '5', 'percentage points'],
            'same basis points' => ['50 basis points', '50', 'basis points'],
            'USD local alias' => ['US dollars 12.4 billion', '$12.4 billion', 'USD', '$'],
            'scale conversion' => ['12,400 million', '12.4 billion', 'USD', 'USD'],
            'display zero' => ['12.40 billion', '12.4 billion', 'USD', 'USD'],
            'small exact decimal' => ['0.000010', '0.00001'],
            'singular people' => ['5 person', '5 people', 'people'],
            'litre spelling' => ['5 liters', '5 litres', 'litres'],
            'money rate' => ['USD 20 per employee', 'USD 20', 'USD per employee', 'USD'],
            'same bound' => ['more than 10 people', 'over 10 people', 'people'],
            'same approximation' => ['about 10 people', 'approximately 10 people', 'people'],
            'tilde approximation' => ['~10 people', 'about 10 people', 'people'],
        ];
    }

    #[DataProvider('rejectedCases')]
    public function test_semantic_mismatch_has_a_reason(string $claim, string $source,
        ?string $unit, ?string $currency, string $reason): void
    {
        self::assertSame($reason, app(SemanticCompatibility::class)
            ->compare($claim, $this->value($source, $unit, $currency))['reason']);
    }

    public static function rejectedCases(): array
    {
        return [
            'percent to points' => ['15.5 percentage points', '15.5%', 'percent', null, 'unit_mismatch'],
            'percent to basis' => ['50%', '50 basis points', 'basis points', null, 'unit_mismatch'],
            'points to percent' => ['5%', '5', 'percentage points', null, 'unit_mismatch'],
            'points to basis implicit' => ['500 basis points', '5', 'percentage points', null, 'magnitude_mismatch'],
            'wrong currency' => ['KES 12.4 billion', 'USD 12.4 billion', 'USD', 'USD', 'currency_mismatch'],
            'unresolved currency' => ['USD 12.4 billion', '$12.4 billion', null, '$', 'currency_mismatch'],
            'scale mismatch' => ['12.4 million', '12.4 billion', 'USD', 'USD', 'magnitude_mismatch'],
            'precision changed' => ['12.4001 billion', '12.4 billion', 'USD', 'USD', 'magnitude_mismatch'],
            'small decimal changed' => ['0.1', '0.00001', null, null, 'magnitude_mismatch'],
            'employees to households' => ['60000 households', '60000 employees', 'employees', null, 'unit_mismatch'],
            'beneficiaries to toilets' => ['12000 toilets', '12000 beneficiaries', 'beneficiaries', null, 'unit_mismatch'],
            'litres to tonnes' => ['5 tonnes', '5 litres', 'litres', null, 'unit_mismatch'],
            'duration to people' => ['30 people', '30 days', 'days', null, 'unit_mismatch'],
            'duration schema' => ['30 days', '30 days', 'days', null, 'schema_required'],
            'rate to total' => ['100 tonnes', '100', 'tonnes per year', null, 'unit_mismatch'],
            'rate to years' => ['100 years', '100', 'tonnes per year', null, 'unit_mismatch'],
            'slash rate to total' => ['100 tonnes', '100', 'tonnes/year', null, 'unit_mismatch'],
            'money rate to total' => ['USD 20', 'USD 20', 'USD per employee', 'USD', 'unit_mismatch'],
            'negative to positive' => ['5%', '-5%', 'percent', null, 'magnitude_mismatch'],
            'bound to exact' => ['10 people', 'at least 10 people', 'people', null, 'qualifier_mismatch'],
            'approximate to exact' => ['10 people', 'approximately 10 people', 'people', null, 'qualifier_mismatch'],
            'tilde to exact' => ['10 people', '~10 people', 'people', null, 'qualifier_mismatch'],
            'strict vs inclusive' => ['at most 10 people', 'less than 10 people', 'people', null, 'qualifier_mismatch'],
            'range unparsed' => ['10 people', '10–12 people', 'people', null, 'unparsed_quantity'],
        ];
    }

    public function test_point_conversion_requires_explicit_authorization(): void
    {
        $value = $this->value('5', 'percentage points');
        self::assertSame('magnitude_mismatch', app(SemanticCompatibility::class)
            ->compare('500 basis points', $value)['reason']);
        self::assertTrue(app(SemanticCompatibility::class)
            ->compare('500 basis points', $value, allowPointConversion: true)['ok']);
    }

    public function test_direction_and_matching_normalization_are_bounded(): void
    {
        $value = $this->value('3 percentage points', 'percentage points');
        $value['label'] = 'Operating margin decreased';
        self::assertSame('direction_mismatch', app(SemanticCompatibility::class)
            ->compare('3 percentage points', $value, 'Operating margin increased by 3 percentage points')['reason']);
        $normalizer = app(MatchingTextNormalizer::class);
        self::assertSame('office - 202S USD', $normalizer->normalize('oﬃce – 202S USD'));
        self::assertSame('USO', $normalizer->normalize('USO'));
    }

    public function test_conflicting_percent_value_and_point_unit_does_not_type(): void
    {
        self::assertNull(app(MeasurementParser::class)->parse('5%', 'percentage points'));
        self::assertNull(app(MeasurementParser::class)->parse('5%', 'basis points'));
    }
}
