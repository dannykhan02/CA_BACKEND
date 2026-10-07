<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentIntelligenceSummary;

/**
 * Builds the document's key takeaways without a provider call.
 *
 * DocIntel's synthesis already writes grounded, source-referenced conclusions - material_findings
 * and trends, each validated against real evidence ids by ResponseValidator. They were simply not
 * surfaced as takeaways. Those come first here, unchanged in substance.
 *
 * The rest are stated by the evidence itself: a chart candidate's own first and last point, its
 * largest share, its highest category, and obligations whose explicit dates fall beyond the
 * reporting year. Each restates figures DocIntel already accepted and carries the same source
 * references, so a takeaway can always be opened back to the document.
 *
 * Every takeaway carries source references. Nothing ungrounded is ever presented as a takeaway:
 * fewer grounded conclusions is the better outcome, because a takeaway the reader cannot open back
 * to the document is not evidence, it is a claim.
 *
 * The synthesis also writes `key_findings`, plain strings with no references at all. Those are not
 * takeaways and never enter the list. They are returned separately by notes(), clearly as
 * unsupported summary text, and only for a document too thin to produce three grounded takeaways -
 * where suppressing them entirely would lose the only overview the document has.
 */
class TakeawayBuilder
{
    private const MAX = 8;

    private const MIN_USEFUL_CHARS = 25;

    /** Above this share of shared words, two takeaways are saying the same thing. */
    private const DUPLICATE_OVERLAP = 0.6;

    private const QUOTAS = ['synthesis' => 4, 'metric' => 3, 'trend' => 2, 'risk' => 2, 'obligation' => 1];

    /** Below this many grounded takeaways, unsupported summary text is offered separately. */
    private const MIN_GROUNDED = 3;

    private const MAX_NOTES = 3;

    /**
     * @param  list<array<string,mixed>>  $charts
     * @return list<array<string,mixed>>
     */
    public function build(Document $document, ?DocumentIntelligenceSummary $summary, array $charts): array
    {
        $candidates = [
            ...$this->fromSynthesis($summary),
            ...$this->fromCharts($charts),
            ...$this->fromTrends($summary),
            ...$this->fromRisks($document),
            ...$this->fromObligations($document),
        ];

        $taken = [];
        $used = [];
        foreach ($candidates as $candidate) {
            if (count($taken) >= self::MAX) {
                break;
            }
            $origin = $candidate['origin'];
            if (($used[$origin] ?? 0) >= self::QUOTAS[$origin]) {
                continue;
            }
            // A candidate that lost its references on the way here is not a takeaway.
            if ($candidate['sourceIds'] === []) {
                continue;
            }
            if (mb_strlen($candidate['text']) < self::MIN_USEFUL_CHARS || $this->duplicates($candidate['text'], $taken)) {
                continue;
            }
            $used[$origin] = ($used[$origin] ?? 0) + 1;
            $taken[] = $candidate;
        }

        foreach ($taken as $index => $takeaway) {
            $taken[$index]['id'] = 'takeaway-'.($index + 1);
        }

        return $taken;
    }

    /**
     * Unsupported summary text, kept apart from the takeaways and labelled as such.
     *
     * The synthesis' `key_findings` are prose without source references, so they cannot be shown
     * as evidence-backed. They are surfaced only when the grounded takeaways number fewer than
     * MIN_GROUNDED: for a document that produced little else, this is the only overview it has,
     * and dropping it silently would be a worse answer than showing it for what it is.
     *
     * @param  list<array<string,mixed>>  $takeaways  the grounded takeaways build() returned
     * @return list<array<string,mixed>>
     */
    public function notes(?DocumentIntelligenceSummary $summary, array $takeaways): array
    {
        if ($summary === null || count($takeaways) >= self::MIN_GROUNDED) {
            return [];
        }
        $notes = [];
        foreach ($summary->key_findings ?? [] as $finding) {
            if (count($notes) >= self::MAX_NOTES) {
                break;
            }
            if (! is_string($finding) || mb_strlen(trim($finding)) < self::MIN_USEFUL_CHARS) {
                continue;
            }
            if ($this->duplicates($finding, [...$takeaways, ...$notes])) {
                continue;
            }
            $notes[] = ['id' => 'note-'.(count($notes) + 1), 'text' => trim($finding), 'supported' => false];
        }

        return $notes;
    }

    /** @return list<array<string,mixed>> */
    private function fromSynthesis(?DocumentIntelligenceSummary $summary): array
    {
        $takeaways = [];
        foreach ($summary?->material_findings ?? [] as $finding) {
            if (! is_array($finding) || ! is_string($finding['title'] ?? null)) {
                continue;
            }
            $takeaways[] = $this->takeaway('synthesis', $finding['title'],
                is_string($finding['why_it_matters'] ?? null) ? $finding['why_it_matters'] : ($finding['explanation'] ?? null),
                $finding, $finding['severity'] ?? null);
        }

        return $takeaways;
    }

    /** @return list<array<string,mixed>> */
    private function fromTrends(?DocumentIntelligenceSummary $summary): array
    {
        $takeaways = [];
        foreach ($summary?->trends ?? [] as $trend) {
            if (is_array($trend) && is_string($trend['observation'] ?? null)) {
                $takeaways[] = $this->takeaway('trend', $trend['observation'],
                    is_string($trend['significance'] ?? null) ? $trend['significance'] : null, $trend);
            }
        }

        return $takeaways;
    }

