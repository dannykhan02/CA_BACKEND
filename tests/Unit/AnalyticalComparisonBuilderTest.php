<?php

namespace Tests\Unit;

use App\Services\Intelligence\AnalyticalComparisonBuilder;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\MetricObservation;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PeriodParser;
use Tests\TestCase;

class AnalyticalComparisonBuilderTest extends TestCase
{
    private function observation(string $id, string $concept, string $value, string $unit,
        string $period = 'Q3 2026', string $basis = 'actual', string $subject = 'company'): MetricObservation
    {
        return new MetricObservation($id, $concept, $value,
            app(MeasurementParser::class)->parse($value, $unit, $concept),
            app(PeriodParser::class)->parse($period), $subject, 1.0, null,
            $concept, $concept, 1, true,
            ['analyticalEligible' => true], $concept, $basis, 'absolute', 'total',
            $concept.' '.$value.' '.$unit.' '.$basis);
    }

    public function test_revenue_cost_and_profit_reconcile_without_counting_a_proposal_as_cost(): void
    {
        $rows = [
            $this->observation('kpi:revenue', 'revenue', '51.0', 'KES million'),
            $this->observation('kpi:costs', 'operating costs', '41.0', 'KES million'),
            $this->observation('kpi:profit', 'operating profit', '10.0', 'KES million'),
            $this->observation('kpi:proposal', 'recovery investment', '2.4', 'KES million', basis: 'proposed'),
        ];
        $answers = app(AnalyticalComparisonBuilder::class)->build($rows);
        $this->assertCount(1, $answers);
        $this->assertSame('financial_reconciliation', $answers[0]['kind']);
        $this->assertSame(10.0, $answers[0]['gap']);
        $this->assertSame('KES million', $answers[0]['gapUnit']);
        $this->assertSame(['kpi:revenue', 'kpi:costs', 'kpi:profit'], $answers[0]['sourceIds']);

        $rows[2] = $this->observation('kpi:profit', 'operating profit', '9.9', 'KES million');
        $this->assertSame([], app(AnalyticalComparisonBuilder::class)->build($rows));
        $rows[2] = $this->observation('kpi:profit', 'operating profit', '10.0', 'KES million', 'Q2 2026');
        $this->assertSame([], app(AnalyticalComparisonBuilder::class)->build($rows));
    }

    public function test_a_single_quarter_never_yields_a_period_change(): void
    {
        $rows = [$this->observation('kpi:revenue', 'revenue', '51.0', 'KES million')];
        $this->assertSame([], app(AnalyticalComparisonBuilder::class)->build($rows));
    }

    public function test_identical_duplicates_are_collapsed_and_conflicting_observations_block_analytics(): void
    {
        $q2 = $this->observation('kpi:q2', 'revenue', '46', 'KES million', 'Q2 2026');
        $repeat = $this->observation('kpi:q2-repeat', 'revenue', '46', 'KES million', 'Q2 2026');
        $q3 = $this->observation('kpi:q3', 'revenue', '51', 'KES million');
        $conflict = $this->observation('kpi:q2-conflict', 'revenue', '47', 'KES million', 'Q2 2026');
        self::assertCount(1, app(AnalyticalComparisonBuilder::class)->build([$q2, $repeat, $q3]));
        self::assertCount(1, app(ChartCandidateBuilder::class)->build([$q2, $repeat, $q3])['candidates']);
        self::assertSame([], app(AnalyticalComparisonBuilder::class)->build([$q2, $conflict, $q3]));
        self::assertSame([], app(ChartCandidateBuilder::class)->build([$q2, $conflict, $q3])['candidates']);

        $actual = $this->observation('kpi:actual', 'on-time delivery rate', '87', '%');
        $actualRepeat = $this->observation('kpi:actual-repeat', 'on-time delivery rate', '87', '%');
        $target = $this->observation('kpi:target', 'on-time delivery rate', '95', '%', basis: 'target');
        $comparisons = app(AnalyticalComparisonBuilder::class)->build([$actual, $actualRepeat, $target]);
        self::assertSame('actual_vs_target', $comparisons[0]['kind']);
        self::assertSame([], app(AnalyticalComparisonBuilder::class)->build([$actual,
            $this->observation('kpi:bad', 'on-time delivery rate', '88', '%'), $target]));
    }

    public function test_period_change_requires_adjacent_periods_and_the_same_subject(): void
    {
        $q2 = $this->observation('kpi:q2', 'revenue', '46', 'KES million', 'Q2 2026');
        $q3 = $this->observation('kpi:q3', 'revenue', '51', 'KES million');
        $answers = app(AnalyticalComparisonBuilder::class)->build([$q2, $q3]);
        $this->assertCount(1, $answers);
        $this->assertSame('period_change', $answers[0]['kind']);
        $this->assertSame(5.0, $answers[0]['gap']);
        $this->assertSame('KES million', $answers[0]['gapUnit']);
        $this->assertSame([], app(AnalyticalComparisonBuilder::class)->build([
            $this->observation('kpi:q1', 'revenue', '42', 'KES million', 'Q1 2026'), $q3]));
        $this->assertSame([], app(AnalyticalComparisonBuilder::class)->build([
            $q2, $this->observation('kpi:other', 'revenue', '51', 'KES million', subject: 'other company')]));
    }
}
