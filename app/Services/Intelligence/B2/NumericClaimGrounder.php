<?php

namespace App\Services\Intelligence\B2;

use App\Services\Intelligence\Brief\BriefVerifier;
use App\Services\Intelligence\MeasurementParser;

/** Binds each prose quantity to one cited typed Stage A value. */
class NumericClaimGrounder
{
    private const NOUNS = [
        'person' => 'people', 'people' => 'people', 'beneficiary' => 'people',
        'beneficiaries' => 'people', 'customer' => 'customers', 'customers' => 'customers',
        'employee' => 'employees', 'employees' => 'employees', 'household' => 'households',
        'households' => 'households', 'case' => 'cases', 'cases' => 'cases',
        'toilet' => 'toilets', 'toilets' => 'toilets', 'litre' => 'litres', 'litres' => 'litres',
        'liter' => 'litres', 'liters' => 'litres', 'tonne' => 'tonnes', 'tonnes' => 'tonnes',
        'ton' => 'tonnes', 'tons' => 'tonnes', 'day' => 'days', 'days' => 'days',
        'year' => 'years', 'years' => 'years',
    ];

    private const PATTERN = '/(?<![\p{L}\d])(?:(USD|EUR|GBP|CAD|AUD|KES|US\s+dollars?|\$|€|£)\s*)?([+-]?\d(?:\d|,(?=\d))*(?:\.\d+)?(?:\s*(?:trillion|billion|million|thousand))?)(\s*(?:%|percent(?:age)?(?:\s+points?)?|basis\s+points?|people|person|beneficiar(?:y|ies)|customers?|employees?|households?|cases?|toilets?|litres?|liters?|tonnes?|tons?|days?|years?))?/iu';

    public function __construct(private MeasurementParser $parser, private BriefVerifier $brief) {}

    /** @param list<array<string,mixed>> $records */
    public function grounded(string $text, array $records): bool
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $mentions = [];
        foreach ($matches as $match) {
            $raw = $match[0][0];
            $offset = $match[0][1];
            $digits = $match[2][0];
            if (preg_match('/^\d{4}$/D', $digits) && (int) $digits >= 1800 && (int) $digits <= 2200) {
                continue;
            }
            $suffix = trim($match[3][0] ?? '');
            if (preg_match('/^(?:days?|years?)$/iu', $suffix)
                && preg_match('/\b(?:last|past|over|within|during|for|in|per)\s*$/iu',
                    substr($text, max(0, $offset - 25), min(25, $offset)))) {
                // BriefVerifier's duration check handles spans independently.
                continue;
            }
            $parsed = $this->parser->parse(trim(($match[1][0] ?? '').' '.$digits.
                (preg_match('/^(?:%|percent(?:age)?)$/iu', $suffix) ? ' '.$suffix : '')), null);
            if ($parsed === null) {
                return false;
            }
            $mentions[] = ['raw' => $raw, 'number' => $parsed->magnitude,
                'currency' => $match[1][0] ?? '', 'suffix' => $suffix, 'offset' => $offset,
                'end' => $offset + strlen($raw)];
        }

        foreach ($mentions as $index => $mention) {
            $left = $index === 0 ? 0 : $mentions[$index - 1]['end'];
            $right = $mentions[$index + 1]['offset'] ?? strlen($text);
            $local = substr($text, $left, $right - $left);
            $candidate = false;
            foreach ($records as $record) {
                if ($this->recordSupports($mention, $local, $text, $record, $records)) {
                    $candidate = true;
                    break;
                }
            }
            if (! $candidate) {
                return false;
            }
        }

