<?php

namespace Tests\Unit;

use App\Services\Intelligence\Brief\KeyFigureSelector;
use Tests\TestCase;

class IntelligenceKeyFigureSelectorTest extends TestCase
{
    private function metric(string $id, string $label, float $number, string $currency = 'USD',
        string $period = '2025', string $origin = 'document', int $offset = 10, int $tier = 3): array
    {
        return ['identity' => $id, 'source_id' => 'kpi:'.$id, 'kind' => 'metric',
            'data' => ['label' => $label, 'period' => $period, 'confidence' => 0.5],
            'typed' => ['value' => ['type' => 'money', 'unit_kind' => 'currency',
                'currency' => $currency, 'number' => $number], 'dates' => []],
            'provenance' => ['origin' => $origin],
            'sources' => [['start_offset' => $offset, 'end_offset' => $offset + 10, 'page' => 1]],
            'tier' => $tier];
    }

    public function test_total_label_precedes_percentile_and_exact_dedupe_keeps_tiebreak_first(): void
    {
        $records = [
            $this->metric('large', 'Revenue', 900),
            $this->metric('duplicate-late', 'Total revenue', 100, offset: 30),
            $this->metric('total', 'Total revenue', 100, offset: 20),
            $this->metric('small', 'Revenue', 50),
        ];
        $selected = app(KeyFigureSelector::class)->select($records, asOf: new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['total', 'large', 'small'], array_column($selected, 'identity'));
    }

    public function test_currency_groups_remain_separate_and_tier_confidence_do_not_control_selection(): void
    {
        $records = [
            $this->metric('usd-low', 'Revenue', 10, tier: 4),
            $this->metric('usd-high', 'Revenue', 100, tier: 3),
            $this->metric('eur-high', 'Revenue', 1000, 'EUR', tier: 4),
            $this->metric('eur-low', 'Revenue', 20, 'EUR', tier: 3),
            $this->metric('unknown', 'Total revenue', 2000, origin: 'unknown'),
        ];
        $selector = app(KeyFigureSelector::class);
        $first = $selector->select($records, asOf: new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['eur-high', 'usd-high', 'eur-low', 'usd-low'], array_column($first, 'identity'));
        foreach ($records as &$record) {
            $record['data']['confidence'] = 1 - $record['data']['confidence'];
        }
        unset($record);
        self::assertSame(array_column($first, 'identity'), array_column($selector->select($records,
            asOf: new \DateTimeImmutable('2026-10-07')), 'identity'));
    }

    public function test_six_cap_and_no_padding(): void
    {
        $records = array_map(fn ($id) => $this->metric((string) $id, 'Measure '.$id, (float) $id), range(1, 8));
        $selected = app(KeyFigureSelector::class)->select($records, asOf: new \DateTimeImmutable('2026-10-07'));
        self::assertCount(6, $selected);
        self::assertSame(['8', '7', '6', '5', '4', '3'], array_column($selected, 'identity'));
        self::assertCount(2, app(KeyFigureSelector::class)->select(array_slice($records, 0, 2),
            asOf: new \DateTimeImmutable('2026-10-07')));
    }

    public function test_only_finite_document_origin_currency_metrics_are_eligible(): void
    {
        $valid = $this->metric('valid', 'Revenue', 100);
        $withoutCurrency = $this->metric('no-currency', 'Revenue', 200);
        $withoutCurrency['typed']['value']['currency'] = null;
        $percent = $this->metric('percent', 'Margin', 30);
        $percent['typed']['value']['type'] = 'percent';
        $percent['typed']['value']['unit_kind'] = 'percent';
        $infinite = $this->metric('infinite', 'Revenue', INF);
        $selected = app(KeyFigureSelector::class)->select([$valid, $withoutCurrency, $percent, $infinite],
            asOf: new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['valid'], array_column($selected, 'identity'));
    }
}
