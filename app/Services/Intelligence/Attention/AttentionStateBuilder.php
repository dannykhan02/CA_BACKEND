<?php

namespace App\Services\Intelligence\Attention;

/** Read-only attention projections; record kind and severity are never rewritten. */
class AttentionStateBuilder
{
    /** @param array<string,mixed> $settings */
    public function __construct(private HistoricalRiskRule $historical, private array $settings) {}

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $assignment  unified scorer output when materiality integration is enabled
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function build(array $record, array $assignment, array $records, \DateTimeImmutable $asOf): array
    {
        $dueRole = $record['typed']['dates']['due_date'] ?? null;
        $due = ($dueRole['resolution'] ?? null) === 'calendar' ? ($dueRole['date'] ?? null) : null;
        if ($this->historical->applies($record, $records, $asOf)) {
            return $this->state('informational', ['historical_context'], $due, $asOf);
        }
        if (in_array($record['status'] ?? null, $this->settings['terminal_statuses'], true)
            || (($record['kind'] ?? null) === 'unresolved' && ! empty($record['data']['resolved_evidence_id']))) {
            return $this->state('resolved', [], $due, $asOf);
        }
        $forced = $assignment['forced_rule'] ?? null;
        if (($assignment['tier'] ?? null) === 1
            && in_array($forced, $this->settings['needs_attention_forced_rules'], true)) {
            return $this->state('needs_attention', [$forced], $due, $asOf);
        }
        $futureDue = $due ?? (($dueRole['resolution'] ?? null) === 'period'
            && ($dueRole['period']['anchored'] ?? false) ? ($dueRole['period']['end'] ?? null) : null);
        if (($assignment['tier'] ?? null) === 1 || (($assignment['tier'] ?? null) === 2
            && is_string($futureDue) && $futureDue > $asOf->format('Y-m-d'))) {
            return $this->state('watch', is_string($forced) ? [$forced] : [], $due, $asOf);
        }

        return $this->state('informational', [], $due, $asOf);
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $coverage @return array<string,mixed> */
    public function summary(array $items, array $coverage, int $forcedOverflow, \DateTimeImmutable $asOf): array
    {
        $needs = count(array_filter($items, fn ($item) => ($item['state'] ?? null) === 'needs_attention'));
        $watch = count(array_filter($items, fn ($item) => ($item['state'] ?? null) === 'watch'));
        $dates = array_values(array_filter(array_column($items, 'due'),
            fn ($date) => is_string($date) && $date >= $asOf->format('Y-m-d')));
        sort($dates);

        return ['state' => $needs > 0 ? 'needs_attention' : ($watch > 0 ? 'watch'
            : (($coverage['state'] ?? null) === 'complete' ? 'clear' : 'unknown')),
            'needs_attention_count' => $needs, 'watch_count' => $watch,
            'next_due' => $dates[0] ?? null, 'coverage' => $coverage,
            'forced_overflow' => $forcedOverflow];
    }

    /** @param list<string> $reasons @return array<string,mixed> */
    private function state(string $state, array $reasons, ?string $due, \DateTimeImmutable $asOf): array
    {
        return ['state' => $state, 'reasons' => $reasons, 'due' => $due,
            'as_of' => $asOf->format(\DateTimeInterface::ATOM)];
    }
}
