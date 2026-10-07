<?php

namespace Tests\Unit;

use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\Materiality\SignalEvaluator;
use Tests\TestCase;

class IntelligenceMaterialityTest extends TestCase
{
    private function record(string $id, string $kind, array $data = [], array $typed = [], array $source = []): array
    {
        return [
            'identity' => $id, 'source_id' => 'evidence:'.$id, 'kind' => $kind,
            'data' => $data + ['label' => $id, 'value' => $id],
            'typed' => $typed + ['value' => null, 'dates' => []],
            'sources' => [$source + ['span_id' => 'E'.$id, 'start_offset' => 10, 'end_offset' => 60, 'page' => 1,
                'quote' => $id]],
            'provenance' => ['origin' => 'document', 'assertion' => 'stated',
                'attribution' => ['role' => 'unattributed']],
            'status' => 'open', 'span_type' => null, 'span_ordinal' => null, 'section' => null, 'page' => 1,
        ];
    }

    private function context(): array
    {
        return ['span_count' => null, 'cited_source_ids' => [], 'comparable_source_ids' => []];
    }

    public function test_fixture_26_four_critical_risks_bypass_max_per_kind(): void
    {
        $records = array_map(fn ($id) => $this->record((string) $id, 'risk', ['severity' => 'critical']), range(1, 4));
        $assigned = app(MaterialityScorer::class)->assign($records, $this->context(), new \DateTimeImmutable('2026-10-07'));
        self::assertSame(3, config('intelligence_v2.tier1.per_kind'));
        self::assertCount(4, array_filter($assigned, fn ($item) => $item['tier'] === 1));
        foreach ($assigned as $item) {
            self::assertTrue($item['forced']);
            self::assertSame('critical_risk', $item['forced_rule']);
        }
    }

    public function test_historical_high_risk_keeps_kind_and_severity_but_does_not_consume_a_forced_slot(): void
    {
        $record = $this->record('past', 'risk', ['severity' => 'high'], ['dates' => [
            'observed_date' => ['resolution' => 'calendar', 'date' => '2020-01-01'],
        ]]);
        $asOf = new \DateTimeImmutable('2026-10-07');
        $assigned = app(MaterialityScorer::class)->assign([$record], $this->context(), $asOf)['past'];
        $attention = new AttentionStateBuilder(new HistoricalRiskRule, config('intelligence_v2.attention'));
        self::assertSame('risk', $record['kind']);
        self::assertSame('high', $record['data']['severity']);
        self::assertFalse($assigned['forced']);
        self::assertNull($assigned['forced_rule']);
        self::assertSame('informational', $attention->build($record, $assigned, [$record], $asOf)['state']);
        self::assertSame(['historical_context'], $attention->build($record, $assigned, [$record], $asOf)['reasons']);
    }

    public function test_historical_exception_requires_a_resolved_observed_date_and_no_same_span_open_consequence(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07');
        $risk = $this->record('past', 'risk', ['severity' => 'high'], ['dates' => [
            'observed_date' => ['resolution' => 'calendar', 'date' => '2020-01-01'],
        ]], ['span_id' => 'E1']);
        $other = $this->record('future', 'obligation', [], ['dates' => [
            'due_date' => ['resolution' => 'calendar', 'date' => '2027-01-01'],
        ]], ['span_id' => 'E1']);
        $scorer = app(MaterialityScorer::class);
        self::assertSame('high_risk', $scorer->assign([$risk, $other], $this->context(), $asOf)['past']['forced_rule']);
        unset($risk['typed']['dates']['observed_date']);
        self::assertSame('high_risk', $scorer->assign([$risk], $this->context(), $asOf)['past']['forced_rule']);
    }

    public function test_reasons_sum_to_score_and_unavailable_span_signals_are_skipped(): void
    {
        $record = $this->record('metric', 'metric', ['value' => 'USD 12 billion'], ['value' => [
            'number' => 12e9, 'unit_kind' => 'currency', 'currency' => 'USD',
        ]]);
        $assigned = app(MaterialityScorer::class)->assign([$record], $this->context(), new \DateTimeImmutable('2026-10-07'))['metric'];
        self::assertEqualsWithDelta($assigned['score'], array_sum(array_column($assigned['reasons'], 'contribution')), 1e-9);
        $reasons = collect($assigned['reasons'])->keyBy('signal');
        self::assertTrue($reasons['structural_prominence']['skipped']);
        self::assertTrue($reasons['boilerplate_penalty']['skipped']);
        self::assertSame('span_type_unavailable', $reasons['boilerplate_penalty']['reason']);
    }

