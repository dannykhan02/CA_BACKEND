<?php

namespace App\Services\Intelligence;

use App\Services\Kpis\KpiLabelNormalizer;

/**
 * Derives chart candidates from accepted metric findings. DocIntel decides what is comparable;
 * the provider is never asked for chart JSON, and nothing here depends on a finding being a
 * canonical workspace KPI.
 *
 * Three shapes are produced, and only where the underlying comparison is one the document itself
 * made:
 *
 *  - time series: one metric, one measured subject, two or more comparable reporting periods in
 *    chronological order. Mixed granularities (a quarter beside a year) and mixed bases (FY2024
 *    beside calendar 2024) are never plotted together.
 *  - categorical: one metric, one period, several measured subjects. A total is dropped when its
 *    own parts are present, so one bar cannot dwarf the comparison it belongs to.
 *  - composition: a categorical or label-dimension set that is demonstrably parts of one whole -
 *    percentages that sum to about a hundred, or parts that sum to a stated total. Nothing
 *    becomes a pie chart on the strength of looking like a breakdown.
 *
 * Compatibility is decided upstream by MetricObservation::groupKey(), which already requires an
 * identical measurement family, currency, measure kind and basis. Scale is the one difference this
 * layer is allowed to reconcile: USD million and USD billion are the same measurement written two
 * ways. Currency is never converted.
 */
class ChartCandidateBuilder
{
    private const SCALE_WORDS = [1e12 => 'trillion', 1e9 => 'billion', 1e6 => 'million', 1e3 => 'thousand'];

    private const MAX_CATEGORIES = 12;

    private const MAX_SERIES = 5;

    /** A share of a whole, allowing for the rounding a report prints. */
    private const COMPOSITION_TOLERANCE_PERCENT = 3.0;

    /** Parts may miss a stated total by this fraction of it and still be its parts. */
    private const COMPOSITION_TOLERANCE_RATIO = 0.02;

    private const MIN_COMPOSITION_PARTS = 3;

    public function __construct(private KpiLabelNormalizer $normalizer) {}

