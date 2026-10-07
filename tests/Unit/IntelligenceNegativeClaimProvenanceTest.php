<?php

namespace Tests\Unit;

use App\Services\Intelligence\NegativeClaimGuard;
use PHPUnit\Framework\TestCase;

class IntelligenceNegativeClaimProvenanceTest extends TestCase
{
    public function test_unknown_origin_cannot_support_an_absence_claim_under_complete_coverage(): void
    {
        $guard = new NegativeClaimGuard;
        $coverage = ['state' => 'complete'];
        $records = [['provenance' => ['origin' => 'unknown', 'assertion' => 'unspecified']]];

        self::assertNull($guard->absenceCheck($coverage, 'risk_severity_in(high,critical)',
            'pipeline:current', $records, fn () => false));
    }

    public function test_noncomplete_coverage_cannot_support_absence_even_with_direct_records(): void
    {
        $guard = new NegativeClaimGuard;
        $records = [['provenance' => ['origin' => 'document', 'assertion' => 'stated']]];
        foreach (['bounded', 'partial', 'unavailable'] as $state) {
            self::assertNull($guard->absenceCheck(['state' => $state], 'risk_severity_in(high,critical)',
                'pipeline:current', $records, fn () => false));
        }
    }
}
