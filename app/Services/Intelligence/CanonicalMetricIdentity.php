<?php

namespace App\Services\Intelligence;

/** Converts route-specific KPI semantics into one internal measurement identity. */
class CanonicalMetricIdentity
{
    public function __construct(private MeasurementParser $parser) {}

    public function incremental(array $record): array
    {
        if (($record['kind'] ?? null) !== 'metric') {
            return $record;
        }
        $rawType = mb_strtolower(trim((string) ($record['metric_type'] ?? '')));
        $rawBasis = mb_strtolower(trim((string) ($record['value_basis'] ?? '')));
        $legacy = in_array($rawType, ['actual', 'target', 'change'], true)
            || in_array($rawBasis, ['total', 'average', 'rate'], true);
        if (! $legacy) {
            return $record;
        }
        $measurement = $this->parser->parse($record['value'] ?? null, $record['unit'] ?? null,
            $record['label'] ?? null);
        $quantityType = match ($measurement?->kind) {
            'currency' => 'amount', 'percent' => 'percentage',
            'count', 'duration', 'ratio' => $measurement->kind,
            default => null,
        };
        $record['raw_metric_type'] = $record['metric_type'] ?? null;
        $record['raw_value_basis'] = $record['value_basis'] ?? null;
        $record['metric_type'] = in_array($rawType, ['actual', 'target', 'change'], true)
            ? ($rawBasis === 'rate' ? 'rate' : $quantityType) : ($record['metric_type'] ?? null);
        if (! in_array($record['metric_type'], ['amount', 'count', 'percentage', 'rate', 'duration', 'ratio', 'index'], true)) {
            $record['metric_type'] = null;
        }
        $record['value_basis'] = in_array($rawType, ['actual', 'target'], true)
            ? $rawType : (in_array($rawBasis, ['total', 'average', 'rate'], true) ? null : ($record['value_basis'] ?? null));
        if (in_array($rawBasis, ['total', 'average'], true)) {
            $record['aggregation'] = $rawBasis;
        }
        if ($rawType === 'change') {
            $record['quantity_kind'] = 'change';
        }
        $record['identity_contract_version'] = 2;

        return $record;
    }
}
