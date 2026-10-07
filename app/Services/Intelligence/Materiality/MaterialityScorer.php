<?php

namespace App\Services\Intelligence\Materiality;

use App\Services\AI\Incremental\EvidenceMerger;

/** One deterministic ranking and Tier 1 budget for all V2 callers. */
class MaterialityScorer
{
    public function __construct(private SignalEvaluator $signals, private ForcedItemRules $forced,
        private EvidenceMerger $normalizer) {}

    /**
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,mixed>  $context
     * @return array<string,array<string,mixed>> keyed by evidence identity
     */
    public function assign(array $records, array $context, \DateTimeImmutable $asOf): array
    {
        $config = config('intelligence_v2.materiality');
        $budget = config('intelligence_v2.tier1');
        $assignments = [];
        foreach ($records as $record) {
            $class = $this->classify($record, $asOf);
            $raw = (float) ($config['class_base'][$class] ?? $config['class_base']['other']);
            $reasons = [['signal' => 'kind_class', 'value' => $class, 'weight' => 1.0, 'contribution' => $raw]];
            foreach ($this->signals->values($record, $records, $context, $asOf) as $name => $result) {
                $value = max(0.0, min(1.0, (float) $result['value']));
                $contribution = $config['weights'][$name] * $value;
                $raw += $contribution;
                if ($contribution != 0.0 || ($result['skipped'] ?? false)) {
                    $reason = ['signal' => $name, 'value' => $value,
                        'weight' => $config['weights'][$name], 'contribution' => $contribution];
                    if ($result['skipped'] ?? false) {
                        $reason['skipped'] = true;
                        $reason['reason'] = $result['reason'];
                    }
                    $reasons[] = $reason;
                }
            }
            $score = max(0.0, min(1.0, $raw));
            if ($score != $raw) {
                $reasons[] = ['signal' => 'clamp', 'weight' => 1.0, 'contribution' => $score - $raw];
            }
            $band = $score >= $config['bands']['tier1'] ? 1 : ($score >= $config['bands']['tier2'] ? 2
                : ($score >= $config['bands']['tier3'] ? 3 : 4));
            $cited = isset($context['cited_source_ids'][$record['source_id'] ?? '']);
            $scored = $cited ? max(1, $band - 1) : $band;
            if ($cited) {
                $reasons[] = ['signal' => 'tier_adjustment', 'weight' => 0.0, 'contribution' => 0.0,
                    'reason' => 'cited_by_synthesis', 'from_tier' => $band, 'to_tier' => $scored];
            }
            $assignments[$record['identity']] = [
                'version' => $config['version'], 'scorer_version' => $config['version'],
                'config_version' => $config['version'], 'kind_class' => $class, 'score' => $score,
                'band_tier' => $band, 'scored_tier' => $scored, 'tier' => $scored,
                'band_qualified' => $scored === 1, 'forced' => false, 'forced_rule' => null,
                'overflow_from_forced' => false, 'reasons' => $reasons,
            ];
        }
        foreach ($records as $record) {
            $id = $record['identity'];
            $rule = $this->forced->first($record, $records, $assignments, $context, $asOf);
            $assignments[$id]['forced_rule'] = $rule;
            $assignments[$id]['forced_priority'] = $rule === null
                ? $config['tiebreak']['absent_forced_priority'] : $config['forced_priorities'][$rule];
        }
        $byId = [];
        foreach ($records as $record) {
            $byId[$record['identity']] = $record;
        }
        $forcedIds = array_keys(array_filter($assignments, fn ($item) => $item['forced_rule'] !== null));
        usort($forcedIds, fn ($a, $b) => [$assignments[$a]['forced_priority'], -$assignments[$a]['score']]
            <=> [$assignments[$b]['forced_priority'], -$assignments[$b]['score']]
            ?: self::compareTiebreak($byId[$a], $byId[$b], $assignments[$a], $assignments[$b]));
        foreach ($forcedIds as $index => $id) {
            if ($index < $budget['forced_max']) {
                $assignments[$id]['tier'] = 1;
                $assignments[$id]['forced'] = true;
                $assignments[$id]['reasons'][] = ['signal' => 'tier_adjustment', 'weight' => 0.0, 'contribution' => 0.0,
                    'reason' => $assignments[$id]['forced_rule'], 'to_tier' => 1];
            } else {
                $assignments[$id]['tier'] = 2;
                $assignments[$id]['overflow_from_forced'] = true;
                $assignments[$id]['reasons'][] = ['signal' => 'tier_adjustment', 'weight' => 0.0, 'contribution' => 0.0,
                    'reason' => 'forced_overflow', 'to_tier' => 2];
            }
        }
        $normalIds = array_keys(array_filter($assignments, fn ($item) => $item['forced_rule'] === null));
        usort($normalIds, fn ($a, $b) => $assignments[$b]['score'] <=> $assignments[$a]['score']
            ?: self::compareTiebreak($byId[$a], $byId[$b], $assignments[$a], $assignments[$b]));
        $normalCount = 0;
        $total = min(count($forcedIds), $budget['forced_max']);
        $normalKinds = [];
        $normalStems = [];
        $normalOrigins = [];
        foreach ($normalIds as $id) {
            $candidate = $assignments[$id];
            $qualifies = $candidate['scored_tier'] === 1
                || ($total < $budget['min'] && $candidate['scored_tier'] === 2);
            $record = $byId[$id];
            $kind = $record['kind'] === 'obligation' ? 'deadline' : $record['kind'];
            $stem = trim((string) preg_replace('/[^\p{L}\s]+|\s+/u', ' ',
                (string) preg_replace('/[\p{N}]+/u', '', mb_strtolower((string) ($record['data']['label'] ?? '')))));
            $origin = match ($record['kind']) {
                'metric' => 'metric', 'risk' => 'risk', 'obligation', 'deadline' => 'obligation', default => null,
            };
            $withinQuotas = ($normalKinds[$kind] ?? 0) < $budget['per_kind']
                && ($normalStems[$stem] ?? 0) < $budget['per_stem']
                && ($origin === null || ($normalOrigins[$origin] ?? 0) < $budget['origin_quotas'][$origin]);
            if ($qualifies && $withinQuotas && $normalCount < $budget['max']) {
                $assignments[$id]['tier'] = 1;
                $normalCount++;
                $total++;
                $normalKinds[$kind] = ($normalKinds[$kind] ?? 0) + 1;
                $normalStems[$stem] = ($normalStems[$stem] ?? 0) + 1;
                if ($origin !== null) {
                    $normalOrigins[$origin] = ($normalOrigins[$origin] ?? 0) + 1;
                }
            } elseif ($candidate['scored_tier'] === 1) {
                $assignments[$id]['tier'] = 2;
                $assignments[$id]['reasons'][] = ['signal' => 'tier_adjustment', 'weight' => 0.0, 'contribution' => 0.0,
                    'reason' => $withinQuotas ? 'normal_budget' : 'normal_quota', 'to_tier' => 2];
            }
        }
        if ($total > $budget['hard_cap']) {
            throw new \LogicException('Tier 1 hard cap exceeded');
        }

        return $assignments;
    }

