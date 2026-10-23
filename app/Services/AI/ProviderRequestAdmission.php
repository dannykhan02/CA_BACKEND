<?php

namespace App\Services\AI;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

/** Local admission for every paid Anthropic message, including each retry. */
class ProviderRequestAdmission
{
    public function __construct(private AiPricing $pricing) {}

    public function assertAllowed(?Document $document, ?string $task, string $model, array $messages, array $options): float
    {
        return $this->assess($document, $task, $model, $messages, $options)['maximum'];
    }

    /**
     * The same checks, returning both bounds: the worst case (every output token the request may produce) and the realistic
     * cost (the same input, the purpose's `ai_credits.stage_realistic_output_tokens` at most as output). Never above the worst case.
     *
     * @return array{maximum:float,realistic:float}
     */
    public function assess(?Document $document, ?string $task, string $model, array $messages, array $options): array
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
            Log::warning('Anthropic request uses an unapproved model', [
                'purpose' => $task,
                'model' => preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', $model) ? $model : '[invalid]',
                'document_id' => $document?->id,
            ]);
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
        $maxImages = config('document_intelligence.max_images_per_request');
        if (! is_numeric($budget) || $budget <= 0 || ! is_numeric($callCap) || $callCap <= 0
            || $callCap > $budget || ! is_int($visualReserve) || $visualReserve < 1
            || ! is_int($maxImages) || $maxImages < 1) {
            throw new AiProcessingException('invalid_provider_config');
        }
        // Count the serialized request bytes (excluding base64 pixels) as a text-token ceiling.
        // Provider image tokens have a separate documented per-image model ceiling.
        $boundedMessages = [];
        $images = 0;
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                throw new AiProcessingException('invalid_provider_config');
            }
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $boundedMessages[] = $message;
            } elseif (is_array($content)) {
                $boundedParts = [];
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'image') {
                        $images++;
                        if (! is_string($part['source']['data'] ?? null)
                            || ($part['source']['type'] ?? null) !== 'base64') {
                            throw new AiProcessingException('invalid_provider_config');
                        }
                        $part['source']['data'] = '';
                        $boundedParts[] = $part;
                    } elseif (is_string($part['text'] ?? null)) {
                        $boundedParts[] = $part;
                    } else {
                        throw new AiProcessingException('invalid_provider_config');
                    }
                }
                $boundedMessages[] = ['role' => 'user', 'content' => $boundedParts];
            } else {
                throw new AiProcessingException('invalid_provider_config');
            }
        }
        if (count($messages) > 4 || $images > $maxImages
            || ($images && (! is_int($capabilities['max_image_tokens'] ?? null)
                || $visualReserve < $capabilities['max_image_tokens']))) {
            throw new AiProcessingException('invalid_provider_config');
        }
        $input = 512 + strlen(json_encode($boundedMessages, JSON_THROW_ON_ERROR)) + $images * $visualReserve;
        if (isset($options['system'])) {
            $input += strlen(json_encode($options['system'], JSON_THROW_ON_ERROR));
        }
        if (isset($options['output_config'])) {
            $input += strlen(json_encode($options['output_config'], JSON_THROW_ON_ERROR));
        }
        if ($input + (int) $output > (int) $capabilities['context_window']) {
            throw new AiProcessingException('context_overflow');
        }
        $mayUseCache = $this->mayUseCache($messages) || $this->mayUseCache($options['system'] ?? []);
        $cost = $this->pricing->maximum($model, $input, (int) $output, $mayUseCache);
        if ($cost === null || ! is_finite($cost)) {
            throw new AiProcessingException('unknown_price');
        }
        if ($cost > $callCap || ($document && $cost > ($document->ai_pipeline['budget_usd'] ?? $budget))) {
            throw new AiProcessingException('budget_exceeded');
        }

        $typical = is_string($task) ? config('ai_credits.stage_realistic_output_tokens', [])[$task] ?? null : null;
        $realistic = is_numeric($typical) && (int) $typical >= 1
            ? $this->pricing->maximum($model, $input, min((int) $output, (int) $typical), $mayUseCache) : $cost;

        return ['maximum' => $cost, 'realistic' => min($cost, $realistic ?? $cost)];
    }

    private function mayUseCache(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (array_key_exists('cache_control', $block)
                || $this->mayUseCache(array_filter($block, 'is_array'))) {
                return true;
            }
        }

        return false;
    }
}
