<?php

namespace Tests\Unit;

use App\Models\DocumentEvidence;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\ImportantFindingsBuilder;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\Materiality\SignalEvaluator;
use App\Services\Intelligence\ProvenanceProjector;
use App\Services\Intelligence\Values\ValueParser;
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

    public function test_synthetic_adb_known_commitments_are_labelled_and_keep_provenance_limits(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/intelligence-v2/synthetic-adb-known-commitments.json')),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('SYNTHETIC', $fixture['fixture_type']);
        self::assertCount(4, $fixture['unavailable_without_a_real_fixture']);
        $models = [];
        $records = [];
        foreach ($fixture['records'] as $item) {
            $data = $item + ['quote' => $fixture['source_line'], 'unit' => null, 'subject' => ''];
            $model = new DocumentEvidence(['identity' => $item['identity'], 'kind' => $item['kind'],
                'source_id' => $item['source_id'], 'data' => $data,
                'sources' => [['quote' => $fixture['source_line'], 'span_id' => 'E001',
                    'start_offset' => 0, 'end_offset' => mb_strlen($fixture['source_line']), 'page' => null]]]);
            $typed = app(ValueParser::class)->parse($data, [$fixture['source_line']]);
            $provenance = app(ProvenanceProjector::class)->project($model);
            $records[] = ['identity' => $item['identity'], 'source_id' => $item['source_id'],
                'kind' => $item['kind'], 'data' => $data, 'typed' => $typed,
                'provenance' => $provenance, 'sources' => $model->sources,
                'status' => null, 'span_type' => null, 'span_ordinal' => null, 'section' => null, 'page' => null];
            $models[] = $model;
        }
        self::assertSame(['document', 'document'], array_column(array_column($records, 'provenance'), 'origin'));
        $assigned = app(MaterialityScorer::class)->assign($records, $this->context(), new \DateTimeImmutable('2026-10-07'));
        config(['intelligence_v2.enabled' => true]);
        $important = app(ImportantFindingsBuilder::class)->build(collect($models), null, [], $assigned);
        self::assertSame(['USD 12.4 billion', 'USD 10.1 billion'], array_column($important, 'value'));
        self::assertCount(0, array_filter($assigned, fn ($item) => $item['tier'] === 1));
        self::assertSame([null, null], array_column(array_values($assigned), 'forced_rule'));
        foreach ($assigned as $item) {
            self::assertEqualsWithDelta($item['score'], array_sum(array_column($item['reasons'], 'contribution')), 1e-9);
        }
    }

    public function test_normal_tier_one_admission_obeys_stem_cap_without_padding(): void
    {
        $records = array_map(fn ($id) => $this->record((string) $id, 'fact', [
            'label' => 'Operating note '.$id, 'value' => 'Detail '.$id,
        ]), range(1, 5));
        $context = $this->context();
        foreach ($records as $record) {
            $context['cited_source_ids'][$record['source_id']] = true;
        }
        $assigned = app(MaterialityScorer::class)->assign($records, $context, new \DateTimeImmutable('2026-10-07'));
        self::assertCount(config('intelligence_v2.tier1.per_stem'), array_filter($assigned, fn ($item) => $item['tier'] === 1));
        self::assertCount(0, array_filter($assigned, fn ($item) => $item['forced']));
    }

    public function test_non_currency_metric_cannot_trigger_headline_measure(): void
    {
        $record = $this->record('headcount', 'metric', ['label' => 'Employees', 'value' => '1,310'],
            ['value' => ['number' => 1310, 'unit_kind' => 'count', 'currency' => null]]);
        $context = $this->context();
        $context['comparable_source_ids'][$record['source_id']] = true;
        $assigned = app(MaterialityScorer::class)->assign([$record], $context, new \DateTimeImmutable('2026-10-07'))['headcount'];
        self::assertNull($assigned['forced_rule']);
        self::assertFalse($assigned['forced']);
    }

    public function test_signed_penalties_and_clamp_reasons_reconcile_without_rounding(): void
    {
        $first = $this->record('a', 'fact', ['label' => 'Repeated item', 'value' => 'Same value']);
        $second = $this->record('b', 'fact', ['label' => 'Repeated item', 'value' => 'Same value'], [],
            ['start_offset' => 100]);
        $unresolved = $this->record('u', 'unresolved');
        $assigned = app(MaterialityScorer::class)->assign([$first, $second, $unresolved], $this->context(),
            new \DateTimeImmutable('2026-10-07'));
        $secondReasons = collect($assigned['b']['reasons'])->keyBy('signal');
        self::assertLessThan(0, $secondReasons['repetition_penalty']['contribution']);
        $unresolvedReasons = collect($assigned['u']['reasons'])->keyBy('signal');
        self::assertLessThan(0, $unresolvedReasons['unresolved_penalty']['contribution']);

        $weights = config('intelligence_v2.materiality.weights');
        config(['intelligence_v2.materiality.weights' => [...$weights, 'severity' => 1.0]]);
        $critical = $this->record('critical', 'risk', ['severity' => 'critical']);
        $clamped = app(MaterialityScorer::class)->assign([$critical], $this->context(),
            new \DateTimeImmutable('2026-10-07'))['critical'];
        self::assertSame(1.0, $clamped['score']);
        self::assertLessThan(0, collect($clamped['reasons'])->firstWhere('signal', 'clamp')['contribution']);
        self::assertEqualsWithDelta($clamped['score'], array_sum(array_column($clamped['reasons'], 'contribution')), 1e-9);
    }

    public function test_signal_values_cover_authority_resolution_magnitude_and_comparability_boundaries(): void
    {
        $a = $this->record('a', 'metric', ['severity' => 'medium'], ['value' => [
            'number' => 100.0, 'unit_kind' => 'currency', 'currency' => 'USD',
        ], 'dates' => ['period_covered' => ['resolution' => 'period', 'period' => [
            'anchored' => false, 'end' => null,
        ]]]]);
        $b = $this->record('b', 'metric', [], ['value' => [
            'number' => 200.0, 'unit_kind' => 'currency', 'currency' => 'USD',
        ]]);
        $c = $this->record('c', 'metric', [], ['value' => [
            'number' => 300.0, 'unit_kind' => 'currency', 'currency' => 'USD',
        ]]);
        $a['provenance']['attribution']['role'] = 'counterparty';
        $context = $this->context();
        $context['comparable_source_ids'][$b['source_id']] = true;
        $signals = app(SignalEvaluator::class);
        $values = $signals->values($b, [$a, $b, $c], $context, new \DateTimeImmutable('2026-10-07'));
        self::assertSame(0.5, $values['monetary_magnitude']['value']);
        self::assertEqualsWithDelta(2 / 3, $values['relative_magnitude']['value'], 1e-12);
        self::assertSame(1.0, $values['comparability']['value']);
        $values = $signals->values($a, [$a, $b, $c], $context, new \DateTimeImmutable('2026-10-07'));
        self::assertSame(0.35, $values['severity']['value']);
        self::assertSame(0.6, $values['date_resolution']['value']);
        self::assertSame(0.6, $values['attribution_authority']['value']);
        self::assertSame(0.0, $values['date_proximity']['value']);
        self::assertSame(0.0, $values['comparability']['value']);
        $zero = $this->record('zero', 'metric', [], ['value' => [
            'number' => 0.0, 'unit_kind' => 'count', 'currency' => null,
        ]]);
        self::assertSame(0.0, $signals->values($zero, [$zero], $context,
            new \DateTimeImmutable('2026-10-07'))['relative_magnitude']['value']);
    }

    public function test_penalty_consequence_requires_valueparser_money_from_the_same_quote(): void
    {
        $record = $this->record('penalty', 'obligation', ['value' => 'USD 100', 'unit' => null], [],
            ['quote' => 'A penalty of USD 100 applies.']);
        $signals = app(SignalEvaluator::class);
        self::assertSame(1.0, $signals->values($record, [$record], $this->context(),
            new \DateTimeImmutable('2026-10-07'))['obligation_consequence']['value']);
        $record['sources'][0]['quote'] = 'A penalty applies.';
        self::assertSame(0.0, $signals->values($record, [$record], $this->context(),
            new \DateTimeImmutable('2026-10-07'))['obligation_consequence']['value']);
    }

    public function test_imminent_overdue_regulator_and_unresolved_neighbour_forced_rules(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07');
        $due = $this->record('due', 'obligation', ['date_type' => 'explicit',
            'due_date' => $asOf->modify('+90 days')->format('Y-m-d')], ['dates' => ['due_date' => [
                'resolution' => 'calendar', 'date' => $asOf->modify('+90 days')->format('Y-m-d'),
            ]]]);
        $scorer = app(MaterialityScorer::class);
        self::assertSame('imminent_dated_obligation', $scorer->assign([$due], $this->context(), $asOf)['due']['forced_rule']);
        $due['data']['due_date'] = $asOf->modify('-1 day')->format('Y-m-d');
        $due['typed']['dates']['due_date']['date'] = $due['data']['due_date'];
        self::assertSame('overdue_dated_obligation', $scorer->assign([$due], $this->context(), $asOf)['due']['forced_rule']);
        $regulator = $this->record('regulator', 'fact');
        $regulator['provenance']['attribution']['role'] = 'regulator';
        self::assertSame('regulator_attributed', $scorer->assign([$regulator], $this->context(), $asOf)['regulator']['forced_rule']);
        $unresolved = $this->record('unresolved', 'unresolved');
        $neighbour = $this->record('neighbour', 'fact');
        $context = $this->context();
        $context['cited_source_ids'][$neighbour['source_id']] = true;
        self::assertSame('unresolved_material_reference', $scorer->assign([$unresolved, $neighbour],
            $context, $asOf)['unresolved']['forced_rule']);
    }

    public function test_tiebreak_uses_page_end_offset_label_and_identity_without_confidence(): void
    {
        $a = $this->record('a', 'fact', ['label' => 'Beta', 'confidence' => 0.99], [],
            ['start_offset' => 10, 'end_offset' => 50, 'page' => 2]);
        $b = $this->record('b', 'fact', ['label' => 'Alpha', 'confidence' => 0.01], [],
            ['start_offset' => 10, 'end_offset' => 50, 'page' => 3]);
        self::assertLessThan(0, MaterialityScorer::compareTiebreak($a, $b));
        $b['sources'][0]['page'] = 2;
        $b['sources'][0]['end_offset'] = 60;
        self::assertLessThan(0, MaterialityScorer::compareTiebreak($a, $b));
        $b['sources'][0]['end_offset'] = 50;
        self::assertGreaterThan(0, MaterialityScorer::compareTiebreak($a, $b));
    }
}
