<?php

namespace App\Services\AI;

class AiPricing
{
    public function estimate(string $model, array $usage): ?float
    {
        // Model IDs can contain dots; Laravel's dotted config lookup would split those IDs.
        $rates = config('document_intelligence.pricing', [])[$model] ?? null;
        if (! $rates) {
            return null;
        }
        $cost = 0;
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $i => $key) {
            $cost += ($usage[$key] ?? 0) * $rates[$i] / 1000000;
        }

        return round($cost, 6);
    }

    /** Conservative preflight, including the higher rate for a possible cache write. */
    public function reserve(string $model, int $inputTokens, int $outputTokens, bool $cacheWrite = false): ?float
    {
        return $this->estimate($model, [
            $cacheWrite ? 'cache_creation_input_tokens' : 'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);
    }
}
