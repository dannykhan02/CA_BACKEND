<?php

namespace App\Services\Intelligence;

/** Rejects a candidate status contradicted by explicit source wording. */
class ValueBasisEvidence
{
    public function rejection(?string $claimed, string $source): ?string
    {
        $matches = [];
        foreach (['proposed' => '\\bpropos(?:ed|al)\\b', 'approved' => '\\bapprov(?:ed|al)\\b',
            'incurred' => '\\b(?:incurred|spent|paid)\\b', 'budget' => '\\bbudget(?:ed)?\\b',
            'forecast' => '\\b(?:forecast|projected|projection)\\b',
            'target' => '\\b(?:target|goal|threshold|ceiling)\\b'] as $basis => $pattern) {
            if (preg_match('/'.$pattern.'/iu', $source) === 1) {
                $matches[] = $basis;
            }
        }
        if (count($matches) > 1) {
            return 'value_basis_ambiguous';
        }
        if ($matches !== [] && mb_strtolower(trim((string) $claimed)) !== $matches[0]) {
            return 'value_basis_source_mismatch';
        }
        if ($matches === [] && in_array($claimed, ['proposed', 'approved', 'incurred', 'budget',
            'forecast', 'target'], true)) {
            return 'value_basis_unverified';
        }

        return null;
    }
}
