<?php

namespace App\Services\Intelligence;

/**
 * Reads the period text DocIntel stored with a metric finding. Deterministic patterns only:
 * anything that is not a recognisable reporting period is rejected rather than guessed, because a
 * misread period reorders a trend line and changes what the chart claims.
 */
class PeriodParser
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    public function parse(?string $period): ?ReportingPeriod
    {
        $text = trim((string) $period);
        if ($text === '') {
            return null;
        }
        // Leading qualifiers state when the figure was taken, not a different period.
        $text = (string) preg_replace('/^(?:as\s+(?:at|of)|for\s+the|in|during|ended|ending|year\s+ended|year\s+to)\s+/iu', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // Trailing noise a label can carry, never a different period.
        $text = trim((string) preg_replace('/\s*\.$/u', '', $text));
        // "FY2024" has no word boundary after FY, so the marker is matched by what follows it.
        $fiscal = (bool) preg_match('/\bfy(?![a-z])|\b(financial|fiscal)\s+year\b/iu', $text);

        return $this->endedDuration($text, $fiscal)
            ?? $this->day($text, $fiscal)
            ?? $this->quarter($text, $fiscal)
            ?? $this->half($text, $fiscal)
            ?? $this->month($text, $fiscal)
            ?? $this->year($text, $fiscal);
    }

    /** A duration heading is part of the period identity; its end date alone is not. */
    private function endedDuration(string $text, bool $fiscal): ?ReportingPeriod
    {
        if (! preg_match('/^(three|six|nine|twelve)\s+months?\s+ended\s+'
            .'([a-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})$/iuD', $text, $match)) {
            return null;
        }
        $month = $this->monthNumber($match[2]);
        $day = (int) $match[3];
        $year = (int) $match[4];
        if ($month === null || ! $this->validYear($year) || ! checkdate($month, $day, $year)
            || $day !== (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)))) {
            return null;
        }
        $duration = ['three' => 3, 'six' => 6, 'nine' => 9, 'twelve' => 12][mb_strtolower($match[1])];
        $basis = $this->basis($fiscal, $text);
        if ($duration === 3 && $month % 3 === 0) {
            $quarter = intdiv($month, 3);

            return new ReportingPeriod('quarter', $basis, $year + $quarter / 10,
                'Q'.$quarter.' '.($basis === 'fiscal' ? 'FY' : '').$year);
        }

        return new ReportingPeriod($duration.'_months', $basis, $year + $month / 100,
            $duration.'M '.($basis === 'fiscal' ? 'FY' : '').$year);
    }

    /** Complete a bare quarter only when one verified, governing source states its year and basis. */
    public function resolveQuarter(?string $period, string $governingText, string $valueText = ''): ?ReportingPeriod
    {
        $parsed = $this->parse($period);
        if ($parsed !== null) {
            return $parsed;
        }
        $partial = trim((string) $period);
        if (! preg_match('/^Q([1-4])$/iuD', $partial, $wanted)) {
            return null;
        }
        $candidates = [];
        foreach ([$governingText, $valueText] as $text) {
            preg_match_all('/\b(?:Q[1-4]\s*(?:FY\s*)?\d{4}|FY\s*\d{4}\s*Q[1-4]|\d{4}\s*Q[1-4])\b/iu',
                $text, $matches);
            foreach ($matches[0] as $match) {
                $candidate = $this->parse($match);
                if ($candidate === null || $candidate->granularity !== 'quarter') {
                    continue;
                }
                if (! str_starts_with($candidate->label, 'Q'.$wanted[1].' ')) {
                    return null;
                }
                $candidates[$candidate->label] = $candidate;
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
    }

    private function day(string $text, bool $fiscal): ?ReportingPeriod
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/u', $text, $iso)) {
            [$year, $month, $day] = [(int) $iso[1], (int) $iso[2], (int) $iso[3]];
        } elseif (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+([a-z]{3,9})\.?,?\s+(\d{4})$/iu', $text, $match)) {
            [$day, $month, $year] = [(int) $match[1], $this->monthNumber($match[2]), (int) $match[3]];
        } elseif (preg_match('/^([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/iu', $text, $match)) {
            [$month, $day, $year] = [$this->monthNumber($match[1]), (int) $match[2], (int) $match[3]];
        } else {
            return null;
        }
        if ($month === null || ! $this->validYear($year) || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new ReportingPeriod('day', $this->basis($fiscal, $text),
            $year + $month / 100 + $day / 10000, sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private function quarter(string $text, bool $fiscal): ?ReportingPeriod
    {
        $names = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4];
        if (preg_match('/^q([1-4])\s*(?:fy\s*)?[\/\-]?\s*(\d{2,4})(?:[\/\-]\d{2,4})?$/iu', $text, $match)) {
            [$quarter, $year] = [(int) $match[1], $this->expandYear($match[2])];
        } elseif (preg_match('/^(?:fy\s*)?(\d{4})\s*q([1-4])$/iu', $text, $match)) {
            [$quarter, $year] = [(int) $match[2], $this->expandYear($match[1])];
        } elseif (preg_match('/^(first|second|third|fourth)\s+quarter\s+(?:of\s+)?(?:fy\s*)?(\d{4})$/iu', $text, $match)) {
            [$quarter, $year] = [$names[mb_strtolower($match[1])], $this->expandYear($match[2])];
        } else {
            return null;
        }

        $basis = $this->basis($fiscal, $text);

        return $year === null ? null : new ReportingPeriod('quarter', $basis,
            $year + $quarter / 10, 'Q'.$quarter.' '.($basis === 'fiscal' ? 'FY' : '').$year);
    }

    private function half(string $text, bool $fiscal): ?ReportingPeriod
    {
        if (preg_match('/^h([12])\s*(?:fy\s*)?[\/\-]?\s*(\d{2,4})(?:[\/\-]\d{2,4})?$/iu', $text, $match)) {
            [$half, $year] = [(int) $match[1], $this->expandYear($match[2])];
        } elseif (preg_match('/^(first|second)\s+half\s+(?:of\s+)?(?:fy\s*)?(\d{4})$/iu', $text, $match)) {
            [$half, $year] = [mb_strtolower($match[1]) === 'second' ? 2 : 1, $this->expandYear($match[2])];
        } else {
            return null;
        }

        $basis = $this->basis($fiscal, $text);

        return $year === null ? null : new ReportingPeriod('half', $basis,
            $year + $half / 10, 'H'.$half.' '.($basis === 'fiscal' ? 'FY' : '').$year);
    }

    private function month(string $text, bool $fiscal): ?ReportingPeriod
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/u', $text, $match)) {
            [$year, $number] = [(int) $match[1], (int) $match[2]];
        } elseif (preg_match('/^([a-z]{3,9})\.?,?\s+(?:fy\s*)?(\d{4})$/iu', $text, $match)) {
            [$year, $number] = [(int) $match[2], $this->monthNumber($match[1])];
        } else {
            return null;
        }
        if ($number === null || $number < 1 || $number > 12 || ! $this->validYear($year)) {
            return null;
        }

        $basis = $this->basis($fiscal, $text);

        return new ReportingPeriod('month', $basis,
            $year + $number / 100, ($basis === 'fiscal' ? 'FY' : '').sprintf('%04d-%02d', $year, $number));
    }

    /**
     * Calendar years, and the fiscal forms that name a single year: "FY2024", "FY2024/25",
     * "2024/25". A span of two full calendar years ("2023-2024") is not one period.
     */
    private function year(string $text, bool $fiscal): ?ReportingPeriod
    {
        if (! preg_match('/^(?:fy|financial year|fiscal year)?\s*[\'’]?(\d{4})(?:\s*[\/\-]\s*(\d{2})(?!\d))?$/iu', $text, $match)) {
            return null;
        }
        $year = (int) $match[1];
        if (! $this->validYear($year)) {
            return null;
        }
        $spans = ($match[2] ?? '') !== '';
        if ($spans && ($year + 1) % 100 !== (int) $match[2]) {
            // "2024/26" is not a reporting year DocIntel can place.
            return null;
        }

        $basis = $spans || $fiscal ? 'fiscal' : 'calendar';

        return new ReportingPeriod('year', $basis, (float) $year,
            $spans ? 'FY'.$year.'/'.$match[2] : ($basis === 'fiscal' ? 'FY'.$year : (string) $year));
    }

    private function basis(bool $fiscal, string $text): string
    {
        return $fiscal || preg_match('/\bfy(?![a-z])/iu', $text) ? 'fiscal' : 'calendar';
    }

    private function monthNumber(string $name): ?int
    {
        return self::MONTHS[mb_strtolower(mb_substr($name, 0, 3))] ?? null;
    }

    private function validYear(int|string $year): bool
    {
        return (int) $year >= self::MIN_YEAR && (int) $year <= self::MAX_YEAR;
    }

    private function expandYear(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // A two-digit year is only expanded where the pattern already fixed the century.
        $year = strlen($raw) === 2 ? 2000 + (int) $raw : (int) $raw;

        return $this->validYear($year) ? $year : null;
    }
}
