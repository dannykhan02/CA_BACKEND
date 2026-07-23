<?php

namespace App\Services;

use App\Exceptions\AnthropicRateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicClient
{
    private const RATE_LIMIT_KEY = 'anthropic:requests_this_minute';
    private const MAX_REQUESTS_PER_MINUTE = 40;

    public function extractDocumentInsights(string $documentText, string $documentName): array
    {
        $this->throttle();

        $prompt = $this->buildPrompt($documentText, $documentName);

        $response = $this->callWithRetry($prompt);

        return $this->parseResponse($response);
    }

    private function throttle(): void
    {
        // Atomic increment avoids a get-then-put race under concurrent workers.
        $count = Cache::increment(self::RATE_LIMIT_KEY);
        if ($count === 1) {
            Cache::put(self::RATE_LIMIT_KEY, 1, now()->addMinute());
        }
        if ($count > self::MAX_REQUESTS_PER_MINUTE) {
            throw new AnthropicRateLimitException('Local rate limit reached, retry shortly.');
        }
    }

    private function callWithRetry(string $prompt, int $attempt = 1): array
    {
        $maxAttempts = 4;

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(config('services.anthropic.timeout'))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => config('services.anthropic.max_tokens'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if ($response->status() === 429 || $response->status() === 529) {
            if ($attempt >= $maxAttempts) {
                throw new \RuntimeException('Anthropic API rate/overload limit exceeded after retries.');
            }
            $retryAfter = (int) $response->header('Retry-After', 0);
            $sleepSeconds = $retryAfter > 0 ? $retryAfter : (2 ** $attempt);
            sleep($sleepSeconds);
            return $this->callWithRetry($prompt, $attempt + 1);
        }

        if ($response->failed()) {
            Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException("Anthropic API request failed with status {$response->status()}.");
        }

        return $response->json();
    }

    private function buildPrompt(string $documentText, string $documentName): string
    {
        $truncated = mb_substr($documentText, 0, config('document_processing.max_extraction_chars'));

        return <<<PROMPT
You are analyzing a document titled "{$documentName}" for a regulatory intelligence dashboard.

Extract the following from the document text below, and respond with ONLY valid JSON, no other text, no markdown code fences:

{
  "kpis": [{"label": string, "value": string, "unit": string|null, "trend": "up"|"down"|"flat"|null, "trendValue": string|null}],
  "charts": [{"type": "bar"|"line"|"pie", "title": string, "description": string, "data": [{"label": string, "value": number}]}],
  "insights": [string, string, ...]
}

Only include kpis/charts if the document actually contains quantitative data suitable for them — an empty array is correct and expected for narrative-only documents. Insights should be 2-5 concise, factual observations directly supported by the text. Do not fabricate numbers not present in the source.

Document text:
{$truncated}
PROMPT;
    }

    private function parseResponse(array $response): array
    {
        $text = $response['content'][0]['text'] ?? '';

        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', trim($text));

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new \RuntimeException('Anthropic response was not valid JSON: ' . json_last_error_msg());
        }

        return [
            'kpis' => $decoded['kpis'] ?? [],
            'charts' => $decoded['charts'] ?? [],
            'insights' => $decoded['insights'] ?? [],
            'input_tokens' => $response['usage']['input_tokens'] ?? null,
            'output_tokens' => $response['usage']['output_tokens'] ?? null,
        ];
    }
}
