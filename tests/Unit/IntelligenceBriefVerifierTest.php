<?php

namespace Tests\Unit;

use App\Services\Intelligence\Brief\BriefVerifier;
use Tests\TestCase;

class IntelligenceBriefVerifierTest extends TestCase
{
    private function record(string $id = 'kpi:1', float $number = 11e9, string $currency = 'USD',
        string $origin = 'document', string $kind = 'metric'): array
    {
        return ['source_id' => $id, 'identity' => $id, 'kind' => $kind,
            'data' => ['value' => '11', 'subject' => '', 'aliases' => [], 'severity' => null],
            'typed' => ['value' => ['type' => 'money', 'number' => $number, 'raw' => '11',
                'precision' => 'exact', 'unit_kind' => 'currency', 'currency' => $currency,
                'unit' => $currency.' billion'], 'dates' => ['period_covered' => [
                    'resolution' => 'period', 'period' => ['text' => 'FY2025']]]],
            'provenance' => ['origin' => $origin, 'assertion' => $origin === 'document' ? 'stated' : 'unspecified'],
            'sources' => [['quote' => 'Revenue was '.$currency.' 11 billion in FY2025.']]];
    }

    private function block(string $text, array $cites = ['kpi:1'], ?string $detail = null): array
    {
        return ['type' => 'finding', 'origin' => 'docintel_ai', 'assertion' => 'stated',
            'attribution' => ['speaker' => null, 'role' => 'unattributed', 'reported' => false],
            'text' => $text, 'detail' => $detail, 'cites' => $cites];
    }

    private function check(array $result, string $name): string
    {
        return collect($result['checks'])->firstWhere('check', $name)['status'];
    }

    public function test_grounded_money_and_period_pass_all_applicable_checks(): void
    {
        $record = $this->record();
        $result = app(BriefVerifier::class)->verify($this->block('Revenue was USD 11 billion in FY2025.'),
            ['kpi:1' => $record], ['kpi:1']);
        self::assertSame('passed', $result['status']);
        self::assertSame([], $result['failed_reasons']);
        self::assertCount(11, $result['checks']);
        self::assertSame('passed', $this->check($result, 'numbers_grounded'));
        self::assertSame('passed', $this->check($result, 'periods_grounded'));
        self::assertSame('skipped', $this->check($result, 'dates_grounded'));
    }

    public function test_number_date_and_unit_mismatches_fail_their_checks(): void
    {
        $record = $this->record();
        $record['typed']['dates']['due_date'] = ['resolution' => 'calendar', 'date' => '2025-03-31'];
        $record['typed']['value'] = ['type' => 'percent', 'number' => 11.0, 'raw' => '11%',
            'precision' => 'exact', 'unit_kind' => 'percent', 'currency' => null, 'unit' => '%'];
        $verifier = app(BriefVerifier::class);
        $numbers = $verifier->verify($this->block('The rate was 14%.'), ['kpi:1' => $record], ['kpi:1']);
        self::assertContains('numbers_grounded', $numbers['failed_reasons']);
        $dates = $verifier->verify($this->block('The obligation is due 30 April 2025.'),
            ['kpi:1' => $record], ['kpi:1']);
        self::assertContains('dates_grounded', $dates['failed_reasons']);
        $currency = $this->record();
        $units = $verifier->verify($this->block('Revenue was EUR 11 billion.'),
            ['kpi:1' => $currency], ['kpi:1']);
        self::assertContains('units_consistent', $units['failed_reasons']);
        self::assertContains('numbers_grounded', $units['failed_reasons']);
    }

    public function test_entity_requires_cited_name_alias_or_confirmed_resolution(): void
    {
        $metric = $this->record();
        $verifier = app(BriefVerifier::class);
        $missing = $verifier->verify($this->block('African Development Bank approved the measure.'),
            ['kpi:1' => $metric], ['kpi:1']);
        self::assertContains('entities_grounded', $missing['failed_reasons']);
        $entity = $this->record('entity:1', kind: 'entity');
        $entity['data']['value'] = 'AfDB';
        $entity['data']['aliases'] = ['African Development Bank'];
        $alias = $verifier->verify($this->block('African Development Bank approved the measure.', ['entity:1']),
            ['entity:1' => $entity], ['entity:1']);
        self::assertSame('passed', $this->check($alias, 'entities_grounded'));
        $metric['typed']['value']['entity_ref'] = ['id' => 'entity:1', 'text' => 'AfDB'];
        $confirmed = $verifier->verify($this->block('African Development Bank approved the measure.'),
            ['kpi:1' => $metric, 'entity:1' => $entity], ['kpi:1']);
        self::assertSame('passed', $this->check($confirmed, 'entities_grounded'));
    }

