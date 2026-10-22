<?php

namespace Tests\Unit;

use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\MeasurementParser;
use Tests\TestCase;

class MeasurementRevisionProofTest extends TestCase
{
    public function test_missing_matching_and_conflicting_revisions_have_distinct_reasons(): void
    {
        $parser = app(MeasurementParser::class);
        $integrity = app(MeasurementIntegrity::class);
        $measurement = $parser->parse('51.0', 'KES million', 'Revenue');
        $base = ['source_fields_verified' => ['value', 'unit', 'period', 'subject'],
            'current_source_revision' => str_repeat('a', 64)];
        $sources = [['quote' => 'Revenue 51.0 KES million']];
        $missing = $integrity->assess($measurement, '51.0', 'KES million', $base, $sources, true);
        self::assertFalse($missing['analyticalEligible']);
        self::assertContains('revision_proof_missing', $missing['reasons']);
        self::assertNotContains('source_revision_mismatch', $missing['reasons']);
        $matching = $integrity->assess($measurement, '51.0', 'KES million',
            $base + ['source_revision' => str_repeat('a', 64)], $sources, true);
        self::assertTrue($matching['analyticalEligible']);
        $stale = $integrity->assess($measurement, '51.0', 'KES million',
            $base + ['source_revision' => str_repeat('b', 64)], $sources, true);
        self::assertFalse($stale['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $stale['reasons']);
        self::assertNotContains('revision_proof_missing', $stale['reasons']);
    }
}