    public function test_total_financing_approved_is_not_headline_forced_below_pre_forcing_tier_two(): void
    {
        $record = $this->record('financing', 'metric', ['label' => 'Total financing approved', 'value' => '11,500'],
            ['value' => ['number' => 11.5e9, 'unit_kind' => 'currency', 'currency' => 'USD']]);
        $context = $this->context();
        $context['comparable_source_ids'][$record['source_id']] = true;
        $assigned = app(MaterialityScorer::class)->assign([$record], $context, new \DateTimeImmutable('2026-10-07'))['financing'];
        self::assertSame(3, $assigned['scored_tier']);
        self::assertNull($assigned['forced_rule']);
        self::assertGreaterThanOrEqual(3, $assigned['tier']);
    }

    public function test_heading_and_early_ordinal_use_maximum_signal_value(): void
    {
        $record = $this->record('heading', 'fact');
        $record['span_type'] = 'heading';
        $record['span_ordinal'] = 1;
        $signals = app(SignalEvaluator::class)->values($record, [$record], [
            ...$this->context(), 'span_count' => 20,
        ], new \DateTimeImmutable('2026-10-07'));
        self::assertSame(1.0, $signals['structural_prominence']['value']);
        $record['span_type'] = 'sentence';
        $signals = app(SignalEvaluator::class)->values($record, [$record], [
            ...$this->context(), 'span_count' => 20,
        ], new \DateTimeImmutable('2026-10-07'));
        self::assertSame(0.5, $signals['structural_prominence']['value']);
    }

    public function test_forced_overflow_stays_in_tier_two_with_explanation(): void
    {
        $count = config('intelligence_v2.tier1.forced_max') + 1;
        $records = array_map(fn ($id) => $this->record((string) $id, 'risk', ['severity' => 'critical']), range(1, $count));
        $assigned = app(MaterialityScorer::class)->assign($records, $this->context(), new \DateTimeImmutable('2026-10-07'));
        self::assertCount(config('intelligence_v2.tier1.forced_max'), array_filter($assigned, fn ($item) => $item['forced']));
        self::assertCount(1, array_filter($assigned, fn ($item) => $item['overflow_from_forced'] && $item['tier'] === 2));
        foreach ($assigned as $item) {
            self::assertEqualsWithDelta($item['score'], array_sum(array_column($item['reasons'], 'contribution')), 1e-9);
        }
    }

    public function test_date_role_patterns_choose_nearest_and_ties_remain_unresolved(): void
    {
        $resolver = app(DateRoleResolver::class);
        $typed = ['dates' => ['due_date' => ['raw' => '31 March 2025', 'resolution' => 'calendar',
            'date' => '2025-03-31']]];
        $record = ['kind' => 'risk'];
        self::assertArrayHasKey('observed_date', $resolver->resolve($typed, $record,
            ['The event occurred on 31 March 2025.'])['dates']);
        self::assertArrayHasKey('due_date', $resolver->resolve($typed, ['kind' => 'obligation'],
            ['Payment is due by 31 March 2025.'])['dates']);
        self::assertArrayHasKey('observed_date', $resolver->resolve($typed, $record,
            ['On 31 March 2025 an event happened.'])['dates']);
    }

    public function test_date_proximity_uses_fixed_clock_boundaries_and_closed_status_zero(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07');
        $record = $this->record('due', 'obligation', [], ['dates' => ['due_date' => [
            'resolution' => 'calendar', 'date' => '2026-10-07',
        ]]]);
        $signals = app(SignalEvaluator::class);
        self::assertSame(1.0, $signals->values($record, [$record], $this->context(), $asOf)['date_proximity']['value']);
        $record['typed']['dates']['due_date']['date'] = $asOf->modify('+366 days')->format('Y-m-d');
        self::assertSame(config('intelligence_v2.materiality.signals.date_proximity.floor_value'),
            $signals->values($record, [$record], $this->context(), $asOf)['date_proximity']['value']);
        $record['status'] = 'closed';
        self::assertSame(0.0, $signals->values($record, [$record], $this->context(), $asOf)['date_proximity']['value']);
    }
}
