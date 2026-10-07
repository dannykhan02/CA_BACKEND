<?php

namespace Tests\Unit;

use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use Tests\TestCase;

class IntelligenceHistoricalAttentionTest extends TestCase
{
    private function builder(): AttentionStateBuilder
    {
        return new AttentionStateBuilder(new HistoricalRiskRule, config('intelligence_v2.attention'));
    }

    private function risk(array $date, string $severity = 'critical'): array
    {
        return ['identity' => 'quake', 'kind' => 'risk', 'status' => 'open',
            'data' => ['severity' => $severity],
            'typed' => ['dates' => ['observed_date' => $date]],
            'sources' => [['span_id' => 'E1']]];
    }

    public function test_past_earthquake_is_informational_without_rewriting_risk_kind_or_severity(): void
    {
        $asOf = new \DateTimeImmutable('2025-01-01');
        $record = $this->risk(['resolution' => 'calendar', 'date' => '2024-01-01']);
        $state = $this->builder()->build($record, ['tier' => 2], [$record], $asOf);

        self::assertSame('informational', $state['state']);
        self::assertSame(['historical_context'], $state['reasons']);
        self::assertSame('risk', $record['kind']);
        self::assertSame('critical', $record['data']['severity']);
        self::assertSame('2025-01-01T00:00:00+00:00', $state['as_of']);
    }

    public function test_past_earthquake_high_risk_anchored_period_is_historical(): void
    {
        $record = $this->risk(['resolution' => 'period', 'period' => [
            'anchored' => true, 'end' => '2024-12-31',
        ]], 'high');
        self::assertTrue((new HistoricalRiskRule)->applies($record, [$record], new \DateTimeImmutable('2025-01-01')));
        self::assertSame('informational', $this->builder()->build($record, ['tier' => 2],
            [$record], new \DateTimeImmutable('2025-01-01'))['state']);
    }

    public function test_past_earthquake_unresolved_observed_date_does_not_trigger_exception(): void
    {
        $record = $this->risk(['resolution' => 'unknown']);
        $asOf = new \DateTimeImmutable('2025-01-01');
        self::assertFalse((new HistoricalRiskRule)->applies($record, [$record], $asOf));
        self::assertSame('needs_attention', $this->builder()->build($record,
            ['tier' => 1, 'forced_rule' => 'critical_risk'], [$record], $asOf)['state']);
    }

    public function test_past_earthquake_same_span_open_or_future_consequence_suppresses_exception(): void
    {
        $record = $this->risk(['resolution' => 'calendar', 'date' => '2024-01-01']);
        $open = ['identity' => 'open', 'kind' => 'obligation', 'status' => 'open',
            'sources' => [['span_id' => 'E1']]];
        $future = ['identity' => 'future', 'kind' => 'fact', 'status' => 'closed',
            'sources' => [['span_id' => 'E1']], 'typed' => ['dates' => ['review_date' => [
                'resolution' => 'calendar', 'date' => '2025-06-01',
            ]]]];
        $asOf = new \DateTimeImmutable('2025-01-01');

        self::assertFalse((new HistoricalRiskRule)->applies($record, [$record, $open], $asOf));
        self::assertFalse((new HistoricalRiskRule)->applies($record, [$record, $future], $asOf));
        $future['sources'] = [['span_id' => 'E2']];
        self::assertTrue((new HistoricalRiskRule)->applies($record, [$record, $future], $asOf));
    }

    public function test_incomplete_coverage_never_yields_clear_document_attention(): void
    {
        $asOf = new \DateTimeImmutable('2025-01-01');
        $summary = $this->builder()->summary([], ['state' => 'bounded'], 0, $asOf);

        self::assertSame('unknown', $summary['state']);
        self::assertSame('clear', $this->builder()->summary([], ['state' => 'complete'], 0, $asOf)['state']);
    }
}
