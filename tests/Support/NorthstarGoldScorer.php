<?php

namespace Tests\Support;

use Brick\Math\BigDecimal;

/** Diagnostic exact matches only; unmatched candidates require source review. */
final class NorthstarGoldScorer
{
    public function score(array $gold, array $candidates): array
    {
        $rows = [];
        foreach ($gold as $fact) {
            $matches = [];
            foreach ($candidates as $index => $candidate) {
                if ($this->matches($fact, $candidate['candidate'])) {
                    $matches[] = $index;
                }
            }
            $rows[] = ['gold_id' => $fact['id'], 'candidate_indexes' => $matches,
                'accepted_indexes' => array_values(array_filter($matches,
                    fn ($i) => $candidates[$i]['rejection'] === null && $candidates[$i]['evidence'] !== null)),
                'mission_anchor' => preg_match('/^(revenue|costs|profit|margin)-q[123]$/', $fact['id']) === 1
                    || in_array($fact['id'], ['delivery-q3', 'delivery-target-q3', 'complaints-q3',
                        'complaints-ceiling-q3', 'service-recovery-proposed'], true),
                'prompt_required' => false];
        }
        $matchedIndexes = array_unique(array_merge([], ...array_column($rows, 'candidate_indexes')));

        return ['gold_coverage' => $rows, 'exact_gold_matches' => count(array_filter($rows,
            fn ($row) => $row['candidate_indexes'] !== [])),
            'unmatched_candidate_indexes_for_review' => array_values(array_diff(
                array_keys($candidates), $matchedIndexes)),
            'note' => 'Unmatched candidates may be supported elsewhere in the PDF. Missing gold facts are coverage observations, not automatic prompt failures.'];
    }

    private function matches(array $fact, array $candidate): bool
    {
        $identity = $candidate['identity'] ?? [];
        foreach (['metric' => 'label', 'unit' => 'unit', 'period' => 'period'] as $goldKey => $candidateKey) {
            if ($this->normalize($fact[$goldKey] ?? null) !== $this->normalize($candidate[$candidateKey] ?? null)) {
                return false;
            }
        }
        if ($this->normalize($fact['subject'] ?? null) !== $this->normalize($identity['scope'] ?? null)
            || $this->normalize($fact['value_basis'] ?? null) !== $this->normalize($identity['value_basis'] ?? null)) {
            return false;
        }
        try {
            $a = BigDecimal::of(str_replace([',', '%', '≤', ' '], '', (string) $fact['value']));
            $b = BigDecimal::of(str_replace([',', '%', '≤', ' '], '', (string) ($candidate['value'] ?? '')));

            return $a->isEqualTo($b);
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
