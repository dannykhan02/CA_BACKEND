<?php

namespace App\Services\Intelligence\Values;

/** One backend formatter for derived intelligence amounts and faithful source values. */
class ValueFormatter
{
    public function number(float|int|string $value, ?string $unit): string
    {
        $text = (string) $value;
        if (! is_numeric($text) || str_contains(strtolower($text), 'e')) {
            return $this->raw($text, $unit);
        }
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $formatted = preg_replace('/\B(?=(?:\d{3})+(?!\d))/', ',', $integer);
        $fraction = rtrim($fraction, '0');
        if ($fraction !== '') {
            $formatted .= '.'.$fraction;
        }

        return $this->raw($formatted, $unit);
    }

    /** Append only the unit information the source value does not already state. */
    public function raw(string $value, ?string $unit): string
    {
        $value = trim($value);
        $unit = trim((string) $unit);
        if ($value === '' || $unit === '' || mb_strtolower($unit) === 'unknown') {
            return $value;
        }
        if (preg_match('/^(?:%|percent|percentage|pct)$/iu', $unit)) {
            return preg_match('/%|\bpercent(?:age)?\b/iu', $value) ? $value : $value.'%';
        }

        $parts = preg_split('/\s+/u', $unit) ?: [];
        $currency = preg_match('/^[A-Z]{3}$/D', $parts[0] ?? '') ? array_shift($parts) : null;
        $remaining = implode(' ', $parts);
        if ($currency !== null && ! preg_match('/(?:^|\b)'.preg_quote($currency, '/').'(?:\b|$)|[$€£¥₹₦]/iu', $value)) {
            $value = $currency.' '.$value;
        }
        if ($remaining === '') {
            return $value;
        }
        if (preg_match('/^(billions?|millions?|thousands?|trillions?)$/iu', $remaining)) {
            $stem = preg_replace('/s$/iu', '', $remaining);
            return preg_match('/\b'.preg_quote($stem, '/').'s?\b/iu', $value) ? $value : $value.' '.$stem;
        }
        if (preg_match('/\b'.preg_quote($remaining, '/').'\b/iu', $value)) {
            return $value;
        }

        return $value.' '.$remaining;
    }
}
