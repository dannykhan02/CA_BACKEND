<?php

namespace App\Services\Intelligence;

/** A proven role contradiction is final for that record; textual matching cannot rescue it. */
class StructuralClaimGuard
{
    public function compatible(string $claim, array $record): bool
    {
        $roles = $record['source_provenance']['roles'] ?? [];
        if (! isset($roles['value'])) {
            return true; // Legacy textual verification remains the owner.
        }
        if (($record['source_provenance']['capabilities']['structural'] ?? false)
            && is_array($record['data'] ?? null)) {
            foreach (['period' => 'period', 'label' => 'metric', 'subject' => 'subject',
                'unit' => 'unit'] as $field => $role) {
                if (is_string($record['data'][$field] ?? null) && trim($record['data'][$field]) !== ''
                    && ! isset($roles[$role])) {
                    return false;
                }
            }
        }
        $rowHeaders = array_values(array_filter($roles['metric'] ?? [],
            static fn ($anchor) => ($anchor['kind'] ?? null) === 'row_header'));
        if ($rowHeaders !== []) {
            $ownsRow = false;
            foreach ($rowHeaders as $header) {
                $label = trim((string) ($header['text'] ?? ''));
                $ownsRow = $ownsRow || ($label !== '' && mb_stripos($claim, $label) !== false);
            }
            if (! $ownsRow) {
                return false;
            }
        }
        $periods = array_values(array_filter(array_map(static fn ($anchor) => $anchor['text'] ?? null,
            $roles['period'] ?? []), 'is_string'));
        if ($periods !== []) {
            preg_match_all('/(?<!\d)(?:FY\s*)?(?:19|20)\d{2}(?!\d)/iu', $claim, $found);
            $allowed = [];
            foreach ($periods as $period) {
                preg_match_all('/(?:19|20)\d{2}/u', $period, $years);
                array_push($allowed, ...$years[0]);
            }
            foreach ($found[0] as $token) {
                if (preg_match('/(?:19|20)\d{2}/u', $token, $year)
                    && ! in_array($year[0], $allowed, true)) {
                    return false;
                }
            }
        }
        $units = array_values(array_filter(array_map(static fn ($anchor) => $anchor['text'] ?? null,
            array_merge($roles['unit'] ?? [], $roles['currency'] ?? [])), 'is_string'));
        if ($units !== []) {
            preg_match_all('/\b(?:USD|EUR|GBP|KES|CAD|AUD)\b|[$€£]/iu', $claim, $claimed);
            $allowed = mb_strtoupper(implode(' ', $units));
            foreach ($claimed[0] as $token) {
                if (! str_contains($allowed, mb_strtoupper($token))) {
                    return false;
                }
            }
        }

        return true;
    }
}
