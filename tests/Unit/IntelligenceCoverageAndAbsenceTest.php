<?php

namespace Tests\Unit;

use App\Services\Intelligence\CoverageStateBuilder;
use App\Services\Intelligence\NegativeClaimGuard;
use PHPUnit\Framework\TestCase;

class IntelligenceCoverageAndAbsenceTest extends TestCase
{
    private function pipeline(array $changes = []): array
    {
        return array_replace_recursive(['route' => 'incremental', 'synthesis' => 'completed', 'coverage' => [
            'evidence_total' => 3, 'evidence_omitted' => 0, 'unresolved_references' => 0,
            'failed_chunks' => 0, 'total_chunks' => 2, 'dropped_records' => 0,
            'saturated_chunks' => 0, 'comprehensive' => true, 'source_text' => 'full',
            'synthesis_level' => 0, 'warning' => null,
        ]], $changes);
    }

    public function test_coverage_uses_existing_diagnostics_and_never_calls_missing_counters_complete(): void
    {
        $builder = new CoverageStateBuilder;
        self::assertSame('complete', $builder->build($this->pipeline(), true)['state']);
        $bounded = $builder->build($this->pipeline(['coverage' => ['evidence_omitted' => 1]]), true);
        self::assertSame('bounded', $bounded['state']);
        self::assertContains('evidence_trimmed', $bounded['reasons']);
        $partial = $builder->build($this->pipeline(['coverage' => ['failed_chunks' => 1,
            'saturated_chunks' => 1]]), true);
        self::assertSame('partial', $partial['state']);
        self::assertContains('processing_partial', $partial['reasons']);
        self::assertSame('unavailable', $builder->build($this->pipeline(['synthesis' => 'failed']), false)['state']);

        $unknown = $builder->build(['route' => 'incremental', 'synthesis' => 'completed'], true);
        self::assertSame('bounded', $unknown['state']);
        self::assertContains('failed_chunks_unknown', $unknown['reasons']);
        self::assertContains('source_text_unknown', $unknown['reasons']);
        $legacy = $builder->build(['route' => 'normal', 'synthesis' => 'completed'], true);
        self::assertSame('bounded', $legacy['state']);
        self::assertContains('legacy_route', $legacy['reasons']);
    }

    public function test_tier1_truncation_is_disclosed_without_falsifying_complete_coverage(): void
    {
        $coverage = (new CoverageStateBuilder)->build($this->pipeline(), true, true);
        self::assertSame('complete', $coverage['state']);
        self::assertTrue($coverage['tier1_truncated']);
        self::assertContains('tier1_truncated', $coverage['reasons']);
    }

    public function test_absence_requires_complete_coverage_and_a_zero_match_scan(): void
    {
        $builder = new CoverageStateBuilder;
        $guard = new NegativeClaimGuard;
        $records = [['kind' => 'risk', 'severity' => 'low'], ['kind' => 'metric']];
        $matches = fn (array $record) => $record['kind'] === 'risk'
            && in_array($record['severity'] ?? null, ['high', 'critical'], true);
        $complete = $builder->build($this->pipeline(), true);
        self::assertSame(['origin' => 'docintel_deterministic', 'assertion' => 'absent',
            'absence_check' => ['predicate' => 'risk_severity_in(high,critical)', 'scope' => 'pipeline:current',
                'matched' => 0]], $guard->absenceCheck($complete, 'risk_severity_in(high,critical)',
                    'pipeline:current', $records, $matches));
        self::assertNull($guard->absenceCheck($complete, 'risk_severity_in(high,critical)', 'pipeline:current',
            [...$records, ['kind' => 'risk', 'severity' => 'critical']], $matches));
        foreach (['bounded', 'partial', 'unavailable'] as $state) {
            self::assertNull($guard->absenceCheck(['state' => $state], 'risk_severity_in(high,critical)',
                'pipeline:current', $records, $matches));
        }
    }
}
