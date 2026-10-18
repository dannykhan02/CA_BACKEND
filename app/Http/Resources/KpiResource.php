<?php

namespace App\Http\Resources;

use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\CanonicalMetricIdentity;
use App\Services\Intelligence\MeasurementParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evidence = $request->attributes->get('kpi_evidence')?->get('kpi:'.$this->id);
        $metadata = is_array($this->identity_metadata) ? $this->identity_metadata : [];
        if ($evidence?->kind === 'metric') {
            $canonical = app(CanonicalMetricIdentity::class)->incremental((is_array($evidence->data)
                ? $evidence->data : []) + ['kind' => 'metric']);
            foreach (['metric_type', 'value_basis', 'aggregation', 'quantity_kind'] as $field) {
                $metadata[$field] = $canonical[$field] ?? null;
            }
        }
        $measurement = app(MeasurementParser::class)->parse((string) $this->value, $this->unit, $this->label);
        $proof = is_array($evidence?->data) ? ($evidence->data['source_fields_verified'] ?? null) : null;
        $integrity = app(MeasurementIntegrity::class)->assess($measurement, (string) $this->value,
            $this->unit, $metadata + ['source_fields_verified' => $proof], $evidence?->sources ?? [],
            $evidence !== null && ($evidence->kind === 'metric'
                || ($evidence->data['measurement_verification'] ?? null) === 'verified'));

        return [
            'id' => (string) $this->id,
            'label' => $this->label,
            'kpiDefinitionId' => $this->kpi_definition_id,
            'period' => $this->period,
            'value' => $this->value,
            'unit' => $this->unit,
            'trend' => $this->trend,
            'trendValue' => $this->trend_value,
            'rawValue' => $this->value,
            'sourceId' => 'kpi:'.$this->id,
            'subject' => $metadata['scope'] ?? null,
            'metricType' => $metadata['metric_type'] ?? null,
            'valueBasis' => $metadata['value_basis'] ?? null,
            'numericVerification' => $integrity['numericVerification'],
            'sourceVerification' => $integrity['sourceVerification'],
            'unitVerification' => $integrity['unitVerification'],
            'analyticalEligible' => $integrity['analyticalEligible'],
            'limitationReasons' => $integrity['reasons'],
        ];
    }
}
