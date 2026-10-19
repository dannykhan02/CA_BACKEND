<?php

namespace App\Services\AI;

class AiPricing
{
    public function estimate(string $model, array $usage): ?float
    {
        // Model IDs can contain dots; Laravel's dotted config lookup would split those IDs.
        $inputTokens = array_sum(array_map(fn ($key) => (int) ($usage[$key] ?? 0),
            ['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens']));
        $rates = $this->rates($model, $inputTokens);
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

    /** Hard request bound: any input token may be billed at the highest configured input/cache rate. */
    public function maximum(string $model, int $inputTokens, int $maxOutputTokens, bool $mayUseCache = false): ?float
    {
        $rates = $this->rates($model, $inputTokens);
        if (! is_array($rates) || count($rates) !== 4) {
            return null;
        }

        $inputRate = $mayUseCache ? max($rates[0], $rates[2], $rates[3]) : $rates[0];

        return ceil($inputTokens * $inputRate + $maxOutputTokens * $rates[1]) / 1000000;
    }

    private function rates(string $model, int $inputTokens): ?array
    {
        $rates = config('document_intelligence.pricing', [])[$model] ?? null;
        $long = config('document_intelligence.pricing_long_context', [])[$model] ?? null;
        if (is_array($long) && $inputTokens > ($long['threshold'] ?? PHP_INT_MAX)) {
            return $long['rates'] ?? null;
        }

        return $rates;
    }
}