    /** @param array<string,mixed> $record */
    public function classify(array $record, \DateTimeImmutable $asOf): string
    {
        $kind = $record['kind'] ?? null;
        $data = $record['data'] ?? [];
        if ($kind === 'risk') {
            return match (strtolower(trim((string) ($data['severity'] ?? '')))) {
                'critical' => 'critical_risk', 'high' => 'high_risk', default => 'risk',
            };
        }
        if (in_array($kind, ['deadline', 'obligation'], true)) {
            $due = ($data['date_type'] ?? null) === 'explicit' ? ($data['due_date'] ?? null) : null;
            if (! is_string($due) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due)) {
                return 'undated_obligation';
            }

            return $due >= $asOf->format('Y-m-d') ? 'upcoming_obligation' : 'dated_obligation';
        }

        return in_array($kind, ['metric', 'fact', 'definition', 'entity'], true) ? $kind : 'other';
    }

    /** §9.5, with missing positions last. @param array<string,mixed> $left @param array<string,mixed> $right @param array<string,mixed> $leftAssignment @param array<string,mixed> $rightAssignment */
    public static function compareTiebreak(array $left, array $right, array $leftAssignment = [], array $rightAssignment = []): int
    {
        $settings = config('intelligence_v2.materiality.tiebreak');
        $kinds = array_flip($settings['kind_order']);
        $key = static function (array $record, array $assignment) use ($settings, $kinds): array {
            $source = $record['sources'][0] ?? [];
            $normalize = new EvidenceMerger;

            return [
                $assignment['forced_priority'] ?? $settings['absent_forced_priority'],
                $assignment['scored_tier'] ?? 4,
                $kinds[$record['kind'] ?? ''] ?? count($kinds),
                $source['start_offset'] ?? PHP_INT_MAX,
                $source['page'] ?? PHP_INT_MAX,
                $source['end_offset'] ?? PHP_INT_MAX,
                $normalize->normalize((string) ($record['data']['label'] ?? '')),
                $record['identity'] ?? '',
            ];
        };

        return $key($left, $leftAssignment) <=> $key($right, $rightAssignment);
    }
}
