<?php

namespace App\Services\AI\Incremental;

/** The accepted evidence-to-calendar-date match used by validation and read-only typing. */
class EvidenceDateRecognizer
{
    /** @return list<array{raw:string,date:string}> Complete dates under the evidence grounding grammar. */
    public static function datesIn(string $text): array
    {
        $dates = [];
        foreach (self::candidatesIn($text) as $raw) {
            $candidate = null;
            if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/D', $raw, $parts)) {
                if ((int) $parts[1] > 12 && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
                    $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $parts[3], $parts[2], $parts[1]));
                }
            } elseif (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/D', $raw, $parts)) {
                if (checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]));
                }
            } else {
                try {
                    $candidate = new \DateTimeImmutable($raw);
                } catch (\Exception) {
                    // An invalid date-like string cannot ground a date.
                }
            }
            if ($candidate !== null && self::statesDate($raw, $candidate)) {
                $dates[] = ['raw' => $raw, 'date' => $candidate->format('Y-m-d')];
            }
        }

        return $dates;
    }

    /** @return list<string> Date-shaped tokens, including invalid calendar dates. */
    public static function candidatesIn(string $text): array
    {
        preg_match_all('/(?<!\d)\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?!\d)|(?<!\d)\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}(?!\d)|(?<!\d)\d{1,2}(?:st|nd|rd|th)?[\s,.-]+[A-Za-z]{3,9}\.?(?:[\s,.-]+)\d{4}(?!\d)|(?<!\w)[A-Za-z]{3,9}\.?[\s,.-]+\d{1,2}(?:st|nd|rd|th)?[\s,.-]+\d{4}(?!\d)/iu', $text, $matches);

        return $matches[0];
    }

    public static function statesDate(string $quote, \DateTimeImmutable $date): bool
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('d');
        $numeric = '/(?<!\d)'.preg_quote($year, '/').'[-\/.]'.preg_quote($month, '/').'[-\/.]'.preg_quote($day, '/').'(?!\d)/u';
        if (preg_match($numeric, $quote)) {
            return true;
        }

        $monthName = '(?:'.preg_quote($date->format('F'), '/').'|'.preg_quote($date->format('M'), '/').'\.?)';
        $dayNumber = '0?'.(int) $day.'(?:st|nd|rd|th)?';
        $separator = '[\s,.-]+';
        foreach ([
            '/(?<!\d)'.$dayNumber.$separator.$monthName.$separator.$year.'(?!\d)/iu',
            '/(?<!\w)'.$monthName.$separator.$dayNumber.$separator.$year.'(?!\d)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $quote)) {
                return true;
            }
        }

        // Day/month/year is unambiguous only when the day is greater than twelve.
        if ((int) $day > 12 && preg_match('/(?<!\d)'.(int) $day.'[\/.-]0?'.(int) $month.'[\/.-]'.$year.'(?!\d)/u', $quote)) {
            return true;
        }

        return false;
    }
}
