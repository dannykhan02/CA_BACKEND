<?php

namespace App\Services\AI;

/** Offline scorer for independently matched, normalized extraction recordings. */
class ExtractionBenchmarkScorer
{
    private const FIELDS = ['value', 'unit', 'period', 'subject', 'value_basis', 'comparison_operator'];

    public function __construct(private AiPricing $pricing) {}

    public function score(array $manifest): array
    {
        $models = $manifest['models'] ?? [];
        $cases = $manifest['cases'] ?? [];
        if (! is_array($models) || ! array_is_list($models) || ! is_array($cases)
            || ! array_is_list($cases) || $models === [] || $cases === []) {
            throw new \InvalidArgumentException('Benchmark needs model IDs and cases.');
        }
        $results = [];
        foreach ($models as $model) {
            if (! is_string($model) || $model === '') {
                throw new \InvalidArgumentException('Invalid benchmark model ID.');
            }
            $totals = ['expected' => 0, 'matched' => 0, 'candidates' => 0, 'false_verified' => 0,
                'eligible_candidates' => 0, 'failed_runs' => 0, 'successful_runs' => 0,
                'duration_ms' => 0, 'estimated_cost_usd' => 0.0];
            $correct = array_fill_keys([...self::FIELDS, 'source_ids'], 0);
            $unpriced = 0;
            $pending = 0;
            foreach ($cases as $case) {
                $gold = $case['gold'] ?? null;
                if (! is_array($gold) || ! array_is_list($gold) || ! is_array($case['runs'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid benchmark case.');
                }
                $expected = [];
                foreach ($gold as $item) {
                    if (! is_array($item) || ! is_string($item['id'] ?? null)
                        || isset($expected[$item['id']])) {
                        throw new \InvalidArgumentException('Gold IDs must be unique within a case.');
                    }
                    $expected[$item['id']] = $item;
                }
                $totals['expected'] += count($expected);
                $run = $case['runs'][$model] ?? null;
                if ($run === null) {
                    $pending++;

                    continue;
                }
                if (($run['status'] ?? null) !== 'success') {
                    $totals['failed_runs']++;

                    continue;
                }
                if (! is_array($run['candidates'] ?? null) || ! array_is_list($run['candidates'])) {
                    throw new \InvalidArgumentException('Successful run lacks candidate list.');
                }
                $totals['successful_runs']++;
                $totals['duration_ms'] += max(0, (int) ($run['duration_ms'] ?? 0));
                $usage = $run['usage'] ?? null;
                $cost = $this->validUsage($usage) ? $this->pricing->estimate($model, $usage) : null;
                if ($cost === null) {
                    $unpriced++;
                } else {
                    $totals['estimated_cost_usd'] += $cost;
                }
                $seen = [];
                foreach ($run['candidates'] as $candidate) {
                    if (! is_array($candidate)) {
                        throw new \InvalidArgumentException('Invalid benchmark candidate.');
                    }
                    $totals['candidates']++;
                    $match = $candidate['reviewed_match_id'] ?? null;
                    $reference = is_string($match) ? ($expected[$match] ?? null) : null;
                    if ($reference !== null && ! isset($seen[$match])) {
                        $seen[$match] = true;
                        $totals['matched']++;
                    } else {
                        $reference = null;
                    }
                    $allCorrect = $reference !== null;
                    if ($reference !== null) {
                        foreach (self::FIELDS as $field) {
                            $same = ($candidate[$field] ?? null) === ($reference[$field] ?? null);
                            $correct[$field] += (int) $same;
                            $allCorrect = $allCorrect && $same;
                        }
                        $sources = $candidate['source_ids'] ?? [];
                        $goldSources = $reference['source_ids'] ?? [];
                        if (! is_array($sources) || ! is_array($goldSources)) {
                            throw new \InvalidArgumentException('Source references must be arrays.');
                        }
                        sort($sources);
                        sort($goldSources);
                        $same = $sources === $goldSources;
                        $correct['source_ids'] += (int) $same;
                        $allCorrect = $allCorrect && $same;
                    }
                    if (($candidate['analytical_eligible'] ?? false) === true) {
                        $totals['eligible_candidates']++;
                        $totals['false_verified'] += (int) ! $allCorrect;
                    }
                }
            }
            if ($pending > 0 || $totals['failed_runs'] > 0 || $unpriced > 0
                || $totals['successful_runs'] !== count($cases)) {
                $totals['estimated_cost_usd'] = null;
            }
            $results[$model] = $totals + [
                'status' => $pending === 0 ? 'scored' : 'pending_recordings',
                'pending_cases' => $pending,
                'unpriced_runs' => $unpriced,
                'field_correct' => $correct,
                'field_accuracy' => array_map(
                    fn (int $count): ?float => $totals['matched'] ? $count / $totals['matched'] : null,
                    $correct),
                'completeness' => $pending === 0 && $totals['expected']
                    ? $totals['matched'] / $totals['expected'] : null,
                'source_reference_accuracy' => $totals['matched']
                    ? $correct['source_ids'] / $totals['matched'] : null,
                'false_verification_rate' => $totals['eligible_candidates']
                    ? $totals['false_verified'] / $totals['eligible_candidates'] : null,
                'mean_latency_ms' => $totals['successful_runs']
                    ? $totals['duration_ms'] / $totals['successful_runs'] : null,
            ];
        }

        return ['cases' => count($cases), 'models' => $results];
    }

    private function validUsage(mixed $usage): bool
    {
        if (! is_array($usage) || ! isset($usage['input_tokens'], $usage['output_tokens'])) {
            return false;
        }
        $total = 0;
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            $count = $usage[$key] ?? 0;
            if (! is_int($count) || $count < 0) {
                return false;
            }
            $total += $count;
        }

        return $total > 0;
    }
}
