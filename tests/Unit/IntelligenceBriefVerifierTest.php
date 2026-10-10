<?php

namespace Tests\Unit;

use App\Services\Intelligence\Brief\BriefVerifier;
use App\Services\Intelligence\Brief\Templates\DeterministicTemplates;
use Tests\TestCase;

class IntelligenceBriefVerifierTest extends TestCase
{
    private function record(string $id = 'kpi:1', float $number = 11e9, string $currency = 'USD',
        string $origin = 'document', string $kind = 'metric'): array
    {
        return ['source_id' => $id, 'identity' => $id, 'kind' => $kind,
            'data' => ['label' => 'Revenue', 'value' => '11', 'subject' => '', 'aliases' => [], 'severity' => null],
            'typed' => ['value' => ['type' => 'money', 'number' => $number, 'raw' => '11',
                'precision' => 'exact', 'scale' => 1e9, 'unit_kind' => 'currency', 'currency' => $currency,
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
        // 12 with durations_grounded, which this claim states none of.
        self::assertCount(12, $result['checks']);
        self::assertSame('skipped', $this->check($result, 'durations_grounded'));
        self::assertSame('passed', $this->check($result, 'numbers_grounded'));
        self::assertSame('passed', $this->check($result, 'periods_grounded'));
        self::assertSame('skipped', $this->check($result, 'dates_grounded'));
    }

    public function test_number_date_and_unit_mismatches_fail_their_checks(): void
    {
        $record = $this->record();
        $record['typed']['dates']['due_date'] = ['resolution' => 'calendar', 'date' => '2025-03-31'];
        $record['typed']['value'] = ['type' => 'percent', 'number' => 11.0, 'raw' => '11%',
            'precision' => 'exact', 'scale' => 1.0, 'unit_kind' => 'percent', 'currency' => null, 'unit' => '%'];
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
        $a['typed']['value']['raw'] = '100';
        $b['typed']['value']['raw'] = '120';
        $a['typed']['value']['unit'] = $b['typed']['value']['unit'] = 'USD';
        $a['typed']['value']['scale'] = $b['typed']['value']['scale'] = 1.0;
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

    public function test_every_original_citation_entry_is_validated_without_dropping_duplicates(): void
    {
        $record = $this->record();
        $verifier = app(BriefVerifier::class);
        foreach ([['kpi:1'], ['kpi:1', 'kpi:1']] as $cites) {
            self::assertSame('passed', $verifier->verify($this->block('Revenue was USD 11 billion.', $cites),
                ['kpi:1' => $record], ['kpi:1'])['status']);
        }
        foreach ([['kpi:1', []], [''], [null], [123], ['kpi:1', '']] as $cites) {
            $block = $this->block('Revenue was USD 11 billion.');
            $block['cites'] = $cites;
            self::assertContains('cites_available', $verifier->verify($block,
                ['kpi:1' => $record], ['kpi:1'])['failed_reasons']);
        }
        $block = $this->block('Revenue was USD 11 billion.');
        $block['cites'] = null;
        $block['sourceIds'] = ['kpi:1'];
        self::assertContains('cites_available', $verifier->verify($block,
            ['kpi:1' => $record], ['kpi:1'])['failed_reasons']);
    }

    public function test_origin_assertion_pairs_follow_contract_table(): void
    {
        $legal = ['document' => ['stated'], 'docintel_deterministic' => ['derived', 'absent'],
            'docintel_ai' => ['stated', 'derived', 'inferred'], 'unknown' => ['unspecified']];
        $assertions = ['stated', 'derived', 'absent', 'inferred', 'unspecified', 'invalid'];
        $verifier = app(BriefVerifier::class);
        foreach (array_keys($legal) as $origin) {
            foreach ($assertions as $assertion) {
                $block = $this->block('A finding.');
                $block['origin'] = $origin;
                $block['assertion'] = $assertion;
                $result = $verifier->verify($block, ['kpi:1' => $this->record()], ['kpi:1']);
                self::assertSame(in_array($assertion, $legal[$origin], true) ? 'passed' : 'failed',
                    $this->check($result, 'origin_assertion_consistent'), $origin.'/'.$assertion);
            }
        }
        $unknown = $verifier->verify($this->block('A finding.'),
            ['kpi:1' => $this->record(origin: 'unknown')], ['kpi:1']);
        self::assertSame('failed', $this->check($unknown, 'origin_assertion_consistent'));
    }

    public function test_calendar_dates_use_shared_recognizer_and_periods_stay_separate(): void
    {
        $record = $this->record();
        $record['typed']['dates']['due_date'] = ['resolution' => 'calendar', 'date' => '2025-03-31'];
        $verifier = app(BriefVerifier::class);
        foreach (['2025-03-31', '31/03/2025', '31 March 2025', 'March 31, 2025'] as $date) {
            $result = $verifier->verify($this->block('Due '.$date.'.'), ['kpi:1' => $record], ['kpi:1']);
            self::assertSame('passed', $this->check($result, 'dates_grounded'), $date);
        }
        $invalid = $verifier->verify($this->block('Due 31/02/2025.'), ['kpi:1' => $record], ['kpi:1']);
        self::assertSame('failed', $this->check($invalid, 'dates_grounded'));
        unset($record['typed']['dates']['due_date']);
        $period = $verifier->verify($this->block('Due 31 March 2025.'), ['kpi:1' => $record], ['kpi:1']);
        self::assertSame('failed', $this->check($period, 'dates_grounded'));
    }

    public function test_incomplete_typed_values_and_cross_record_units_cannot_ground_claims(): void
    {
        $verifier = app(BriefVerifier::class);
        $record = $this->record();
        foreach (['type', 'number', 'scale', 'unit_kind', 'currency', 'precision'] as $key) {
            $bad = $record;
            unset($bad['typed']['value'][$key]);
            $result = $verifier->verify($this->block('Revenue was USD 11 billion.'),
                ['kpi:1' => $bad], ['kpi:1']);
            self::assertSame('failed', $this->check($result, 'numbers_grounded'), $key);
        }
        $other = $this->record('kpi:2', 12e9, 'USD');
        $other['typed']['value']['raw'] = '12';
        $other['typed']['value']['currency'] = 'EUR';
        $other['typed']['value']['unit'] = 'EUR billion';
        $result = $verifier->verify($this->block('Revenue was EUR 11 billion.', ['kpi:1', 'kpi:2']),
            ['kpi:1' => $record, 'kpi:2' => $other], ['kpi:1', 'kpi:2']);
        self::assertSame('failed', $this->check($result, 'numbers_grounded'));
        self::assertSame('failed', $this->check($result, 'units_consistent'));

        // A time span is checked as a span, not as a loose number: "within 30 days" leaves no 30
        // behind for numbersGrounded() to chase, and the span itself has to be one the cited record
        // owns. This record's typed date is incomplete - no `type` - so it owns none.
        $duration = $this->record();
        $duration['typed']['value'] = null;
        $duration['typed']['dates']['due_date'] = ['resolution' => 'relative',
            'duration' => ['text' => 'within 30 days', 'anchor_resolved' => false]];
        $result = $verifier->verify($this->block('Submit within 30 days.'),
            ['kpi:1' => $duration], ['kpi:1']);
        self::assertSame('skipped', $this->check($result, 'numbers_grounded'));
        self::assertSame('failed', $this->check($result, 'durations_grounded'));

        // Completed into the shape ValueParser actually writes, the same span grounds.
        $duration['typed']['dates']['due_date'] += ['type' => 'duration', 'raw' => 'within 30 days'];
        $good = $verifier->verify($this->block('Submit within 30 days.'),
            ['kpi:1' => $duration], ['kpi:1']);
        self::assertSame('passed', $this->check($good, 'durations_grounded'));

        // And a span the record does not state is refused.
        $wrong = $verifier->verify($this->block('Submit within 60 days.'),
            ['kpi:1' => $duration], ['kpi:1']);
        self::assertSame('failed', $this->check($wrong, 'durations_grounded'));
    }

    public function test_fallback_is_registered_rendered_and_verified_or_omitted(): void
    {
        $record = $this->record();
        $original = $this->block('Revenue was USD 99 billion.');
        $input = ['label' => 'Revenue', 'value' => $record['typed']['value']];
        $candidate = ['type' => 'finding', 'origin' => 'docintel_deterministic', 'assertion' => 'derived',
            'template_id' => 'measure.period_value', 'template_input' => $input,
            'text' => app(DeterministicTemplates::class)->render('measure.period_value', $input),
            'cites' => ['kpi:1']];
        $candidate['type'] = $original['type'] = 'measure';
        $verifier = app(BriefVerifier::class);
        $admit = fn (array $replacement) => $verifier->admit($original, ['kpi:1' => $record], ['kpi:1'],
            ['state' => 'complete'], [$record], null, fn () => $replacement);
        self::assertNotNull($admit($candidate)['block']);
        $bad = $candidate;
        $bad['cites'] = ['kpi:1', null];
        self::assertNull($admit($bad)['block']);
        $bad = $candidate;
        $bad['text'] = 'Revenue: USD 99 billion';
        self::assertNull($admit($bad)['block']);
        $bad = $candidate;
        $bad['template_input']['value']['raw'] = 'USD 99 billion';
        $bad['text'] = app(DeterministicTemplates::class)->render('measure.period_value', $bad['template_input']);
        self::assertNull($admit($bad)['block']);
        $bad = $candidate;
        $bad['template_id'] = 'made.up';
        self::assertNull($admit($bad)['block']);
        self::assertTrue($admit($bad)['rejected']);

        $timeline = $this->block('Due 31 February 2025.');
        $timeline['type'] = 'timeline';
        $dateInput = ['label' => 'Filing', 'date' => ['raw' => '31 March 2025']];
        $dateCandidate = ['type' => 'timeline', 'origin' => 'docintel_deterministic',
            'assertion' => 'derived', 'template_id' => 'timeline.calendar_due',
            'template_input' => $dateInput, 'cites' => ['kpi:1'],
            'text' => app(DeterministicTemplates::class)->render('timeline.calendar_due', $dateInput)];
        self::assertNull($verifier->admit($timeline, ['kpi:1' => $record], ['kpi:1'],
            ['state' => 'complete'], [$record], null, fn () => $dateCandidate)['block']);
    }
}
