<?php

namespace App\Services\Intelligence\Materiality;

/** Assigns a typed primary date to the nearest approved lexical role. */
class DateRoleResolver
{
    /** @param array<string,mixed> $typed @param array<string,mixed> $record @param list<string> $quotes @return array<string,mixed> */
    public function resolve(array $typed, array $record, array $quotes): array
    {
        $dates = $typed['dates'] ?? [];
        if ($dates === []) {
            return $typed;
        }
        $primary = $dates['due_date'] ?? $dates['period_covered'] ?? reset($dates);
        if (! is_array($primary)) {
            return $typed;
        }
        $mention = (string) ($primary['raw'] ?? $primary['period']['text'] ?? '');
        $best = null;
        $tied = false;
        foreach ($quotes as $quote) {
            $dateAt = $mention !== '' ? mb_stripos($quote, $mention) : false;
            if ($dateAt === false) {
                continue;
            }
            foreach (config('intelligence_v2.materiality.date_role_patterns') as $role => $patterns) {
                foreach ($patterns as $pattern) {
                    if (str_contains($pattern, '...')) {
                        $limit = config('intelligence_v2.materiality.must_be_by_max_intervening_words');
                        [$before, $after] = array_map('trim', explode('...', $pattern, 2));
                        $before = str_replace('\\ ', '\\s+', preg_quote($before, '/'));
                        $after = str_replace('\\ ', '\\s+', preg_quote($after, '/'));
                        $regex = '/(?<!\p{L})'.$before.'(?:\s+\S+){0,'.$limit.'}\s+'.$after.'(?!\p{L})/iu';
                    } else {
                        $regex = '/(?<!\p{L})'.preg_quote($pattern, '/').'(?!\p{L})/iu';
                    }
                    preg_match_all($regex, $quote, $matches, PREG_OFFSET_CAPTURE);
                    foreach ($matches[0] as [$matched, $byteOffset]) {
                        $offset = mb_strlen(substr($quote, 0, $byteOffset));
                        $distance = min(abs($offset - $dateAt), abs($offset + mb_strlen($matched) - $dateAt));
                        if ($best === null || $distance < $best['distance']) {
                            $best = ['distance' => $distance, 'role' => $role];
                            $tied = false;
                        } elseif ($distance === $best['distance'] && $role !== $best['role']) {
                            $tied = true;
                        }
                    }
                }
            }
        }
        $kind = $record['kind'] ?? null;
        $fallback = in_array($kind, ['deadline', 'obligation'], true) ? 'due_date'
            : ($kind === 'metric' ? (($primary['resolution'] ?? null) === 'period' ? 'period_covered' : 'as_of_date')
                : 'observed_date');
        $role = $best === null ? $fallback : ($tied ? 'unresolved' : $best['role']);
        $typed['dates'] = [$role => $primary];

        return $typed;
    }
}
