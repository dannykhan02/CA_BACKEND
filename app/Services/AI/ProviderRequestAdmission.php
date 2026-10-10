<?php

namespace App\Services\AI;

use App\Exceptions\AiProcessingException;
use App\Models\Document;

/** Local admission for every paid Anthropic message, including each retry. */
class ProviderRequestAdmission
{
    public function __construct(private AiPricing $pricing) {}

    public function assertAllowed(?Document $document, ?string $task, string $model, array $messages, array $options): float
    {
        $roles = config('document_intelligence.model_roles', []);
        $role = is_string($task) ? ($roles[$task] ?? null) : null;
        $selected = $role === 'synthesis' ? config('services.anthropic.synthesis_model')
            : ($role === 'extraction' ? config('services.anthropic.extraction_model') : null);
        if ($task === 'brief_synthesis' && config('intelligence_v2.b2.model')) {
            $selected = config('intelligence_v2.b2.model');
        }
        if (! is_string($selected) || $selected === '' || $model !== $selected
            || ! in_array($model, config('document_intelligence.approved_models', []), true)) {
            throw new AiProcessingException('unsupported_model');
        }

        $capabilities = config('document_intelligence.model_capabilities', [])[$model] ?? null;
        $rates = config('document_intelligence.pricing', [])[$model] ?? null;
        if (! is_array($capabilities) || ! is_array($rates) || count($rates) !== 4
            || count(array_filter($rates, fn ($rate) => is_numeric($rate) && $rate >= 0)) !== 4) {
            throw new AiProcessingException('unknown_price');
        }
        $output = $options['max_tokens'] ?? config('services.anthropic.max_tokens');
        $timeout = $options['timeout'] ?? config('services.anthropic.timeout');
        $attempts = $options['max_attempts'] ?? 4;
        if (! is_numeric($output) || (int) $output < 1
            || ! is_numeric($capabilities['max_output_tokens'] ?? null)
            || (int) $output > (int) $capabilities['max_output_tokens']
            || ! is_numeric($capabilities['context_window'] ?? null)
            || (int) $capabilities['context_window'] <= (int) $output
            || ! is_numeric($timeout) || (int) $timeout < 1
            || ! is_numeric($attempts) || (int) $attempts < 1) {
            throw new AiProcessingException('invalid_provider_config');
        }
        $budget = config('document_intelligence.budget_max_usd');
        $callCap = config('document_intelligence.provider_call_max_usd');
        $visualReserve = config('document_intelligence.visual_input_token_reserve');
        if (! is_numeric($budget) || $budget <= 0 || ! is_numeric($callCap) || $callCap <= 0
            || $callCap > $budget || ! is_int($visualReserve) || $visualReserve < 1) {
            throw new AiProcessingException('invalid_provider_config');
        }
        // Byte length is an upper bound for text tokens; base64 image bytes are not text tokens.
        // Each image receives the configured conservative visual-token reserve instead.
        $input = 512;
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                throw new AiProcessingException('invalid_provider_config');
            }
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $input += strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'image') {
                        $input += $visualReserve;
                    } elseif (is_string($part['text'] ?? null)) {
                        $input += strlen($part['text']);
                    }
                }
            }
        }
        if (isset($options['system'])) {
            $input += strlen(json_encode($options['system'], JSON_THROW_ON_ERROR));
        }
        if (isset($options['output_config'])) {
            $input += strlen(json_encode($options['output_config'], JSON_THROW_ON_ERROR));
        }
        if ($input + (int) $output > (int) $capabilities['context_window']) {
            throw new AiProcessingException('context_overflow');
        }
        $cost = $this->pricing->reserve($model, $input, (int) $output, cacheWrite: true);
        if ($cost === null || ! is_finite($cost)) {
            throw new AiProcessingException('unknown_price');
        }
        if ($cost > $callCap || ($document && $cost > ($document->ai_pipeline['budget_usd'] ?? $budget))) {
            throw new AiProcessingException('budget_exceeded');
        }

        return $cost;
    }
}
