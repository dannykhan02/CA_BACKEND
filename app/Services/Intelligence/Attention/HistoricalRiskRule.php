<?php

namespace App\Services\Intelligence\Attention;

/** Conservative same-span historical-event exception, with no semantic inference. */
class HistoricalRiskRule
{
    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records */
    public function applies(array $record, array $records, \DateTimeImmutable $asOf): bool
    {
        if (($record['kind'] ?? null) !== 'risk') {
            return false;
        }
        $observed = $record['typed']['dates']['observed_date'] ?? null;
        $date = ($observed['resolution'] ?? null) === 'calendar' ? ($observed['date'] ?? null)
            : (($observed['resolution'] ?? null) === 'period' && ($observed['period']['anchored'] ?? false)
                ? ($observed['period']['end'] ?? null) : null);
        if (! is_string($date) || $date >= $asOf->format('Y-m-d')) {
            return false;
        }
        $spans = array_values(array_filter(array_column($record['sources'] ?? [], 'span_id'), 'is_string'));
        if ($spans === []) {
            return false;
        }
        foreach ($records as $other) {
            if (($other['identity'] ?? null) === ($record['identity'] ?? null)
                || array_intersect($spans, array_column($other['sources'] ?? [], 'span_id')) === []) {
                continue;
            }
            if (($other['status'] ?? null) === null || $other['status'] === 'open') {
                return false;
            }
            foreach ($other['typed']['dates'] ?? [] as $role) {
                $future = ($role['resolution'] ?? null) === 'calendar' ? ($role['date'] ?? null)
                    : (($role['resolution'] ?? null) === 'period' && ($role['period']['anchored'] ?? false)
                        ? ($role['period']['end'] ?? null) : null);
                if (is_string($future) && $future > $asOf->format('Y-m-d')) {
                    return false;
                }
            }
        }

        return true;
    }
}
