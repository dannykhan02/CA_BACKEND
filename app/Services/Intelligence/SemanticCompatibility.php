<?php

namespace App\Services\Intelligence;

/** Shared, record-local compatibility for a written quantity and a stored typed value. */
class SemanticCompatibility
{
    public const POLICY = 'semantic.v1';

    private const SCALE = ['thousand' => 3, 'million' => 6, 'billion' => 9, 'trillion' => 12,
        'k' => 3, 'm' => 6, 'bn' => 9, 'b' => 9, 'tn' => 12];

    private const UNITS = [
        'person' => 'people', 'persons' => 'people', 'people' => 'people',
        'child' => 'children', 'children' => 'children',
        'beneficiary' => 'beneficiaries', 'beneficiaries' => 'beneficiaries',
        'customer' => 'customers', 'customers' => 'customers',
        'employee' => 'employees', 'employees' => 'employees',
        'household' => 'households', 'households' => 'households',
        'case' => 'cases', 'cases' => 'cases', 'toilet' => 'toilets', 'toilets' => 'toilets',
        'litre' => 'litres', 'litres' => 'litres', 'liter' => 'litres', 'liters' => 'litres',
        'tonne' => 'tonnes', 'tonnes' => 'tonnes', 'ton' => 'tonnes', 'tons' => 'tonnes',
        'day' => 'days', 'days' => 'days', 'month' => 'months', 'months' => 'months',
        'year' => 'years', 'years' => 'years', 'unit' => 'unit', 'units' => 'unit',
        'share' => 'share', 'shares' => 'share',
    ];

    public function __construct(private MeasurementParser $measurements) {}

    /** @return array{ok:bool,reason:string} */
    public function compare(string $claim, array $value, ?string $context = null,
        bool $allowPointConversion = false): array
    {
        $source = (string) ($value['raw'] ?? '');
        $sourceUnit = (string) ($value['unit'] ?? '');
        $claimQuantity = $this->quantity($claim);
        $sourceQuantity = $this->quantity($source, $sourceUnit);
        if ($claimQuantity === null || $sourceQuantity === null || ! is_numeric($value['number'] ?? null)) {
            return $this->reject('unparsed_quantity');
        }
        $pointConversion = $allowPointConversion
            && $sourceQuantity['semantic'] === 'percentage_points'
            && $claimQuantity['semantic'] === 'basis_points'
            && $this->timesHundred($sourceQuantity['number']) === $claimQuantity['number'];
        if (! $pointConversion && $claimQuantity['number'] !== $sourceQuantity['number']) {
            return $this->reject('magnitude_mismatch');
        }
        if ($claimQuantity['qualifier'] !== $sourceQuantity['qualifier']) {
            return $this->reject('qualifier_mismatch');
        }
        if (! $pointConversion && $claimQuantity['semantic'] !== null
            && $claimQuantity['semantic'] !== $sourceQuantity['semantic']) {
            return $this->reject('unit_mismatch');
        }
        if ($sourceQuantity['semantic'] === 'rate' && $claimQuantity['semantic'] !== 'rate') {
            return $this->reject('rate_mismatch');
        }
        if ($claimQuantity['denominator'] !== $sourceQuantity['denominator']) {
            return $this->reject('denominator_mismatch');
        }
        $code = $this->recordCurrency($value);
        $sameUnresolvedSymbol = $claimQuantity['currency'] === '$' && $code === null
            && str_contains($source, '$');
        if ($claimQuantity['currency'] !== null && ! $sameUnresolvedSymbol && ($code === null
            || ($claimQuantity['currency'] !== '$' && $claimQuantity['currency'] !== $code)
            || ($claimQuantity['currency'] === '$' && ! str_contains($source, '$')))) {
            return $this->reject('currency_mismatch');
        }
        if ($sourceQuantity['semantic'] === 'currency' && $code === null && ! $sameUnresolvedSymbol) {
            return $this->reject('currency_unresolved');
        }
        if ($sourceQuantity['semantic'] === 'duration'
            || ($sourceQuantity['semantic'] === 'rate'
                && in_array($sourceQuantity['denominator'], ['days', 'months', 'years'], true))
            || in_array($value['unit_kind'] ?? null, ['duration'], true)) {
            // Existing typed values cannot represent a rate's numerator and denominator or a
            // duration's calendar meaning without losing information.
            return $this->reject('schema_required');
        }
        if ($context !== null && $this->oppositeDirection($context, $source.' '.($value['label'] ?? ''))) {
            return $this->reject('direction_mismatch');
        }

        return ['ok' => true, 'reason' => 'compatible'];
    }

    private function timesHundred(string $number): string
    {
        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '-');
        [$whole, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        $digits = ltrim($whole.$fraction, '0') ?: '0';
        $places = strlen($fraction) - 2;
        if ($places > 0) {
            $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$places).'.'.substr($digits, -$places);
            $digits = rtrim(rtrim($digits, '0'), '.');
        } else {
            $digits .= str_repeat('0', -$places);
        }

