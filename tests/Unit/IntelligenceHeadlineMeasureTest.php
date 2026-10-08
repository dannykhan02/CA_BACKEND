<?php

namespace Tests\Unit;

use App\Services\AnthropicClient;
use App\Services\Intelligence\Materiality\ForcedItemRules;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\Values\ValueParser;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligenceHeadlineMeasureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
    }

    private function asOf(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-07T00:00:00+00:00');
    }

    private function metric(string $id, int|float $number, ?string $currency = 'USD',
        int $offset = 10, float $confidence = 0.9): array
    {
        return ['identity' => $id, 'source_id' => 'metric:'.$id, 'kind' => 'metric',
            'data' => ['label' => 'Measure '.$id, 'value' => (string) $number, 'confidence' => $confidence],
            'typed' => ['value' => ['unit_kind' => 'currency', 'currency' => $currency, 'number' => $number],
                'dates' => []],
            'sources' => [['span_id' => 'E'.$id, 'start_offset' => $offset,
                'end_offset' => $offset + 20, 'page' => 1, 'quote' => (string) $number]],
            'status' => null, 'provenance' => ['origin' => 'document', 'assertion' => 'stated',
                'attribution' => ['role' => 'unattributed']]];
    }

    private function context(array $cited = []): array
    {
        return ['span_count' => null, 'cited_source_ids' => array_fill_keys($cited, true),
            'comparable_source_ids' => []];
    }

    public function test_largest_currency_metric_is_forced_without_a_chart_group_when_pre_forcing_tier_is_two(): void
    {
        $large = $this->metric('large', 12e9);
        $small = $this->metric('small', 9e9);
        $assigned = app(MaterialityScorer::class)->assign([$large, $small],
            $this->context([$large['source_id']]), $this->asOf());
        self::assertSame(2, $assigned['large']['scored_tier']);
        self::assertSame('headline_measure', $assigned['large']['forced_rule']);
        self::assertSame(1, $assigned['large']['tier']);
        self::assertNull($assigned['small']['forced_rule']);
        self::assertNotContains('comparability', array_column($assigned['large']['reasons'], 'signal'));
        Http::assertNothingSent();
    }

    public function test_single_metric_group_and_tier_three_largest_do_not_fire(): void
    {
        $large = $this->metric('large', 12e9);
        $single = app(MaterialityScorer::class)->assign([$large],
            $this->context([$large['source_id']]), $this->asOf());
        self::assertSame(2, $single['large']['scored_tier']);
        self::assertNull($single['large']['forced_rule']);

        $small = $this->metric('small', 9e9);
        $uncited = app(MaterialityScorer::class)->assign([$large, $small], $this->context(), $this->asOf());
        self::assertSame(3, $uncited['large']['scored_tier']);
        self::assertNull($uncited['large']['forced_rule']);
        Http::assertNothingSent();
    }

    public function test_currency_groups_do_not_mix_and_null_currency_is_excluded(): void
    {
        $usd = $this->metric('usd', 12e9, 'USD');
        $usdSmall = $this->metric('usd-small', 4e9, 'USD');
        $eur = $this->metric('eur', 100e9, 'EUR');
        $unknown = $this->metric('unknown', 500e9, null);
        $assigned = app(MaterialityScorer::class)->assign([$eur, $unknown, $usdSmall, $usd],
            $this->context([$usd['source_id'], $eur['source_id'], $unknown['source_id']]), $this->asOf());
        self::assertSame('headline_measure', $assigned['usd']['forced_rule']);
        self::assertNull($assigned['eur']['forced_rule']);
        self::assertNull($assigned['unknown']['forced_rule']);
        Http::assertNothingSent();
    }

    public function test_scale_applied_canonical_magnitude_beats_a_larger_raw_number(): void
    {
        $parser = app(ValueParser::class);
        $billion = $this->metric('billion', 0);
        $million = $this->metric('million', 0);
        $billion['typed'] = $parser->parse(['kind' => 'metric', 'label' => 'Financing',
            'value' => '12.4', 'unit' => 'USD billion'], ['Financing was USD 12.4 billion.']);
        $million['typed'] = $parser->parse(['kind' => 'metric', 'label' => 'Financing',
            'value' => '950', 'unit' => 'USD million'], ['Financing was USD 950 million.']);
        self::assertSame(12.4e9, $billion['typed']['value']['number']);
        self::assertSame(950e6, $million['typed']['value']['number']);
        $assigned = app(MaterialityScorer::class)->assign([$million, $billion],
            $this->context([$billion['source_id']]), $this->asOf());
        self::assertSame('headline_measure', $assigned['billion']['forced_rule']);
        self::assertNull($assigned['million']['forced_rule']);
        Http::assertNothingSent();
    }

    public function test_exact_magnitude_tie_uses_section_nine_five_and_ignores_confidence(): void
    {
        $later = $this->metric('later', -12e9, 'USD', 100, 0.99);
        $earlier = $this->metric('earlier', 12e9, 'USD', 10, 0.01);
        foreach ([[$later, $earlier], [
            $this->metric('later', -12e9, 'USD', 100, 0.01),
            $this->metric('earlier', 12e9, 'USD', 10, 0.99),
        ]] as $records) {
            $assigned = app(MaterialityScorer::class)->assign($records,
                $this->context(array_column($records, 'source_id')), $this->asOf());
            self::assertSame('headline_measure', $assigned['earlier']['forced_rule']);
            self::assertNull($assigned['later']['forced_rule']);
        }
        Http::assertNothingSent();
    }

    public function test_nonfinite_and_string_numbers_do_not_make_a_valid_group(): void
    {
        $valid = $this->metric('valid', 12e9);
        foreach ([INF, '20'] as $badNumber) {
            $bad = $this->metric('bad', 0);
            $bad['typed']['value']['number'] = $badNumber;
            $rule = app(ForcedItemRules::class)->first($valid, [$valid, $bad],
                ['valid' => ['scored_tier' => 2]], $this->context(), $this->asOf());
            self::assertNull($rule);
        }
        Http::assertNothingSent();
    }
}
