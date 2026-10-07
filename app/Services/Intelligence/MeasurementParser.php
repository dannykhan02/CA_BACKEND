<?php

namespace App\Services\Intelligence;

use App\Services\Kpis\KpiLabelNormalizer;

/**
 * Turns the two strings DocIntel already stores for a metric finding - the observed `value` and
 * its `unit` - into a Measurement, or rejects the finding.
 *
 * Everything here is deterministic text handling. No provider call, no inference beyond what the
 * document itself wrote, and no currency conversion. The bias is rejection: a finding that cannot
 * be read unambiguously is simply not chartable, because a missing chart is cheap and a wrong one
 * is not.
 */
class MeasurementParser
{
    /** Written scales. Only accepted immediately after the number or inside the unit. */
    private const SCALES = [
        'trillion' => 1e12, 'trillions' => 1e12, 'tn' => 1e12, 'tr' => 1e12,
        'billion' => 1e9, 'billions' => 1e9, 'bn' => 1e9, 'bln' => 1e9, 'b' => 1e9,
        'million' => 1e6, 'millions' => 1e6, 'mn' => 1e6, 'mln' => 1e6, 'm' => 1e6, 'mm' => 1e6,
        'thousand' => 1e3, 'thousands' => 1e3, 'k' => 1e3,
    ];

    /** ISO codes and unambiguous symbols. A bare "$" stays its own family: it is not provably USD. */
    private const CURRENCIES = [
        'usd' => 'USD', 'kes' => 'KES', 'ksh' => 'KES', 'kshs' => 'KES', 'kshs.' => 'KES',
        'eur' => 'EUR', 'gbp' => 'GBP', 'ngn' => 'NGN', 'zar' => 'ZAR', 'tzs' => 'TZS',
        'ugx' => 'UGX', 'rwf' => 'RWF', 'ghs' => 'GHS', 'zmw' => 'ZMW', 'xaf' => 'XAF',
        'xof' => 'XOF', 'inr' => 'INR', 'cny' => 'CNY', 'rmb' => 'CNY', 'jpy' => 'JPY',
        'aed' => 'AED', 'sar' => 'SAR', 'cad' => 'CAD', 'aud' => 'AUD', 'chf' => 'CHF',
        'sek' => 'SEK', 'nok' => 'NOK', 'dkk' => 'DKK', 'brl' => 'BRL', 'mxn' => 'MXN',
        'try' => 'TRY', 'egp' => 'EGP', 'mad' => 'MAD', 'etb' => 'ETB', 'mur' => 'MUR',
        '€' => 'EUR', '£' => 'GBP', '₹' => 'INR', '₦' => 'NGN', '¥' => 'JPY',
        'sdr' => 'SDR', 'ua' => 'UA',
    ];

    private const DURATIONS = ['second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days',
        'week', 'weeks', 'month', 'months', 'quarter', 'quarters', 'year', 'years', 'fte', 'man hours'];

    /**
     * Units that identify a locator rather than a measurement. A page or clause number is a
     * number about the document, never a figure from it.
     */
    private const LOCATORS = ['page', 'pages', 'p', 'pp', 'clause', 'clauses', 'section', 'sections',
        'paragraph', 'paragraphs', 'article', 'articles', 'note', 'notes', 'table', 'tables',
        'figure', 'figures', 'schedule', 'schedules', 'annex', 'appendix', 'item', 'line'];

    public function __construct(private KpiLabelNormalizer $normalizer) {}

    /** The measurement this finding states, or null when it cannot be read unambiguously. */
    public function parse(?string $value, ?string $unit, ?string $label = null): ?Measurement
    {
        $valueText = trim((string) $value);
        $unitText = trim((string) $unit);
        if ($valueText === '') {
            return null;
        }

        $number = $this->number($valueText);
        if ($number === null) {
            return null;
        }
        [$magnitude, $numberEnd, $hadPercentSign] = $number;

        $unitTokens = $this->tokens($unitText);
        if (array_intersect($unitTokens, self::LOCATORS) !== []) {
            return null;
        }

        // A scale may be written beside the number ("12.4 billion") or inside the unit
        // ("USD billion"); never both, because that would multiply the document's own statement.
        $trailing = $this->tokens(mb_substr($valueText, $numberEnd));
        $valueScale = $this->scale($trailing, true);
        $unitScale = $this->scale($unitTokens, false);
        if ($valueScale !== null && $unitScale !== null && $valueScale !== $unitScale) {
            return null;
        }
        $scale = $valueScale ?? $unitScale ?? 1.0;

        $currency = $this->currency($valueText) ?? $this->currency($unitText);
        $percent = $hadPercentSign || $this->isPercent($valueText, $unitTokens);
        if ($percent && $currency !== null) {
            // "12% of USD 4bn" and similar are two measurements in one string.
            return null;
        }

        $family = $this->family($unitTokens, $unitText, $currency, $percent, $valueText);
        $kind = $this->kind($currency, $percent, $unitTokens, $family, $label);
        if ($kind === 'unknown' && $family === '' && $this->looksLikeYear($magnitude, $valueText)) {
            // A bare year with no unit is a date, not a quantity.
            return null;
        }

        return new Measurement(
            magnitude: $magnitude * ($percent ? 1.0 : $scale),
            kind: $kind,
            currency: $currency,
            family: $family,
            scale: $percent ? 1.0 : $scale,
            unitText: $unitText !== '' ? $unitText : ($percent ? '%' : ''),
        );
    }

