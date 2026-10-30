<?php

namespace App\Services\Comparisons;

/** A narrow source grammar, never a name lookup or an LLM identity claim. */
class SourceIdentityIdentifier
{
    public function forName(string $name, array $sources): ?array
    {
        if (trim($name) === '' || mb_strlen($name) > 255) {
            return null;
        }
        $identifiers = [];
        foreach (array_slice($sources, 0, 32) as $source) {
            $quote = (string) ($source['quote'] ?? '');
            // Bind the identifier to this exact named subject, not a nearby mentioned party.
            preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote(trim($name), '/')
                .'\s*\(\s*LEI\s*:\s*([A-Z0-9]{20})\s*\)/iu', $quote, $matches);
            foreach ($matches[1] as $raw) {
                $value = strtoupper($raw);
                if ($this->validLei($value)) {
                    $identifiers[$value] = true;
                }
            }
        }
        if (count($identifiers) > 1) {
            return ['namespace' => 'ISO17442:LEI', 'issuer' => 'GLEIF', 'jurisdiction' => 'global',
                'value' => null, 'conflict' => true, 'basis' => 'conflicting_named_source_identifiers'];
        }
        if ($identifiers === []) {
            return null;
        }

        return ['namespace' => 'ISO17442:LEI', 'issuer' => 'GLEIF', 'jurisdiction' => 'global',
            'value' => array_key_first($identifiers), 'basis' => 'exact_named_source_identifier'];
    }

    private function validLei(string $value): bool
    {
        if (! preg_match('/^[A-Z0-9]{18}[0-9]{2}$/D', $value)) {
            return false;
        }
        $remainder = 0;
        foreach (str_split($value) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
