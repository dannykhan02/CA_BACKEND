<?php

namespace App\Services\Intelligence;

use App\Services\AI\Incremental\EvidenceMerger;

/** A narrow, single-quote notation exception to CR-001's value substring test. */
class NumericEquivalentGrounding
{
    private const QUANTITY = '(?:(?:more\s+than|less\s+than|over|under|nearly|approximately|about|circa)\s+)?'
        .'(?:(?:USD|KES|EUR|GBP|NGN|ZAR|TZS|UGX|RWF|GHS|ZMW|XAF|XOF|INR|CNY|JPY|AED|SAR|CAD|AUD|CHF|SEK|NOK|DKK|BRL|MXN|TRY|EGP|MAD|ETB|MUR|SDR)\s*|[$€£₹₦]\s*)?[+-]?\d[\d,]*(?:\.\d+)?\s*'
        .'(?:%|per\s+cent|percent|thousands?|millions?|billions?|trillions?|[kmbt])?';

    public function __construct(private MeasurementParser $measurements, private EvidenceMerger $normalizer) {}

    /** @param array<string,mixed> $data */
    public function equivalent(array $data, string $quote): bool
    {
        if (($data['kind'] ?? null) !== 'metric' || trim($quote) === '') {
            return false;
        }
        $value = trim((string) ($data['value'] ?? ''));
        if (! preg_match('/^'.self::QUANTITY.'$/iu', $value)) {
            // Generated explanatory wording is not numeric notation.
            return false;
        }
        if (! $this->labelCompatible((string) ($data['label'] ?? ''), $quote)) {
            return false;
        }
        $stored = $this->measurements->parse($this->percentNotation($value),
            $data['unit'] ?? null, $data['label'] ?? null);
        if ($stored === null || ! is_finite($stored->magnitude)
            || $this->unrecognizedCurrencyPrefix($value, $stored)) {
            return false;
        }
        $candidates = $this->candidates($quote, $data);
        // In a quote with two quantities, their relationship to the stored label is not known.
        if (count($candidates) !== 1) {
            return false;
        }
        return $this->sameMeasurement($value, $stored, $candidates[0]);
    }

    /** A narrow guard for substring matches such as `20` inside `20%`. */
    public function contradictsSubstring(array $data, string $quote): bool
    {
        if (($data['kind'] ?? null) !== 'metric') {
            return false;
        }
        $value = trim((string) ($data['value'] ?? ''));
        if (! preg_match('/^'.self::QUANTITY.'$/iu', $value)) {
            return false;
        }
        if (! $this->labelCompatible((string) ($data['label'] ?? ''), $quote)) {
            return true;
        }
        $stored = $this->measurements->parse($this->percentNotation($value),
            $data['unit'] ?? null, $data['label'] ?? null);
        $candidates = $this->candidates($quote, $data);

        if ($stored === null || count($candidates) !== 1) {
            return false;
        }
        $cited = $candidates[0]['measurement'];
        $rawStored = $this->measurements->parse($this->percentNotation($value),
            null, $data['label'] ?? null);
        $storedMagnitude = $cited->scale === 1.0 && $stored->scale !== 1.0
            ? ($rawStored?->magnitude ?? $stored->magnitude) : $stored->magnitude;

        return $storedMagnitude !== $cited->magnitude
            || $this->qualifier($value) !== $this->qualifier($candidates[0]['token'])
            || (($stored->kind === 'percent') !== ($cited->kind === 'percent'))
            || ($stored->currency !== null && $cited->currency !== null
                && $stored->currency !== $cited->currency)
            || ($stored->scale !== 1.0 && $cited->scale !== 1.0
                && $stored->scale !== $cited->scale);
    }

    private function candidates(string $quote, array $data): array
    {
        preg_match_all('/(?<![\p{L}\d])'.self::QUANTITY.'(?![\p{L}\d])/iu', $quote, $matches);
        $candidates = [];
        foreach ($matches[0] as $token) {
            $measurement = $this->measurements->parse($this->percentNotation(trim($token)),
                null, $data['label'] ?? null);
            if ($measurement !== null && ! $this->unrecognizedCurrencyPrefix(trim($token), $measurement)) {
                $candidates[] = ['token' => trim($token), 'measurement' => $measurement];
            }
        }

        return $candidates;
    }

    private function sameMeasurement(string $value, Measurement $stored, array $candidate): bool
    {
        $cited = $candidate['measurement'];

        return $this->qualifier($value) === $this->qualifier($candidate['token'])
            && $stored->magnitude === $cited->magnitude
            && $stored->kind === $cited->kind
            && $stored->currency === $cited->currency
            && $stored->scale === $cited->scale
            && $stored->family === $cited->family;
    }

    private function unrecognizedCurrencyPrefix(string $text, Measurement $measurement): bool
    {
        // The quantity lexer sees any three-letter prefix; only parser-recognized codes may ground currency.
        return $measurement->currency === null && (bool) preg_match('/^[A-Z]{3}\s*\d/iu', trim($text));
    }

    private function percentNotation(string $text): string
    {
        return preg_replace('/\b(?:per\s+cent|percent)\b/iu', '%', $text);
    }

    private function qualifier(string $text): string
    {
        if (preg_match('/^\s*(more\s+than|less\s+than|over|under|nearly|approximately|about|circa)\b/iu',
            $text, $match)) {
            return match (mb_strtolower(preg_replace('/\s+/u', ' ', $match[1]))) {
                'more than', 'over' => 'greater_than',
                'less than', 'under' => 'less_than',
                'nearly' => 'nearly',
                'approximately', 'about', 'circa' => 'approximate',
            };
        }

        return 'exact';
    }

    private function labelCompatible(string $label, string $quote): bool
    {
        // A stated denominator is part of the metric's meaning. Never borrow a percentage
        // about a different denominator merely because the digits happen to agree.
        if (preg_match('/\b(?:percentage|share)\s+of\s+(.+)$/iu', $label, $match)) {
            $denominator = $this->normalizer->normalize($match[1]);

            return $denominator !== '' && str_contains($this->normalizer->normalize($quote), $denominator);
        }

        return true;
    }
}
