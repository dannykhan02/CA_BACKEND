<?php

namespace App\Http\Resources;

use App\Services\Intelligence\CanonicalMetricIdentity;
use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PeriodParser;
use App\Services\Kpis\KpiIdentityProfile;
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
        $evidenceData = is_array($evidence?->data) ? $evidence->data : [];
        $proof = $evidenceData['source_fields_verified'] ?? null;
        $rejection = $evidenceData['grounding_rejection'] ?? $metadata['grounding_rejection'] ?? null;
        $integrity = app(MeasurementIntegrity::class)->assess($measurement, (string) $this->value,
            $this->unit, array_merge($metadata, ['source_fields_verified' => $proof,
                'field_verification_policy' => $evidenceData['field_verification_policy'] ?? null,
                'grounding_rejection' => $rejection,
                'source_revision' => $evidenceData['source_revision'] ?? null,
                'current_source_revision' => $request->attributes->get('document_source_revision')
                    ?? hash('sha256', (string) $this->document?->extracted_text),
                'label' => $this->label, 'period' => $this->period,
                'subject' => $metadata['scope'] ?? null]), $evidence?->sources ?? [],
            $evidence !== null && ($evidence->kind === 'metric'
                || ($evidence->data['measurement_verification'] ?? null) === 'verified'));
        if (app(PeriodParser::class)->parse($this->period) === null) {
            $integrity['analyticalEligible'] = false;
            $integrity['reasons'][] = 'period_unavailable';
        }
        $identity = array_filter(array_intersect_key($metadata,
            array_flip(['concept', 'scope', 'metric_type', 'quantity_kind', 'aggregation', 'value_basis', 'period'])),
            fn ($value) => is_string($value) && trim($value) !== '');
        if (array_diff_key($identity, ['period' => true]) !== [] && ! app(KpiIdentityProfile::class)->make(['label' => $this->label, 'unit' => $this->unit,
            'value' => $this->value, 'identity' => $identity])['valid']) {
            $integrity['analyticalEligible'] = false;
            $integrity['reasons'][] = 'semantic_identity_conflict';
        }
        $integrity['reasons'] = array_values(array_unique($integrity['reasons']));

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
