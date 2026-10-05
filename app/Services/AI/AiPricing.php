<?php

namespace App\Services\AI;

class AiPricing
{
    public function estimate(string $model, array $usage): ?float
    {
        $rates = config('document_intelligence.pricing.'.$model);
        if (! $rates) {
            return null;
        }
        $cost = 0;
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $i => $key) {
            $cost += ($usage[$key] ?? 0) * $rates[$i] / 1000000;
        }

        return round($cost, 6);
    }
}
