<?php

namespace App\Services\AI\Incremental;

/** The accepted evidence-to-calendar-date match used by validation and read-only typing. */
class EvidenceDateRecognizer
{
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
