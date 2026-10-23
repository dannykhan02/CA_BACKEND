<?php

namespace App\Services\AiCredits;

/** The output a request is expected to produce, for admission against the quote's cap (config ai_credits.stage_realistic_output). */
class RealisticOutput
{
    /** Output tokens to price, or null when the purpose has no estimate (admit at the worst case). Never above $maxTokens. */
    public function tokens(?string $purpose, int $inputTokens, int $maxTokens): ?int
    {
        $c = $purpose === null ? null : config('ai_credits.stage_realistic_output', [])[$purpose] ?? null;
        if (! is_array($c)) {
            return null;
        }
        $estimate = min((int) $c['cap'], (int) ceil((float) $c['base'] + (float) $c['per_input_token'] * max(0, $inputTokens)));

        return max(1, min($maxTokens, $estimate));
    }

    /** A request's input tokens from the byte bound admission uses (it counts a byte as a token; text runs about 3 bytes a token). */
    public function inputTokens(int $inputBound): int
    {
        return intdiv(max(0, $inputBound - 512), 3);
    }
}