        return true;
    }

    private function recordSupports(array $mention, string $local, string $text,
        array $record, array $records): bool
    {
        $value = $record['typed']['value'] ?? null;
        if (! $this->brief->groundableValue($value)) {
            return false;
        }
        if ((float) $value['number'] !== (float) $mention['number']) {
            return false;
        }
        if (! $this->metricIdentified($local, $record)
            && ! ($this->allLabelsSame($records) && $this->metricIdentified($text, $record))) {
            return false;
        }
        $type = $value['type'];
        $currency = $mention['currency'];
        if ($currency !== '') {
            if ($type !== 'money' || ! $this->currencyMatches($currency, $value)) {
                return false;
            }
        }
        $claimUnit = $this->unitClass($mention['suffix']);
        $recordUnit = $this->unitClass((string) ($value['unit'] ?? ''));
        if ($claimUnit !== null) {
            if ($claimUnit === 'percent' && $type !== 'percent') {
                return false;
            }
            if ($claimUnit !== 'percent' && $claimUnit !== $recordUnit) {
                return false;
            }
        } elseif ($currency === '' && ! $this->metricUnambiguous($local, $record, $records)) {
            // An untyped number needs the same record's metric wording to recover its meaning.
            return false;
        }
        if ($recordUnit === 'basis_points'
            || ($recordUnit === 'percentage_points' && $claimUnit !== 'percentage_points')) {
            return false;
        }
        if ($this->denominator((string) ($value['unit'] ?? '')) !== $this->denominator($local)) {
            return false;
        }
        if ($this->qualifier($local, $mention['raw']) !== $this->qualifier((string) $value['raw'])) {
            return false;
        }
        if (! $this->relationOwned($local, $record, $records)
            || ! $this->entityOwned($local, $record, $records)
            || ! $this->periodOwned($local, $mention['raw'], $record, $records)) {
            return false;
        }

        return true;
    }

    private function currencyMatches(string $claim, array $value): bool
    {
        $code = $this->brief->recordCurrencyCode($value);
        $claim = mb_strtoupper(trim($claim));
        if (in_array($claim, ['$', 'US DOLLAR', 'US DOLLARS'], true)) {
            return $code === 'USD' && ($claim !== '$' || str_contains((string) $value['raw'], '$'));
        }

        return match ($claim) {
            '€' => $code === 'EUR', '£' => $code === 'GBP', default => $code === $claim,
        };
    }

    private function unitClass(string $text): ?string
    {
        if (preg_match('/\bbasis\s+points?\b/iu', $text)) {
            return 'basis_points';
        }
        if (preg_match('/\bpercent(?:age)?\s+points?\b/iu', $text)) {
            return 'percentage_points';
        }
        if (preg_match('/%|\bpercent(?:age)?\b/iu', $text)) {
            return 'percent';
        }
        foreach (self::NOUNS as $word => $class) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $text)) {
                return $class;
            }
        }

        return null;
    }

    private function denominator(string $text): ?string
    {
        if (preg_match('/\bper\s+(person|people|household|households|unit|year|years)\b/iu', $text, $match)) {
            return self::NOUNS[mb_strtolower($match[1])] ?? mb_strtolower($match[1]);
        }

        return null;
    }

    private function qualifier(string $text, ?string $needle = null): string
    {
        $position = $needle === null ? (preg_match('/\d/u', $text, $match, PREG_OFFSET_CAPTURE)
            ? $match[0][1] : 0) : strpos($text, $needle);
        $before = substr($text, 0, $position === false ? 0 : $position);
        if (preg_match('/\b(?:more than|over|greater than)\s*[$€£]?\s*$/iu', $before)) {
            return 'strict_lower_bound';
        }
        if (preg_match('/\bat least\s*[$€£]?\s*$/iu', $before)) {
            return 'inclusive_lower_bound';
        }
        if (preg_match('/\b(?:less than|under)\s*[$€£]?\s*$/iu', $before)) {
            return 'strict_upper_bound';
        }
        if (preg_match('/\b(?:up to|at most)\s*[$€£]?\s*$/iu', $before)) {
            return 'inclusive_upper_bound';
        }
        if (preg_match('/(?:\b(?:approximately|about|around|circa)|~)\s*[$€£]?\s*$/iu', $before)) {
            return 'approximate';
        }

        return 'exact';
    }

    private function metricIdentified(string $local, array $record): bool
    {
        $label = (string) ($record['data']['label'] ?? '');
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($label)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word) => mb_strlen($word) >= 4
            && ! in_array($word, ['from', 'with', 'under', 'total', 'amount'], true)));
        if ($words === []) {
            return false;
        }
        $local = mb_strtolower($local);
        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $local)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $records */
    private function allLabelsSame(array $records): bool
    {
        $labels = array_unique(array_map(static fn (array $record) => mb_strtolower(trim(
            (string) ($record['data']['label'] ?? ''))), $records));

        return count($labels) === 1;
    }

    /** @param list<array<string,mixed>> $records */
    private function metricUnambiguous(string $local, array $record, array $records): bool
    {
        $own = $this->labelWords($record);
        $other = [];
        foreach ($records as $candidate) {
            if ($candidate !== $record) {
                $other = [...$other, ...$this->labelWords($candidate)];
            }
        }
        $distinctive = array_diff($own, $other);
        if ($distinctive === [] && array_diff($other, $own) === []) {
            return true;
        }

        return $this->containsAny(mb_strtolower($local), $distinctive);
    }

    /** @param list<array<string,mixed>> $records */
    private function relationOwned(string $local, array $record, array $records): bool
    {
        $ownWords = $this->labelWords($record);
        $otherWords = [];
        foreach ($records as $other) {
            if ($other !== $record) {
                $otherWords = [...$otherWords, ...$this->labelWords($other)];
            }
        }
        $distinctive = array_diff($ownWords, $otherWords);
        $otherDistinctive = array_diff($otherWords, $ownWords);
        $local = mb_strtolower($local);
        $mentionsOther = $this->containsAny($local, $otherDistinctive);
        if ($mentionsOther && ! $this->containsAny($local, $distinctive)) {
            return false;
        }
        if ($distinctive !== [] && $this->containsAny($local, $distinctive) === false
            && $mentionsOther) {
            return false;
        }
        $label = mb_strtolower((string) ($record['data']['label'] ?? ''));
        foreach ([['loss', 'profit'], ['decrease', 'increase'], ['expense', 'revenue']] as [$a, $b]) {
            if ((preg_match('/\b'.$a.'\b/u', $label) && preg_match('/\b'.$b.'\b/u', $local))
                || (preg_match('/\b'.$b.'\b/u', $label) && preg_match('/\b'.$a.'\b/u', $local))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $records */
    private function entityOwned(string $local, array $record, array $records): bool
    {
        $subjects = array_values(array_unique(array_filter(array_map(
            static fn (array $item) => $item['data']['subject'] ?? null, $records),
            static fn ($subject) => is_string($subject) && $subject !== '')));
        if (count($subjects) <= 1) {
            return true;
        }
        usort($subjects, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($subjects as $subject) {
            if (preg_match('/(?<!\p{L})'.preg_quote($subject, '/').'(?!\p{L})/iu', $local)) {
                return $subject === ($record['data']['subject'] ?? null);
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $records */
    private function periodOwned(string $local, string $raw, array $record, array $records): bool
    {
        $periods = array_unique(array_filter(array_map(fn (array $item) => $item['typed']['dates']['period_covered']['period']['text'] ?? null, $records), 'is_string'));
        if (count($periods) <= 1) {
            return true;
        }
        $at = strpos($local, $raw);
        $after = $at === false ? '' : substr($local, $at + strlen($raw), 35);
        $before = $at === false ? '' : substr($local, max(0, $at - 35), min(35, $at));
        $year = null;
        if (preg_match('/\b(?:19|20)\d{2}\b/u', $after, $m)) {
            $year = $m[0];
        } elseif (preg_match_all('/\b(?:19|20)\d{2}\b/u', $before, $m) && $m[0] !== []) {
            $year = end($m[0]);
        }
        $recordPeriod = $record['typed']['dates']['period_covered']['period']['text'] ?? null;

        return $year !== null && $recordPeriod === $year;
    }

    private function labelWords(array $record): array
    {
        $label = mb_strtolower((string) ($record['data']['label'] ?? ''));
        $words = preg_split('/[^\p{L}]+/u', $label) ?: [];

        return array_values(array_unique(array_filter($words,
            static fn (string $word) => mb_strlen($word) >= 4
                && ! in_array($word, ['from', 'with', 'under', 'total', 'amount', 'million',
                    'billion', 'year', 'income', 'resources', 'core', 'sector', 'people'], true))));
    }

    private function containsAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $text)) {
                return true;
            }
        }

        return false;
    }
}
