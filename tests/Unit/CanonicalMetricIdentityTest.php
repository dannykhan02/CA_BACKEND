<?php

namespace Tests\Unit;

use App\Services\Intelligence\CanonicalMetricIdentity;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Kpis\KpiLabelNormalizer;
use PHPUnit\Framework\TestCase;

class CanonicalMetricIdentityTest extends TestCase
{
    public function test_legacy_incremental_status_and_aggregation_are_mapped_without_changing_raw_value(): void
    {
        $contract = new CanonicalMetricIdentity(new MeasurementParser(new KpiLabelNormalizer));
        $input = ['kind' => 'metric', 'label' => 'Revenue', 'value' => '51.0',
            'unit' => 'KES million', 'metric_type' => 'actual', 'value_basis' => 'total'];
        $mapped = $contract->incremental($input);
        $this->assertSame('51.0', $mapped['value']);
        $this->assertSame('KES million', $mapped['unit']);
        $this->assertSame('amount', $mapped['metric_type']);
        $this->assertSame('actual', $mapped['value_basis']);
        $this->assertSame('total', $mapped['aggregation']);
        $this->assertSame('actual', $mapped['raw_metric_type']);
        $this->assertSame('total', $mapped['raw_value_basis']);
    }

    public function test_change_is_not_a_target_or_actual_value(): void
    {
        $contract = new CanonicalMetricIdentity(new MeasurementParser(new KpiLabelNormalizer));
        $mapped = $contract->incremental(['kind' => 'metric', 'label' => 'Delivery change',
            'value' => '8', 'unit' => 'percentage points', 'metric_type' => 'change',
            'value_basis' => 'rate']);
        $this->assertNull($mapped['value_basis']);
        $this->assertSame('change', $mapped['quantity_kind']);
        $this->assertSame('rate', $mapped['metric_type']);
    }
}
