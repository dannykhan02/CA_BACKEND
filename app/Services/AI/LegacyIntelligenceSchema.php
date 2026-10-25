<?php

namespace App\Services\AI;

/** JSON schemas for the four normal-route intelligence responses. */
class LegacyIntelligenceSchema
{
    private static function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required,
            'additionalProperties' => false];
    }

    private static function list(string $name, array $properties, array $required): array
    {
        return self::object([$name => ['type' => 'array', 'items' => self::object($properties, $required)]], [$name]);
    }

    public static function for(string $purpose, int $promptVersion = 0): ?array
    {
        $string = ['type' => 'string'];
        $number = ['type' => 'number'];
        $nullable = ['type' => ['string', 'null']];
        $source = ['source_span_id' => $nullable];

        return match ($purpose) {
            'insights' => self::insights($promptVersion),
            'document_type' => self::object([
                'document_type' => ['type' => 'string', 'enum' => [
                    'compliance_report', 'financial_report', 'regulatory_filing', 'policy_document',
                    'contract', 'correspondence', 'technical_report', 'meeting_minutes',
                    'research_report', 'application_form', 'invoice', 'other',
                ]], 'confidence' => $number, 'reasoning' => $string,
            ], ['document_type', 'confidence', 'reasoning']),
            'entities' => self::list('entities', [
                'entity_type' => ['type' => 'string', 'enum' => [
                    'organization', 'person', 'department', 'location', 'regulator',
                    'contract', 'reference', 'date', 'other',
                ]], 'value' => $string, 'normalized_value' => $string,
                'confidence' => $number, 'context' => $string,
            ] + $source, ['entity_type', 'value', 'normalized_value', 'confidence', 'context']),
            'risks' => self::list('risks', [
                'risk_type' => $string, 'title' => $string, 'description' => $string,
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                'confidence' => $number, 'evidence' => $string,
            ] + $source, ['risk_type', 'title', 'description', 'severity', 'confidence', 'evidence']),
            'deadlines' => self::list('deadlines', [
                'deadline_type' => $string, 'title' => $string, 'description' => $string,
                'due_date' => $nullable,
                'date_type' => ['type' => 'string', 'enum' => ['explicit', 'relative', 'inferred']],
                'relative_text' => $nullable, 'confidence' => $number, 'evidence' => $string,
            ] + $source, ['deadline_type', 'title', 'description', 'due_date', 'date_type', 'relative_text', 'confidence', 'evidence']),
            default => null,
        };
    }

    private static function insights(int $version): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];
        $kpi = ['label' => $string, 'value' => $string, 'unit' => $nullable,
            'trend' => $version >= 10 ? ['type' => 'null'] : $nullable,
            'trendValue' => $version >= 10 ? ['type' => 'null'] : $nullable];
        if ($version >= 7) {
            $identity = array_fill_keys(['concept', 'scope', 'metric_type', 'quantity_kind',
                'aggregation', 'value_basis', 'period'], $nullable);
            $kpi['identity'] = self::object($identity, array_keys($identity));
        }
        if ($version >= 8) {
            $source = array_fill_keys(['value_span_id', 'unit_span_id', 'period_span_id', 'subject_span_id'], $nullable);
            $kpi['source_context'] = self::object($source, array_keys($source));
        }
        $properties = ['kpis' => ['type' => 'array', 'items' => self::object($kpi, array_keys($kpi))]];
        if ($version < 10) {
            $point = self::object(['label' => $string, 'value' => ['type' => 'number']], ['label', 'value']);
            $chart = self::object(['type' => ['type' => 'string', 'enum' => ['bar', 'line', 'pie']],
                'title' => $string, 'description' => $string,
                'data' => ['type' => 'array', 'items' => $point]], ['type', 'title', 'description', 'data']);
            $properties['charts'] = ['type' => 'array', 'items' => $chart];
        }
        $properties['insights'] = ['type' => 'array', 'items' => $string];

        return self::object($properties, array_keys($properties));
    }
}
