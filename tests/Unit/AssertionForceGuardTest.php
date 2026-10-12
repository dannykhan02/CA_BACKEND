<?php

namespace Tests\Unit;

use App\Services\Intelligence\AssertionForceGuard;
use App\Services\Intelligence\StructuralClaimGuard;
use PHPUnit\Framework\TestCase;

class AssertionForceGuardTest extends TestCase
{
    public function test_obligation_force_negation_and_exception_are_preserved(): void
    {
        $guard = new AssertionForceGuard;
        $record = static fn (string $quote) => ['kind' => 'obligation', 'data' => ['quote' => $quote]];
        self::assertTrue($guard->compatible('The supplier shall pay unless excused.',
            $record('The supplier shall pay unless excused.')));
        self::assertFalse($guard->compatible('The supplier shall pay.',
            $record('The supplier may pay.')));
        self::assertFalse($guard->compatible('The supplier shall pay.',
            $record('The supplier shall not pay.')));
        self::assertFalse($guard->compatible('The supplier shall pay.',
            $record('The supplier shall pay unless excused.')));
    }

    public function test_metric_edition_and_estimate_confusion_is_rejected(): void
    {
        $guard = new AssertionForceGuard;
        self::assertFalse($guard->compatible('Original revenue was 12.',
            ['kind' => 'metric', 'data' => ['quote' => 'Restated revenue was 12.']]));
        self::assertFalse($guard->compatible('Actual revenue was 12.',
            ['kind' => 'metric', 'data' => ['quote' => 'Estimated revenue was 12.']]));
        self::assertTrue($guard->compatible('Restated revenue was 12.',
            ['kind' => 'metric', 'data' => ['quote' => 'Restated revenue was 12.']]));
    }

    public function test_unresolved_metric_conflict_cannot_ground_a_single_winner(): void
    {
        self::assertFalse((new StructuralClaimGuard)->compatible('Revenue was 10 in 2024.', [
            'data' => ['source_conflict' => ['status' => 'unresolved_conflict']],
        ]));
    }
}
