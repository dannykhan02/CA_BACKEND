<?php

namespace App\Services\Comparisons;

use App\Services\Intelligence\SeverityNormalizer;

/** Compares extracted classifications for a corresponding risk, never real-world likelihood. */
class RiskDecision
{
    public function decide(array $a, array $b): array
    {
        $ca = $a['context'] ?? [];
        $cb = $b['context'] ?? [];
        foreach (['subject', 'risk_type'] as $field) {
            if (! empty($ca[$field]) && ! empty($cb[$field]) && $this->normalized($ca[$field]) !== $this->normalized($cb[$field])) {
                return ['not_comparable', 'risk_'.$field.'_mismatch'];
            }
        }
        if (empty($ca['identity']['key']) || $ca['identity']['key'] !== ($cb['identity']['key'] ?? null)) {
            return ['potential_relationship', 'risk_identity_requires_review'];
        }
        if (empty($ca['subject']) || empty($cb['subject'])) {
            return ['insufficient_evidence', 'risk_subject_unknown'];
        }
        foreach ([$a, $b] as $fact) {
            if (empty($fact['source']['spans']) || empty($fact['source']['evidence']) || empty($fact['source']['intelligence_revision_id'])) {
                return ['insufficient_evidence', 'missing_risk_evidence'];
            }
            if (($fact['context']['ambiguous_source_context'] ?? false)) {
                return ['insufficient_evidence', 'ambiguous_risk_subject'];
            }
            if (SeverityNormalizer::normalize($fact['value']['severity'] ?? null) === null
                || ($fact['context']['severity_scale'] ?? 'low_medium_high_critical') !== 'low_medium_high_critical') {
                return ['not_comparable', 'risk_severity_scale_unknown'];
            }
        }
        if (SeverityNormalizer::normalize($a['value']['severity']) !== SeverityNormalizer::normalize($b['value']['severity'])) {
            return ['supported_change', 'extracted_risk_classification_difference'];
        }
        if ($this->normalized($a['value']['description'] ?? '') !== $this->normalized($b['value']['description'] ?? '')) {
            return ['potential_relationship', 'risk_interpretation_differs'];
        }

        return ['equivalent', 'same_corresponding_risk_observation'];
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
