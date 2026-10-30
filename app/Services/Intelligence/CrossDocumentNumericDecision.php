<?php

namespace App\Services\Intelligence;

use Brick\Math\BigDecimal;

/** Candidate identity is supplied by the caller; compatibility is never inferred from it. */
class CrossDocumentNumericDecision
{
    public function __construct(private MeasurementParser $measurements, private PeriodParser $periods) {}

    public function decide(array $a, array $b): array
    {
        $left = $a['value'];
        $right = $b['value'];
        $ma = $this->measurement($left);
        $mb = $this->measurement($right);
        if (! $ma || ! $mb) {
            return ['insufficient_evidence', 'invalid_numeric'];
        }
        if ($ma->family === '' || $mb->family === '' || in_array($ma->kind, ['unknown']) || in_array($mb->kind, ['unknown'])) {
            return ['insufficient_evidence', 'unknown_unit'];
        }
        if (! $ma->compatibleWith($mb)) {
            return ['not_comparable', 'measurement_mismatch'];
        }
        $pa = $this->periods->parse($left['period'] ?? null);
        $pb = $this->periods->parse($right['period'] ?? null);
        if (! $pa || ! $pb) {
            return ['insufficient_evidence', 'unknown_period'];
        }
        if ($pa->label !== $pb->label || ! $pa->comparableWith($pb)
            || mb_strtolower(trim($left['period'])) !== mb_strtolower(trim($right['period']))) {
            return ['not_comparable', 'period_mismatch'];
        }
        $ca = $a['context'] ?? [];
        $cb = $b['context'] ?? [];
        foreach (['owner', 'subject', 'value_basis', 'aggregation'] as $field) {
            if (! is_string($ca[$field] ?? null) || trim($ca[$field]) === '' || ! is_string($cb[$field] ?? null) || trim($cb[$field]) === '') {
                return ['insufficient_evidence', 'unknown_'.$field];
            }
            if ($this->normalized($ca[$field]) !== $this->normalized($cb[$field])) {
                return ['not_comparable', $field === 'value_basis' ? 'basis_mismatch' : $field.'_mismatch'];
            }
        }
        if (array_key_exists('reporting_scope', $ca) || array_key_exists('reporting_scope', $cb)) {
            if (empty($ca['reporting_scope']) || empty($cb['reporting_scope'])
                || $ca['reporting_scope'] === 'ambiguous' || $cb['reporting_scope'] === 'ambiguous') {
                return ['insufficient_evidence', 'unknown_reporting_scope'];
            }
            if ($ca['reporting_scope'] !== $cb['reporting_scope']) {
                return ['not_comparable', 'reporting_scope_mismatch'];
            }
        }
        foreach (['metric_status', 'accounting_basis'] as $field) {
            $leftContext = trim((string) ($ca[$field] ?? ''));
            $rightContext = trim((string) ($cb[$field] ?? ''));
            if ($leftContext !== '' || $rightContext !== '') {
                if ($leftContext === '' || $rightContext === '') {
                    return ['insufficient_evidence', 'unknown_'.$field];
                }
                if ($this->normalized($leftContext) !== $this->normalized($rightContext)) {
                    return ['not_comparable', $field.'_mismatch'];
                }
            }
        }
        foreach ([$a, $b] as $fact) {
            $context = $fact['context'] ?? [];
            if (($context['superseded'] ?? false) || ! ($context['analytical_eligible'] ?? false)) {
                return ['insufficient_evidence', ($context['superseded'] ?? false) ? 'superseded' : 'ineligible'];
            }
            if (! is_string($fact['source']['evidence'] ?? null) || trim($fact['source']['evidence']) === '' || ($context['verification'] ?? null) !== 'source_verified') {
                return ['insufficient_evidence', 'missing_evidence'];
            }
        }

        return BigDecimal::of($ma->exactMagnitude)->isEqualTo($mb->exactMagnitude)
            ? ['equivalent', 'equal_exact_magnitude'] : ['supported_change', 'different_compatible_values'];
    }

    private function measurement(array $value): ?Measurement
    {
        if (! is_string($value['value'] ?? null) || ! preg_match('/^(?:[A-Z]{3}\s*)?(?:-?\d[\d,\x{00a0} ]*(?:\.\d+)?|\(\d[\d, ]*(?:\.\d+)?\))(?:\s*(?:%|thousands?|millions?|billions?|trillions?|k|mn|bn))?$/iuD', trim($value['value']))) {
            return null;
        }
        if (preg_match('/^([A-Z]{3})/iu', trim($value['value']), $prefix)
            && $this->measurements->isoCurrency($prefix[1]) === null) {
            return null; // Never discard an unknown currency prefix in favor of the unit field.
        }
        $measurement = $this->measurements->parse($value['value'], $value['unit'] ?? null);

        return $measurement?->exactMagnitude !== null ? $measurement : null;
    }

    private function normalized(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }
}
