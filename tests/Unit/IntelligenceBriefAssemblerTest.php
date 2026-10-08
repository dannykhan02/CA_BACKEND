<?php

namespace Tests\Unit;

use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\Intelligence\Brief\BriefVerifier;
use Tests\TestCase;

class IntelligenceBriefAssemblerTest extends TestCase
{
    private function record(string $id, string $kind, string $label, array $typed = [],
        string $origin = 'document', ?string $status = 'open'): array
    {
        return ['identity' => $id, 'source_id' => $kind.':'.$id, 'record_id' => 'row-'.$id,
            'kind' => $kind, 'data' => ['label' => $label, 'value' => $label],
            'typed' => $typed + ['value' => null, 'dates' => []],
            'provenance' => ['origin' => $origin, 'assertion' => $origin === 'document' ? 'stated' : 'unspecified',
                'attribution' => ['speaker' => null, 'role' => 'unattributed', 'reported' => false, 'evidence_ref' => null]],
            'status' => $status, 'sources' => [['chunk_id' => 'chunk-1', 'span_id' => 'E'.$id, 'start_offset' => 0,
                'end_offset' => 40, 'quote' => $label, 'page' => 2]],
            'span_type' => null, 'span_ordinal' => null, 'section' => null, 'page' => 2];
    }

    private function assignment(int $tier, ?string $rule = null): array
    {
        return ['tier' => $tier, 'scored_tier' => $tier, 'score' => $tier === 1 ? 0.9 : 0.36,
            'forced' => $rule !== null, 'forced_rule' => $rule,
            'forced_priority' => $rule === null ? 999 : config('intelligence_v2.materiality.forced_priorities')[$rule]];
    }

    public function test_blocks_follow_order_and_grounded_templates_with_fixed_clock(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07T00:00:00+00:00');
        $risk = $this->record('risk', 'risk', 'Critical exposure');
        $risk['data']['severity'] = 'critical';
        $overdue = $this->record('overdue', 'obligation', 'Covenant review', ['dates' => ['due_date' => [
            'type' => 'date', 'raw' => '30 September 2026', 'resolution' => 'calendar', 'date' => '2026-09-30']]]);
        $period = $this->record('period', 'obligation', 'Facility review', ['dates' => ['due_date' => [
            'type' => 'period', 'raw' => 'FY2027', 'resolution' => 'period',
            'period' => ['text' => 'FY2027', 'anchored' => false, 'end' => null]]]]);
        $metric = $this->record('metric', 'metric', 'Total financing', ['value' => [
            'type' => 'money', 'raw' => 'USD 12.4 billion', 'number' => 12.4e9,
            'unit' => 'USD billion', 'unit_kind' => 'currency', 'currency' => 'USD',
            'scale' => 1e9, 'precision' => 'exact',
        ], 'dates' => ['period_covered' => ['resolution' => 'period',
            'period' => ['text' => 'FY2025']]]]);
        $overdue['sources'][0]['quote'] = 'Covenant review was due 30 September 2026.';
        $period['sources'][0]['quote'] = 'Facility review is due in FY2027.';
        $metric['sources'][0]['quote'] = 'Total financing: USD 12.4 billion (FY2025).';
        $records = [$metric, $period, $risk, $overdue];
        $assignments = ['risk' => $this->assignment(1, 'critical_risk'),
            'overdue' => $this->assignment(1, 'overdue_dated_obligation'),
            'period' => $this->assignment(2), 'metric' => $this->assignment(4)];
        $brief = app(BriefAssembler::class)->assemble('Annual report.pdf', 'PDF', $records, $assignments,
            ['state' => 'partial', 'reasons' => ['processing_partial'], 'tier1_truncated' => false], $asOf);
        self::assertFalse($brief['ai_blocks_available']);
        self::assertSame(['headline', 'attention', 'attention', 'timeline', 'timeline', 'measure', 'coverage_note'],
            array_column($brief['blocks'], 'type'));
        self::assertSame(['headline.document_identity', 'attention.critical_risk', 'attention.overdue',
            'timeline.calendar_due', 'timeline.period_due', 'measure.period_value', 'coverage_note.partial'],
            array_column($brief['blocks'], 'template_id'));
        self::assertSame('Covenant review was due 30 September 2026 and is still open.', $brief['blocks'][2]['text']);
        self::assertSame('Facility review — due in FY2027', $brief['blocks'][4]['text']);
        self::assertSame('Total financing: USD 12.4 billion (FY2025)', $brief['blocks'][5]['text']);
        self::assertSame(12.4e9, $brief['blocks'][5]['typed']['value']['number']);
        self::assertSame('FY2025', $brief['blocks'][5]['typed']['period_covered']['period']['text']);
        self::assertSame([], $brief['blocks'][0]['cites']);
        self::assertSame([], $brief['blocks'][6]['cites']);
        foreach (array_slice($brief['blocks'], 1, -1) as $block) {
            self::assertCount(1, $block['cites']);
            self::assertSame('page_only', $block['evidence'][0]['highlight']['mode']);
            self::assertFalse($block['ai_generated']);
            self::assertSame('1', $block['template_version']);
            $bySource = array_column($records, null, 'source_id');
            $verification = app(BriefVerifier::class)->verify($block, $bySource, array_keys($bySource));
            self::assertSame('passed', $verification['status'], $block['template_id'].': '.implode(',', $verification['failed_reasons']));
        }
    }