    /**
     * @param  list<MetricObservation>  $observations
     * @param  list<string>  $citedSourceIds  source ids the document's synthesis referenced
     * @return array{candidates: list<array<string,mixed>>, rejected: array<string,int>}
     */
    public function build(array $observations, array $citedSourceIds = []): array
    {
        $cited = array_fill_keys($citedSourceIds, true);
        $rejected = ['insufficient_points' => 0, 'incomparable_periods' => 0, 'conflicting_values' => 0,
            'not_a_composition' => 0, 'single_category' => 0];
        $groups = [];
        foreach ($observations as $observation) {
            $groups[$observation->groupKey()][] = $observation;
        }

        $candidates = [];
        foreach ($groups as $key => $group) {
            foreach ([$this->timeSeries($key, $group, $rejected), $this->categorical($key, $group, $rejected)] as $candidate) {
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }
        foreach ($this->labelCompositions($observations, $rejected) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['score'] = $this->score($candidate, $cited);
        }
        usort($candidates, fn ($a, $b) => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);

        return ['candidates' => array_values($candidates), 'rejected' => $rejected];
    }

    /**
     * @param  list<MetricObservation>  $group
     * @param  array<string,int>  $rejected
     */
    private function timeSeries(string $key, array $group, array &$rejected): ?array
    {
        $dated = array_values(array_filter($group, fn (MetricObservation $o) => $o->period !== null));
        if (count($dated) < 2) {
            $rejected['insufficient_points']++;

            return null;
        }
        // One granularity and one basis only. The largest consistent set wins; a tie prefers the
        // set that is actually plottable (more points), never a mix.
        $buckets = [];
        foreach ($dated as $observation) {
            $buckets[$observation->period->granularity.'/'.$observation->period->basis][] = $observation;
        }
        if (count($buckets) > 1) {
            $rejected['incomparable_periods']++;
        }
        uasort($buckets, fn ($a, $b) => count($b) <=> count($a));
        $bucket = reset($buckets);
        if (count($bucket) < 2) {
            $rejected['insufficient_points']++;

            return null;
        }

        $bySubject = [];
        foreach ($bucket as $observation) {
            $bySubject[$this->normalizer->normalize($observation->subject)][$observation->period->label][] = $observation;
        }
        $series = [];
        foreach ($bySubject as $subject => $byPeriod) {
            $points = $this->resolvePeriods($byPeriod, $rejected);
            if (count($points) >= 2) {
                $series[$subject] = $points;
            }
        }
        if ($series === []) {
            $rejected['insufficient_points']++;

            return null;
        }

        // Several subjects observed over exactly the same periods are one comparison the document
        // made; anything less aligned stays a single series, because a line that silently changes
        // what it measures is worse than no line.
        $aligned = count($series) > 1 && count($series) <= self::MAX_SERIES
            && count(array_unique(array_map(fn ($points) => implode('|', array_keys($points)), $series))) === 1;
        if (! $aligned) {
            uksort($series, function ($a, $b) use ($series, $bySubject) {
                $weight = fn ($subject) => [count($series[$subject]),
                    (int) (bool) array_filter(array_merge(...array_values($bySubject[$subject])), fn ($o) => $o->isTotal)];

                return [...$weight($b), $a] <=> [...$weight($a), $b];
            });
            $series = array_slice($series, 0, 1, true);
        }

        $observations = array_merge(...array_map(fn ($points) => array_values($points), array_values($series)));
        $display = $this->display($observations);
        $periods = array_keys(reset($series));

        return [
            'id' => 'ts-'.substr(hash('sha256', $key.'|'.implode(',', array_keys($series))), 0, 16),
            'basis' => 'time_series',
            'type' => 'line',
            'title' => $this->title($observations),
            'description' => $this->describe($periods[0].'–'.$periods[count($periods) - 1], $display['unit']),
            'metric' => $this->title($observations),
            ...$display,
            'points' => count($series) > 1 ? [] : $this->points(reset($series), $display['scale']),
            'series' => count($series) > 1 ? array_map(fn ($subject, $points) => [
                'name' => $this->subjectName($subject, $bySubject),
                'data' => $this->points($points, $display['scale']),
            ], array_keys($series), array_values($series)) : [],
            'sourceIds' => array_values(array_unique(array_map(fn ($o) => $o->sourceId, $observations))),
        ];
    }

    /**
     * One observation per period. Where a period was observed more than once the most confident
     * reading wins; where equally confident readings disagree the period is dropped, because
     * DocIntel cannot know which one the chart should claim.
     *
     * @param  array<string,list<MetricObservation>>  $byPeriod
     * @return array<string,MetricObservation>
     */
    private function resolvePeriods(array $byPeriod, array &$rejected): array
    {
        $points = [];
        foreach ($byPeriod as $label => $candidates) {
            usort($candidates, fn ($a, $b) => $b->confidence <=> $a->confidence);
            $best = $candidates[0];
            $rivals = array_filter($candidates, fn ($o) => $o->confidence === $best->confidence
                && abs($o->measurement->magnitude - $best->measurement->magnitude) > 1e-9);
            if ($rivals !== []) {
                $rejected['conflicting_values']++;

                continue;
            }
            $points[$label] = $best;
        }
        uasort($points, fn ($a, $b) => $a->period->sortKey <=> $b->period->sortKey);

        return $points;
    }

    /**
     * @param  list<MetricObservation>  $group
     * @param  array<string,int>  $rejected
     */
    private function categorical(string $key, array $group, array &$rejected): ?array
    {
        // Every subject must be measured in the same period, or the bars compare different times.
        $byPeriod = [];
        foreach ($group as $observation) {
            $byPeriod[$observation->period?->label ?? ''][] = $observation;
        }
        uasort($byPeriod, fn ($a, $b) => count($b) <=> count($a));
        $period = (string) array_key_first($byPeriod);
        $inPeriod = reset($byPeriod);

        $bySubject = [];
        foreach ($inPeriod as $observation) {
            $bySubject[$this->normalizer->normalize($observation->subject)][] = $observation;
        }
        $parts = [];
        $total = null;
        foreach ($bySubject as $subject => $candidates) {
            usort($candidates, fn ($a, $b) => $b->confidence <=> $a->confidence);
            if ($candidates[0]->isTotal) {
                $total ??= $candidates[0];

                continue;
            }
            $parts[$subject] = $candidates[0];
        }
        if (count($parts) < 2) {
            $rejected['single_category']++;

            return null;
        }
        uasort($parts, fn ($a, $b) => $b->measurement->magnitude <=> $a->measurement->magnitude);
        $parts = array_slice($parts, 0, self::MAX_CATEGORIES, true);
        $observations = array_values($parts);
        $display = $this->display($observations);
        $composition = $this->isComposition($observations, $total, count($parts) === count($bySubject) - ($total ? 1 : 0));
        if (! $composition) {
            $rejected['not_a_composition']++;
        }

        return [
            'id' => 'cat-'.substr(hash('sha256', $key.'|'.$period), 0, 16),
            'basis' => $composition ? 'composition' : 'categorical',
            'type' => $composition ? 'pie' : 'bar',
            'title' => $this->title($observations).($period !== '' ? ' · '.$period : ''),
            'description' => $this->describe(count($parts).' categories'.($period !== '' ? ' · '.$period : ''), $display['unit']),
            'metric' => $this->title($observations),
            ...$display,
            'points' => $this->points($parts, $display['scale'], fn (MetricObservation $o) => $this->subjectLabel($o)),
            'series' => [],
            'sourceIds' => array_values(array_unique(array_map(fn ($o) => $o->sourceId, $observations))),
        ];
    }

    /**
     * Percentages that name their own categories: "Infrastructure 31%", "Energy 24%". These are
     * separate metrics, not one metric measured on several subjects, so they only ever form a
     * chart when they demonstrably partition one whole - the shares sum to about a hundred, each
     * category appears once, and they share a period and a measurement.
     *
     * @param  list<MetricObservation>  $observations
     * @return list<array<string,mixed>>
     */
    private function labelCompositions(array $observations, array &$rejected): array
    {
        $sets = [];
        foreach ($observations as $observation) {
            if ($observation->measurement->kind !== 'percent') {
                continue;
            }
            $context = $observation->measureKey.'|'.($observation->period?->label ?? '').'|'.$this->normalizer->normalize($observation->subject);
            $sets[$context][$observation->conceptKey][] = $observation;
        }

        $charts = [];
        foreach ($sets as $context => $concepts) {
            if (count($concepts) < self::MIN_COMPOSITION_PARTS) {
                continue;
            }
            $parts = [];
            foreach ($concepts as $candidates) {
                if (count($candidates) > 1) {
                    // The same share stated twice in one period is not a partition DocIntel can trust.
                    $parts = [];
                    break;
                }
                $parts[] = $candidates[0];
            }
            $sum = array_sum(array_map(fn (MetricObservation $o) => $o->measurement->magnitude, $parts));
            if ($parts === [] || count($parts) > self::MAX_CATEGORIES
                || abs($sum - 100.0) > self::COMPOSITION_TOLERANCE_PERCENT
                || array_filter($parts, fn (MetricObservation $o) => $o->measurement->magnitude < 0)) {
                $rejected['not_a_composition']++;

                continue;
            }
            usort($parts, fn ($a, $b) => $b->measurement->magnitude <=> $a->measurement->magnitude);
            [, $period, $subject] = explode('|', $context, 3);
            $keyed = [];
            foreach ($parts as $part) {
                $keyed[$part->sourceId] = $part;
            }
            $display = $this->display($parts);
            $name = $subject !== '' ? $this->subjectLabel($parts[0], true) : $this->commonAffix(array_map(fn ($o) => $o->label, $parts));
            $name = $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);

            $charts[] = [
                'id' => 'comp-'.substr(hash('sha256', $context), 0, 16),
                'basis' => 'composition',
                'type' => 'pie',
                'title' => ($name !== '' ? $name : 'Percentage breakdown').($period !== '' ? ' · '.$period : ''),
                'description' => $this->describe(count($parts).' shares of the total'.($period !== '' ? ' · '.$period : ''), $display['unit']),
                'metric' => $name !== '' ? $name : 'Percentage breakdown',
                ...$display,
                'points' => $this->points($keyed, $display['scale'], fn (MetricObservation $o) => $this->categoryLabel($o, $parts)),
                'series' => [],
                'sourceIds' => array_map(fn ($o) => $o->sourceId, $parts),
            ];
        }

        return $charts;
    }