    public function test_comparison_needs_two_comparable_records_and_correct_direction(): void
    {
        $a = $this->record('kpi:1', 100.0);
        $b = $this->record('kpi:2', 120.0, 'EUR');
        $verifier = app(BriefVerifier::class);
        $invalid = $verifier->verify($this->block('Revenue rose.', ['kpi:1', 'kpi:2']),
            ['kpi:1' => $a, 'kpi:2' => $b], ['kpi:1', 'kpi:2']);
        self::assertContains('comparison_valid', $invalid['failed_reasons']);
        $b['typed']['value']['currency'] = 'USD';
        $b['typed']['value']['unit'] = 'USD billion';
        $wrong = $verifier->verify($this->block('Revenue fell.', ['kpi:1', 'kpi:2']),
            ['kpi:1' => $a, 'kpi:2' => $b], ['kpi:1', 'kpi:2']);
        self::assertContains('comparison_valid', $wrong['failed_reasons']);
    }

    public function test_growth_derivation_is_recomputed_and_wrong_result_fails(): void
    {
        $a = $this->record('kpi:1', 100.0);
        $b = $this->record('kpi:2', 120.0);
        $block = $this->block('Revenue rose 20%.', ['kpi:1', 'kpi:2']);
        $block['derivation'] = ['operation' => 'growth_percent', 'inputs' => ['kpi:1', 'kpi:2']];
        $verifier = app(BriefVerifier::class);
        $good = $verifier->verify($block, ['kpi:1' => $a, 'kpi:2' => $b], ['kpi:1', 'kpi:2']);
        self::assertSame('passed', $good['status']);
        $block['text'] = 'Revenue rose 25%.';
        $bad = $verifier->verify($block, ['kpi:1' => $a, 'kpi:2' => $b], ['kpi:1', 'kpi:2']);
        self::assertContains('numbers_grounded', $bad['failed_reasons']);
    }

    public function test_reported_attribution_source_leak_and_uncited_id_fail(): void
    {
        $record = $this->record();
        $record['sources'][0]['quote'] = str_repeat('Long evidence sentence. ', 12);
        $verifier = app(BriefVerifier::class);
        $reported = $this->block('The measure was reported.');
        $reported['attribution'] = ['speaker' => null, 'role' => 'counterparty', 'reported' => true];
        self::assertContains('attribution_respected', $verifier->verify($reported,
            ['kpi:1' => $record], ['kpi:1'])['failed_reasons']);
        $leak = $verifier->verify($this->block($record['sources'][0]['quote']),
            ['kpi:1' => $record], ['kpi:1']);
        self::assertContains('no_source_text_leak', $leak['failed_reasons']);
        $uncited = $verifier->verify($this->block('A statement.', ['kpi:missing']),
            ['kpi:1' => $record], ['kpi:1']);
        self::assertContains('cites_available', $uncited['failed_reasons']);
    }

    public function test_negative_claim_uses_guarded_absence_only_with_complete_coverage(): void
    {
        $record = $this->record('risk:1', kind: 'risk');
        $record['data']['severity'] = 'low';
        $block = $this->block('No high or critical risks were identified.', ['risk:1']);
        $verifier = app(BriefVerifier::class);
        $request = ['template_id' => 'absence.high_critical_risks',
            'predicate' => 'risk_severity_in(high,critical)', 'scope' => 'pipeline:test'];
        $complete = $verifier->admit($block, ['risk:1' => $record], ['risk:1'],
            ['state' => 'complete'], [$record], $request);
        self::assertTrue($complete['rejected']);
        self::assertSame('negative_claim', $complete['verification']['failed_reasons'][0]);
        self::assertSame('absent', $complete['block']['assertion']);
        $bounded = $verifier->admit($block, ['risk:1' => $record], ['risk:1'],
            ['state' => 'bounded'], [$record], $request);
        self::assertTrue($bounded['rejected']);
        self::assertNull($bounded['block']);
    }
}
