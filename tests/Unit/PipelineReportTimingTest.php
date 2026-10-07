<?php

namespace Tests\Unit;

use App\Console\Commands\PipelineReport;
use App\Models\DocumentAiRun;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineReportTimingTest extends TestCase
{
    public function test_overlap_is_measured_from_completed_run_intervals(): void
    {
        $first = new DocumentAiRun(['duration_ms' => 10000]);
        $first->created_at = Carbon::parse('2026-10-07 00:00:10');
        $second = new DocumentAiRun(['duration_ms' => 10000]);
        $second->created_at = Carbon::parse('2026-10-07 00:00:15');

        $method = (new \ReflectionClass(PipelineReport::class))->getMethod('providerConcurrency');
        $result = $method->invoke(new PipelineReport, collect([$first, $second]));

        self::assertSame(15000, $result['wall_ms']);
        self::assertSame(1.33, $result['average']);
        self::assertSame(2, $result['peak']);
        self::assertSame(66.7, $result['one_percent']);
        self::assertSame(33.3, $result['two_plus_percent']);
    }
}