    /**
     * Takeaways the charts themselves state. Each one only restates its own plotted points, so it
     * cannot claim more than the evidence behind those points.
     *
     * @param  list<array<string,mixed>>  $charts
     * @return list<array<string,mixed>>
     */
    private function fromCharts(array $charts): array
    {
        $takeaways = [];
        foreach ($charts as $chart) {
            $points = $chart['points'] !== [] ? $chart['points'] : ($chart['series'][0]['data'] ?? []);
            if (count($points) < 2) {
                continue;
            }
            $unit = (string) $chart['unit'];
            $text = null;
            if ($chart['basis'] === 'time_series' && $chart['series'] === []) {
                $first = $points[0];
                $last = $points[count($points) - 1];
                $change = (float) $first['value'] == 0.0 ? null
                    : ((float) $last['value'] - (float) $first['value']) / abs((float) $first['value']) * 100;
                $direction = match (true) {
                    (float) $last['value'] > (float) $first['value'] => 'increased',
                    (float) $last['value'] < (float) $first['value'] => 'decreased',
                    default => 'was unchanged',
                };
                $text = $direction === 'was unchanged'
                    ? sprintf('%s was unchanged at %s between %s and %s.', $chart['metric'],
                        $this->amount($last['value'], $unit), $first['label'], $last['label'])
                    : sprintf('%s %s from %s in %s to %s in %s%s.', $chart['metric'], $direction,
                        $this->amount($first['value'], $unit), $first['label'],
                        $this->amount($last['value'], $unit), $last['label'],
                        $change === null ? '' : sprintf(' (%+.1f%%)', $change));
            } elseif ($chart['basis'] === 'composition') {
                $text = sprintf('%s was the largest share of %s at %s.', $points[0]['label'],
                    lcfirst((string) $chart['metric']), $this->amount($points[0]['value'], $unit));
            } elseif ($chart['basis'] === 'categorical') {
                $text = sprintf('%s recorded the highest %s at %s.', $points[0]['label'],
                    lcfirst((string) $chart['metric']), $this->amount($points[0]['value'], $unit));
            }
            if ($text !== null) {
                $takeaways[] = ['origin' => 'metric', 'text' => $text, 'detail' => $chart['description'],
                    'basis' => 'explicit', 'severity' => null, 'sourceIds' => $chart['sourceIds'], 'chartId' => $chart['id']];
            }
        }

        return $takeaways;
    }

    /** @return list<array<string,mixed>> */
    private function fromRisks(Document $document): array
    {
        $takeaways = [];
        foreach ($document->risks->sortByDesc('confidence') as $risk) {
            if (! in_array($risk->severity, ['high', 'critical'], true)) {
                continue;
            }
            $takeaways[] = ['origin' => 'risk', 'text' => rtrim((string) $risk->title, '.').'.',
                'detail' => $risk->description, 'basis' => 'explicit', 'severity' => $risk->severity,
                'sourceIds' => ['risk:'.$risk->id], 'chartId' => null];
        }

        return $takeaways;
    }

    /**
     * Obligations the document dated beyond its own reporting year. Only explicit dates count, so
     * this never turns a relative or inferred deadline into a calendar claim.
     *
     * @return list<array<string,mixed>>
     */
    private function fromObligations(Document $document): array
    {
        $year = (int) ($document->year ?: 0);
        if ($year < 1900) {
            return [];
        }
        $beyond = $document->deadlines
            ->filter(fn ($deadline) => $deadline->date_type === 'explicit' && $deadline->due_date !== null
                && (int) $deadline->due_date->format('Y') > $year);
        if ($beyond->count() < 2) {
            return [];
        }
        $last = $beyond->sortByDesc(fn ($deadline) => $deadline->due_date)->first();

        return [[
            'origin' => 'obligation',
            'text' => sprintf('%d dated obligations extend beyond %d, the latest to %s.',
                $beyond->count(), $year, $last->due_date->format('j F Y')),
            'detail' => null,
            'basis' => 'explicit',
            'severity' => null,
            'sourceIds' => $beyond->take(4)->map(fn ($deadline) => 'deadline:'.$deadline->id)->values()->all(),
            'chartId' => null,
        ]];
    }

    /** @param array<string,mixed> $item */
    private function takeaway(string $origin, string $text, ?string $detail, array $item, ?string $severity = null): array
    {
        return [
            'origin' => $origin,
            'text' => rtrim(trim($text), '.').'.',
            'detail' => is_string($detail) ? trim($detail) : null,
            'basis' => in_array($item['basis'] ?? null, ['explicit', 'inferred'], true) ? $item['basis'] : null,
            'severity' => is_string($severity) ? $severity : null,
            'sourceIds' => array_values(array_filter((array) ($item['source_ids'] ?? []), 'is_string')),
            'chartId' => null,
        ];
    }

    /** @param list<array<string,mixed>> $taken */
    private function duplicates(string $text, array $taken): bool
    {
        $words = $this->words($text);
        if ($words === []) {
            return true;
        }
        foreach ($taken as $takeaway) {
            $other = $this->words($takeaway['text']);
            $shared = count(array_intersect($words, $other));
            if ($shared / max(1, min(count($words), count($other))) >= self::DUPLICATE_OVERLAP) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text));
        $words = array_values(array_filter(preg_split('/\s+/u', trim((string) $normalized)) ?: [],
            fn ($word) => mb_strlen($word) > 2));

        return array_values(array_unique($words));
    }

    /** Readable amount for takeaway prose, using the chart's own already-scaled values. */
    private function amount(float|int|string $value, string $unit): string
    {
        $value = (float) $value;
        $decimals = abs($value) >= 100 ? 0 : (abs($value) >= 10 ? 1 : 2);
        $formatted = rtrim(rtrim(number_format($value, $decimals), '0'), '.');
        $formatted = $formatted === '' || $formatted === '-' ? number_format($value, $decimals) : $formatted;

        return $unit === '%' ? $formatted.'%' : trim($formatted.' '.$unit);
    }
}
