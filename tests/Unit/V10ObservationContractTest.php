<?php

namespace Tests\Unit;

use App\Services\AI\ResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class V10ObservationContractTest extends TestCase
{
    private function observation(): array
    {
        return ['label' => 'Revenue', 'value' => '51.0', 'unit' => 'KES million',
            'trend' => null, 'trendValue' => null,
            'source_context' => ['value_span_id' => 'E029', 'unit_span_id' => 'E027',
                'period_span_id' => 'E028', 'subject_span_id' => 'E025'],
            'identity' => ['concept' => 'revenue', 'scope' => 'Northstar Distribution Ltd',
                'metric_type' => 'amount', 'quantity_kind' => 'absolute',
                'aggregation' => 'total', 'value_basis' => 'actual', 'period' => 'Q3 2026']];
    }

    public function test_v10_accepts_complete_cited_observations_without_chart_json(): void
    {
        $validator = app(ResponseValidator::class);
        $result = $validator->validateInsights(['kpis' => [$this->observation()], 'insights' => []], true);
        $validator->validateV10Observations($result['kpis']);
        self::assertSame([], $result['charts']);
        $legacy = $validator->validateInsights(['kpis' => [], 'charts' => [], 'insights' => []]);
        self::assertSame([], $legacy['charts']);
    }

    public static function malformed(): array
    {
        return [
            'missing role' => ['source_context', 'subject_span_id'],
            'missing identity' => ['identity', 'value_basis'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_v10_rejects_incomplete_observation_roles(string $section, string $field): void
    {
        $observation = $this->observation();
        unset($observation[$section][$field]);
        $this->expectException(\RuntimeException::class);
        app(ResponseValidator::class)->validateV10Observations([$observation]);
    }

    public function test_v10_rejects_model_chart_series_and_model_trend_strings(): void
    {
        $validator = app(ResponseValidator::class);
        try {
            $validator->validateInsights(['kpis' => [], 'insights' => [],
                'charts' => [['data' => [['label' => 'Q3', 'value' => 51]]]]], true);
            self::fail('A V10 chart payload must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('V10 response must not contain model-authored charts.', $e->getMessage());
        }
        $observation = $this->observation();
        $observation['trendValue'] = '46 in Q2';
        $this->expectException(\RuntimeException::class);
        $validator->validateV10Observations([$observation]);
    }
}