    /**
     * Whether these parts genuinely partition one whole. Either they are percentages summing to
     * about a hundred, or the document stated a total for the same metric and period that the
     * parts add up to. Nothing else qualifies.
     *
     * @param  list<MetricObservation>  $parts
     */
    private function isComposition(array $parts, ?MetricObservation $total, bool $complete): bool
    {
        if (count($parts) < self::MIN_COMPOSITION_PARTS || ! $complete) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part->measurement->magnitude < 0) {
                return false;
            }
        }
        $sum = array_sum(array_map(fn (MetricObservation $o) => $o->measurement->magnitude, $parts));
        if ($parts[0]->measurement->kind === 'percent') {
            return abs($sum - 100.0) <= self::COMPOSITION_TOLERANCE_PERCENT
                && ! array_filter($parts, fn ($o) => $o->measurement->magnitude > 100.0);
        }
        if ($total === null || $total->measurement->magnitude <= 0) {
            return false;
        }

        return abs($sum - $total->measurement->magnitude) <= self::COMPOSITION_TOLERANCE_RATIO * $total->measurement->magnitude;
    }

    /**
     * Presentation scale and unit for one set of observations. The written scale is normalized away
     * during parsing, so the whole set is re-expressed at one readable scale here and the unit
     * string says which.
     *
     * @param  list<MetricObservation>  $observations
     * @return array{unit:string,unitKind:string,currency:?string,scale:float}
     */
    private function display(array $observations): array
    {
        $first = $observations[0]->measurement;
        $largest = max(array_map(fn (MetricObservation $o) => abs($o->measurement->magnitude), $observations));
        // Currency reads better scaled; a count does not - "1.31 thousand employees" is worse than
        // "1,310 employees" - so a count is only rescaled once it is genuinely unreadable.
        $floor = match ($first->kind) {
            'currency' => 1e3,
            'count', 'unknown' => 1e6,
            default => INF,
        };
        $scale = 1.0;
        foreach (self::SCALE_WORDS as $size => $word) {
            if ($largest >= $size && $size >= $floor) {
                $scale = (float) $size;
                break;
            }
        }
        $word = self::SCALE_WORDS[(int) $scale] ?? null;
        $unit = match ($first->kind) {
            'percent' => '%',
            'currency' => trim($first->currency.' '.($word ?? '')),
            default => trim(($word ? $word.' ' : '').$first->unitText),
        };

        return ['unit' => $unit, 'unitKind' => $first->kind, 'currency' => $first->currency, 'scale' => $scale];
    }

    /**
     * @param  array<string,MetricObservation>  $observations
     * @return list<array<string,mixed>>
     */
    private function points(array $observations, float $scale, ?callable $label = null): array
    {
        $points = [];
        foreach ($observations as $key => $observation) {
            $points[] = [
                'label' => $label ? $label($observation) : (string) $key,
                'value' => round($observation->measurement->magnitude / $scale, 4),
                'sourceIds' => [$observation->sourceId],
                'page' => $observation->page,
            ];
        }

        return $points;
    }

    /** The label the document used most often for this metric. */
    private function title(array $observations): string
    {
        $counts = [];
        foreach ($observations as $observation) {
            $counts[$observation->label] = ($counts[$observation->label] ?? 0) + 1;
        }
        arsort($counts);
        $title = (string) array_key_first($counts);

        return mb_strlen($title) > 90 ? mb_substr($title, 0, 89).'…' : $title;
    }

    private function describe(string $shape, string $unit): string
    {
        return $unit === '' ? $shape : $shape.' · '.$unit;
    }

    private function subjectLabel(MetricObservation $observation, bool $plain = false): string
    {
        $subject = trim($observation->subject);
        if ($subject === '') {
            return $plain ? '' : $observation->label;
        }

        return mb_strlen($subject) > 60 ? mb_substr($subject, 0, 59).'…' : $subject;
    }

    /** @param array<string,array<string,list<MetricObservation>>> $bySubject */
    private function subjectName(string $normalized, array $bySubject): string
    {
        $observations = array_merge(...array_values($bySubject[$normalized] ?? []));

        return $observations === [] ? $normalized : $this->subjectLabel($observations[0]);
    }

    /**
     * The category a share names, with the wording its siblings share removed, so a pie reads
     * "Infrastructure" rather than "Infrastructure share of total financing".
     *
     * @param  list<MetricObservation>  $siblings
     */
    private function categoryLabel(MetricObservation $observation, array $siblings): string
    {
        $affix = $this->commonAffix(array_map(fn ($o) => $o->label, $siblings));
        $label = trim($observation->label);
        if ($affix !== '') {
            $stripped = trim((string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($affix, '/').'(?![\p{L}\p{N}])/iu', ' ', $label));
            $label = trim((string) preg_replace('/\s+/u', ' ', $stripped), ' -–—:,()[]') ?: $label;
        }

        return mb_strlen($label) > 60 ? mb_substr($label, 0, 59).'…' : $label;
    }

    /**
     * The longest run of words every label shares at the same end. Whole words only, and only when
     * every label has it, so unrelated labels produce nothing.
     *
     * @param  list<string>  $labels
     */
    private function commonAffix(array $labels): string
    {
        $words = array_map(fn ($label) => preg_split('/\s+/u', trim($label)) ?: [], $labels);
        if (count($words) < 2) {
            return '';
        }
        foreach ([false, true] as $fromStart) {
            $sets = $fromStart ? $words : array_map('array_reverse', $words);
            $run = [];
            for ($index = 0; $index < min(array_map('count', $sets)) - 1; $index++) {
                $word = $sets[0][$index];
                foreach ($sets as $set) {
                    if (mb_strtolower($set[$index]) !== mb_strtolower($word)) {
                        break 2;
                    }
                }
                $run[] = $word;
            }
            if ($run !== []) {
                return implode(' ', $fromStart ? $run : array_reverse($run));
            }
        }

        return '';
    }

    /**
     * Rank, so the page can show the strongest few. Every term is something already recorded:
     * what kind of comparison it is, how many points support it, how confident the underlying
     * findings are, whether the document's own synthesis cited them, and whether the metric is a
     * canonical workspace KPI. No invented precision beyond that ordering.
     *
     * @param  array<string,mixed>  $candidate
     * @param  array<string,bool>  $cited
     */
    private function score(array $candidate, array $cited): float
    {
        $points = $candidate['points'] !== [] ? $candidate['points']
            : array_merge(...array_map(fn ($series) => $series['data'], $candidate['series']));
        $score = match ($candidate['basis']) {
            'time_series' => 1.0,
            'composition' => 0.8,
            default => 0.7,
        };
        $score += 0.2 * min(count($points), 8) / 8;
        if (array_intersect_key($cited, array_fill_keys($candidate['sourceIds'], true)) !== []) {
            $score += 0.15;
        }
        if ($candidate['basis'] === 'time_series' && count($points) >= 2) {
            $first = (float) $points[0]['value'];
            $last = (float) $points[count($points) - 1]['value'];
            if ($first != 0.0 && abs(($last - $first) / $first) >= 0.05) {
                $score += 0.1;
            }
        }

        return round($score, 4);
    }
}