    /**
     * The single number this value states, as [magnitude, byte offset after it, had a percent sign].
     * A second, unrelated number ("USD 12.4bn (2023: 10.7bn)", "10 of 20") makes the observation
     * ambiguous and rejects it; digits belonging to the same written number do not.
     */
    private function number(string $text): ?array
    {
        if (! preg_match('/-?\d[\d,.\s]*/u', $text, $first, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $raw = rtrim($first[0][0], " \t,.");
        $start = $first[0][1];
        $end = $start + strlen($raw);
        $rest = substr($text, $end);
        if (preg_match('/\d/u', $rest)) {
            return null;
        }
        // Thousands separators only; a comma used as a decimal mark is not assumed either way.
        if (preg_match('/,\d{1,2}$|,\d{4,}|\.\d+\./u', $raw)) {
            return null;
        }
        $clean = str_replace([',', ' ', "\u{00a0}"], '', $raw);
        if (! is_numeric($clean)) {
            return null;
        }
        $magnitude = (float) $clean;
        // Accounting negatives: "(1,234)" is minus 1,234.
        if ($magnitude > 0 && preg_match('/\(\s*$/u', substr($text, 0, $start)) && str_contains($rest, ')')) {
            $magnitude = -$magnitude;
        }

        return [$magnitude, $end, (bool) preg_match('/^\s*%/u', $rest)];
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $normalized = $this->normalizer->normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /** @param list<string> $tokens */
    private function scale(array $tokens, bool $mustBeFirst): ?float
    {
        foreach ($tokens as $index => $token) {
            if (isset(self::SCALES[$token])) {
                // Beside the number a scale must be the very next word, so "12 in 2024 millions"
                // cannot silently rescale a value that was never scaled.
                return $mustBeFirst && $index !== 0 ? null : self::SCALES[$token];
            }
            if ($mustBeFirst && $index === 0 && $token !== '') {
                return null;
            }
        }

        return null;
    }

    private function currency(string $text): ?string
    {
        foreach (self::CURRENCIES as $needle => $code) {
            $pattern = preg_match('/^\p{L}+$/u', $needle)
                ? '/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/iu'
                : '/'.preg_quote($needle, '/').'/u';
            if (preg_match($pattern, $text)) {
                return $code;
            }
        }

        // A bare dollar sign is a currency, but not a knowable one. Kept as its own family so
        // "$ 10m" never joins "USD 10m" and never joins "CAD 10m" either.
        return str_contains($text, '$') ? '$' : null;
    }

    /** @param list<string> $unitTokens */
    private function isPercent(string $valueText, array $unitTokens): bool
    {
        if (str_contains($valueText, '%')) {
            return true;
        }

        return array_intersect($unitTokens, ['percent', 'percentage', 'pct']) !== []
            && ! in_array('points', $unitTokens, true) && ! in_array('point', $unitTokens, true);
    }

    /**
     * The compatibility family. A percentage is "percent"; a currency is its code plus any
     * denominator the unit states; anything else is the unit's own words with scale words removed,
     * so "USD million" and "USD billion" share a family while "USD per share" does not.
     */
    private function family(array $unitTokens, string $unitText, ?string $currency, bool $percent, string $valueText): string
    {
        $words = array_values(array_filter($unitTokens, fn ($token) => ! isset(self::SCALES[$token]) && $token !== ''));
        if ($percent) {
            // "percentage points" is a different measurement from a share of a whole.
            return array_intersect($words, ['points', 'point']) !== [] ? 'percentage points' : 'percent';
        }
        if ($currency !== null) {
            $residual = array_values(array_diff($words, array_map('mb_strtolower', array_keys(self::CURRENCIES)),
                ['us', 'dollar', 'dollars', 'shilling', 'shillings', 'euro', 'euros', 'pound', 'pounds', 'naira', 'rand']));

            return trim($currency.' '.implode(' ', $residual));
        }

        return implode(' ', $words);
    }

    /** @param list<string> $unitTokens */
    private function kind(?string $currency, bool $percent, array $unitTokens, string $family, ?string $label): string
    {
        // Driven by the family, so a value written "2.1%" and one written "2.1" with unit
        // "percentage points" classify identically and stay in one group.
        if ($family === 'percentage points') {
            return 'change';
        }
        if ($percent) {
            return 'percent';
        }
        if ($currency !== null) {
            return 'currency';
        }
        if (array_intersect($unitTokens, self::DURATIONS) !== []) {
            return 'duration';
        }
        if (array_intersect($unitTokens, ['ratio', 'times', 'x']) !== []) {
            return 'ratio';
        }
        if ($unitTokens !== []) {
            return 'count';
        }
        if ($label !== null && preg_match('/\b(number of|count of|headcount|employees|staff|total number)\b/iu', $label)) {
            return 'count';
        }

        return 'unknown';
    }

    private function looksLikeYear(float $magnitude, string $valueText): bool
    {
        return $magnitude >= 1800 && $magnitude <= 2200 && floor($magnitude) === $magnitude
            && ! str_contains($valueText, ',') && ! str_contains($valueText, '.');
    }
}