    public function test_thin_unknown_and_historical_records_do_not_create_unsupported_claims(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07');
        $historical = $this->record('past', 'risk', 'Past earthquake', ['dates' => ['observed_date' => [
            'resolution' => 'calendar', 'date' => '2020-01-01']]]);
        $historical['data']['severity'] = 'critical';
        $unknown = $this->record('unknown', 'obligation', 'Unverified deadline', ['dates' => ['due_date' => [
            'resolution' => 'calendar', 'date' => '2026-10-08', 'raw' => '8 October 2026']]], 'unknown');
        $brief = app(BriefAssembler::class)->assemble('Thin document', 'PDF', [$historical, $unknown],
            ['past' => $this->assignment(1, 'critical_risk'),
                'unknown' => $this->assignment(1, 'imminent_dated_obligation')],
            ['state' => 'bounded', 'reasons' => ['source_text_unknown']], $asOf);
        self::assertSame(['headline', 'coverage_note'], array_column($brief['blocks'], 'type'));
        self::assertSame(0, $brief['ai_blocks_rejected']);
    }

    public function test_each_incomplete_coverage_state_and_legacy_route_produces_a_reason_only_note(): void
    {
        $asOf = new \DateTimeImmutable('2026-10-07');
        foreach (['partial', 'bounded', 'unavailable'] as $state) {
            $brief = app(BriefAssembler::class)->assemble('Thin document', 'PDF', [], [],
                ['state' => $state, 'reasons' => [$state.'_reason']], $asOf);
            self::assertSame('coverage_note.'.$state, $brief['blocks'][1]['template_id']);
            self::assertSame('Coverage: '.$state.'_reason.', $brief['blocks'][1]['text']);
        }
        $legacy = app(BriefAssembler::class)->assemble('Old report', 'PDF', [], [],
            ['state' => 'bounded', 'reasons' => ['legacy_route']], $asOf);
        self::assertSame('coverage_note.legacy_route', $legacy['blocks'][1]['template_id']);
    }

    public function test_legacy_adapted_relative_timeline_has_citation_without_fabricated_offsets(): void
    {
        $record = $this->record('legacy', 'obligation', 'Submit return', ['dates' => ['due_date' => [
            'type' => 'duration', 'raw' => 'within 30 days after execution', 'resolution' => 'relative',
            'duration' => ['text' => 'within 30 days after execution', 'anchor_resolved' => false]]]],
            status: null);
        $record['source_id'] = 'deadline:9';
        unset($record['record_id']);
        $record['sources'] = [];
        $brief = app(BriefAssembler::class)->assemble('Legacy agreement', 'PDF', [$record],
            ['legacy' => $this->assignment(2)], ['state' => 'bounded', 'reasons' => ['legacy_route']],
            new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['headline', 'timeline', 'coverage_note'], array_column($brief['blocks'], 'type'));
        self::assertSame('timeline.relative_due', $brief['blocks'][1]['template_id']);
        self::assertSame(['deadline:9'], $brief['blocks'][1]['cites']);
        self::assertSame([], $brief['blocks'][1]['evidence']);
        self::assertSame('coverage_note.legacy_route', $brief['blocks'][2]['template_id']);
    }

    public function test_imminent_reported_attribution_is_named_by_template(): void
    {
        $record = $this->record('soon', 'obligation', 'Supplier filing', ['dates' => ['due_date' => [
            'type' => 'date', 'raw' => '8 October 2026', 'resolution' => 'calendar', 'date' => '2026-10-08']]]);
        $record['provenance']['attribution']['role'] = 'counterparty';
        $record['provenance']['attribution']['reported'] = true;
        $brief = app(BriefAssembler::class)->assemble('Contract', 'PDF', [$record],
            ['soon' => $this->assignment(1, 'imminent_dated_obligation')],
            ['state' => 'complete', 'reasons' => []], new \DateTimeImmutable('2026-10-07'));
        self::assertSame('attention.imminent', $brief['blocks'][1]['template_id']);
        self::assertStringStartsWith('Counterparty report:', $brief['blocks'][1]['text']);
        self::assertSame('timeline.calendar_due', $brief['blocks'][2]['template_id']);
        self::assertCount(3, $brief['blocks']);
    }
}
