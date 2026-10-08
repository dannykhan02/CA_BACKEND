<?php

namespace Tests\Unit;

use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Tests\TestCase;

class IntelligenceMultipleDateRolesTest extends TestCase
{
    public function test_observed_and_due_dates_are_retained_and_drive_independent_rules(): void
    {
        $quote = 'The event occurred on 1 January 2020; remediation is due by 1 November 2026.';
        $typed = ['value' => null, 'dates' => [
            'period_covered' => ['raw' => '1 January 2020', 'resolution' => 'calendar', 'date' => '2020-01-01'],
            'due_date' => ['raw' => '1 November 2026', 'resolution' => 'calendar', 'date' => '2026-11-01'],
        ]];
        $resolved = app(DateRoleResolver::class)->resolve($typed, ['kind' => 'risk'], [$quote]);
        self::assertSame(['observed_date', 'due_date'], array_keys($resolved['dates']));
        self::assertSame('2020-01-01', $resolved['dates']['observed_date']['date']);
        self::assertSame('2026-11-01', $resolved['dates']['due_date']['date']);

        $asOf = new \DateTimeImmutable('2026-10-07');
        $record = ['identity' => 'same-span', 'source_id' => 'risk:same-span', 'kind' => 'risk',
            'data' => ['label' => 'Event risk', 'value' => 'Event risk', 'severity' => 'high'],
            'typed' => $resolved, 'sources' => [['span_id' => 'E1', 'quote' => $quote]],
            'status' => 'open', 'provenance' => ['origin' => 'document', 'assertion' => 'stated',
                'attribution' => ['role' => 'unattributed']]];
        self::assertTrue((new HistoricalRiskRule)->applies($record, [$record], $asOf));
        $context = ['span_count' => null, 'cited_source_ids' => [], 'comparable_source_ids' => []];
        self::assertNull(app(MaterialityScorer::class)->assign([$record], $context, $asOf)['same-span']['forced_rule']);

        $record['kind'] = 'obligation';
        $record['identity'] = 'due';
        $record['source_id'] = 'deadline:due';
        self::assertFalse((new HistoricalRiskRule)->applies($record, [$record], $asOf));
        self::assertSame('imminent_dated_obligation',
            app(MaterialityScorer::class)->assign([$record], $context, $asOf)['due']['forced_rule']);
    }
}
