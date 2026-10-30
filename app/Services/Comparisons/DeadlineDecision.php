<?php

namespace App\Services\Comparisons;

use App\Services\AI\Incremental\EvidenceDateRecognizer;

class DeadlineDecision
{
    public function decide(array $a, array $b): array
    {
        $ca = $a['context'] ?? [];
        $cb = $b['context'] ?? [];
        foreach (['responsible_party', 'obligation_id', 'obligation_reference', 'deadline_type'] as $field) {
            if (! empty($ca[$field]) && ! empty($cb[$field]) && $this->normalized($ca[$field]) !== $this->normalized($cb[$field])) {
                return ['not_comparable', $field.'_mismatch'];
            }
        }
        if (isset($ca['identity_blocked']) || isset($cb['identity_blocked'])) {
            return ['insufficient_evidence', 'identity_unresolved'];
        }
        if (($ca['ambiguous_source_context'] ?? false) || ($cb['ambiguous_source_context'] ?? false)) {
            return ['insufficient_evidence', 'ambiguous_source_context'];
        }
        $aligned = isset($ca['identity']['key']) && $ca['identity']['key'] === ($cb['identity']['key'] ?? null);
        // Frozen M0 contract facts can carry an established obligation identity; production never fabricates it.
        $supported = ! empty($ca['obligation_id']) && $ca['obligation_id'] === ($cb['obligation_id'] ?? null)
            && ! empty($ca['responsible_party']) && $ca['responsible_party'] === ($cb['responsible_party'] ?? null);
        $av = $a['value'];
        $bv = $b['value'];
        unset($av['description'], $bv['description']);
        if ($supported && $av === $bv) {
            return ['equivalent', 'same_obligation_context'];
        }
        if (! $aligned || empty($ca['responsible_party']) || empty($cb['responsible_party'])
            || empty($ca['deadline_type']) || empty($cb['deadline_type'])) {
            return ['insufficient_evidence', 'unverified_provision_context'];
        }
        foreach ([$a, $b] as $fact) {
            if (trim((string) ($fact['source']['evidence'] ?? '')) === '') {
                return ['insufficient_evidence', 'missing_evidence'];
            }
        }
        if (($av['date_type'] ?? null) !== ($bv['date_type'] ?? null)) {
            return ['not_comparable', 'due_date_basis_mismatch'];
        }
        $type = $av['date_type'] ?? null;
        if ($type === 'relative') {
            $parse = function ($value) {
                if (! preg_match('/^(?:within\s+)?(\d+)\s+(calendar days?|business days?|days?)\s+(after|of|from)\s+(.+)$/iuD', trim((string) $value), $m)) {
                    return null;
                }

                return [$m[1], $this->normalized($m[2].' '.$m[3].' '.$m[4])];
            };
            $left = $parse($av['relative_text'] ?? null);
            $right = $parse($bv['relative_text'] ?? null);
            if ($left === null || $right === null) {
                return ['insufficient_evidence', 'unknown_due_date_basis'];
            }
            if ($left[1] !== $right[1]) {
                return ['not_comparable', 'due_date_basis_mismatch'];
            }
            foreach ([$a, $b] as $fact) {
                $term = $this->normalized($fact['value']['relative_text']);
                if (count(array_filter($fact['source']['spans'] ?? [], fn ($span) => str_contains($this->normalized($span['quote'] ?? ''), $term))) === 0) {
                    return ['insufficient_evidence', 'deadline_term_source_mismatch'];
                }
            }
        } elseif ($type !== 'explicit' || empty($av['due_date']) || empty($bv['due_date'])) {
            return ['insufficient_evidence', 'unknown_due_date_basis'];
        } else {
            foreach ([$a, $b] as $fact) {
                $dates = [];
                foreach ($fact['source']['spans'] ?? [] as $span) {
                    array_push($dates, ...array_column(EvidenceDateRecognizer::datesIn($span['quote'] ?? ''), 'date'));
                }
                $dates = array_values(array_unique($dates));
                if (count($dates) !== 1 || $dates[0] !== $fact['value']['due_date']) {
                    return ['insufficient_evidence', 'deadline_date_source_mismatch'];
                }
            }
        }

        return $av === $bv ? ['equivalent', 'aligned_equal_deadline'] : ['supported_change', 'aligned_deadline_difference'];
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