        return $negative && $digits !== '0' ? '-'.$digits : $digits;
    }

    public function recordCurrency(array $value): ?string
    {
        $currency = $value['currency'] ?? null;
        $unitCode = $this->measurements->isoCurrency($value['unit'] ?? null);
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency)) {
            return $unitCode === null || $unitCode === $currency ? $currency : null;
        }
        if ($currency === '$') {
            return $unitCode;
        }

        return null;
    }

    public function canonicalUnit(string $unit): ?string
    {
        return self::UNITS[mb_strtolower($unit)] ?? null;
    }

    /** @return array<string,string|null>|null */
    private function quantity(string $text, string $unit = ''): ?array
    {
        if (! preg_match('/(?<!\d)([+-]?\d[\d,]*(?:\.\d+)?)(?![\d,.])/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $raw = $match[1][0];
        $offset = $match[1][1];
        $before = substr($text, 0, $offset);
        $after = substr($text, $offset + strlen($raw));
        if (preg_match('/\d/u', $after) || ! preg_match('/^[+-]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/D', $raw)) {
            return null;
        }
        preg_match('/^\s*(trillion|billion|million|thousand|bn|tn|[kmb])\b/iu', $after, $scaleMatch);
        $scale = self::SCALE[strtolower($scaleMatch[1] ?? '')] ?? 0;
        if ($scale === 0 && preg_match('/\b(trillion|billion|million|thousand|bn|tn|[kmb])\b/iu', $unit, $scaleMatch)) {
            $scale = self::SCALE[strtolower($scaleMatch[1])] ?? 0;
        }
        $digits = str_replace(',', '', ltrim($raw, '+-'));
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $digits = $whole.$fraction;
        $fractionPlaces = strlen($fraction) - $scale;
        if ($fractionPlaces > 0) {
            $digits = str_pad($digits, $fractionPlaces + 1, '0', STR_PAD_LEFT);
            $number = substr($digits, 0, -$fractionPlaces).'.'.substr($digits, -$fractionPlaces);
            [$integer, $decimal] = explode('.', $number, 2);
            $number = (ltrim($integer, '0') ?: '0').'.'.rtrim($decimal, '0');
            $number = rtrim($number, '.');
        } else {
            $number = ltrim($digits.str_repeat('0', -$fractionPlaces), '0') ?: '0';
        }
        if (str_starts_with($raw, '-') && $number !== '0') {
            $number = '-'.$number;
        }
        $surface = mb_strtolower($text.' '.$unit);
        $semantic = $this->semantic($surface);
        $currency = $this->currency($text);
        if ($currency === null && $unit !== '') {
            $currency = $this->currency($unit);
        }
        if ($currency !== null && $semantic === null) {
            $semantic = 'currency';
        }
        $denominator = null;
        if (preg_match('/(?:\bper\s+(?!cent\b)|\/\s*)(\w+)\b/iu', $surface, $denom)) {
            $denominator = self::UNITS[$denom[1]] ?? null;
            if ($denominator === null) {
                return null;
            }
            $semantic = 'rate';
        }

        return ['number' => $number, 'semantic' => $semantic, 'currency' => $currency,
            'denominator' => $denominator, 'qualifier' => $this->qualifier($before)];
    }

    private function semantic(string $surface): ?string
    {
        if (preg_match('/\bbasis\s+points?\b/u', $surface)) {
            return 'basis_points';
        }
        if (preg_match('/\bpercentage\s+points?\b/u', $surface)) {
            return 'percentage_points';
        }
        if (preg_match('/%|\b(?:percent|percentage|per\s+cent)\b/u', $surface)) {
            return 'percent';
        }
        foreach (self::UNITS as $word => $canonical) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $surface)) {
                return in_array($canonical, ['days', 'months', 'years'], true) ? 'duration' : $canonical;
            }
        }

        return null;
    }

    private function currency(string $text): ?string
    {
        if (preg_match('/\bUS\s+dollars?\b/iu', $text)) {
            return 'USD';
        }
        if (($code = $this->measurements->isoCurrency($text)) !== null) {
            return $code;
        }
        if (preg_match('/[$€£]/u', $text, $m)) {
            return match ($m[0]) {
                '€' => 'EUR', '£' => 'GBP', default => '$'
            };
        }

        return null;
    }

    private function qualifier(string $before): string
    {
        return match (true) {
            (bool) preg_match('/\b(?:more than|over|greater than)\s*$/iu', $before) => 'greater_than',
            (bool) preg_match('/\b(?:less than|under)\s*$/iu', $before) => 'less_than',
            (bool) preg_match('/\b(?:at least)\s*$/iu', $before) => 'at_least',
            (bool) preg_match('/\b(?:at most|up to|no more than)\s*$/iu', $before) => 'at_most',
            (bool) preg_match('/(?:\b(?:about|approximately|around|circa|nearly)|~)\s*$/iu', $before) => 'approximate',
            default => 'exact',
        };
    }

    private function oppositeDirection(string $claim, string $source): bool
    {
        foreach ([['increase', 'decrease'], ['increased', 'decreased'], ['rose', 'fell'],
            ['profit', 'loss']] as [$positive, $negative]) {
            if ((preg_match('/\b'.$positive.'\b/iu', $claim) && preg_match('/\b'.$negative.'\b/iu', $source))
                || (preg_match('/\b'.$negative.'\b/iu', $claim) && preg_match('/\b'.$positive.'\b/iu', $source))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok:bool,reason:string} */
    private function reject(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
