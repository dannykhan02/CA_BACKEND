<?php

namespace Tests\Support;

use App\Services\AI\AiPricing;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** One-process evaluation boundary. Every HTTP dispatch, including an accidental retry, passes here. */
final class NorthstarV10RequestGuard
{
    public int $requests = 0;

    public float $reservedUsd = 0.0;

    public array $attempts = [];

    public function __construct(private readonly float $budgetUsd, private readonly string $promptSha256)
    {
        if (! is_finite($budgetUsd) || $budgetUsd <= 0 || $budgetUsd > 0.01
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $promptSha256)) {
            throw new RuntimeException('northstar_eval_invalid_guard_configuration');
        }
    }

    public function inspect(RequestInterface $request): RequestInterface
    {
        if ($request->getMethod() !== 'POST'
            || (string) $request->getUri() !== 'https://api.anthropic.com/v1/messages') {
            throw new RuntimeException('northstar_eval_unexpected_http_request');
        }
        $body = json_decode((string) $request->getBody(), true);
        if (! is_array($body) || array_keys($body) !== ['model', 'max_tokens', 'messages']
            || ($body['model'] ?? null) !== 'claude-haiku-5-5'
            || ($body['max_tokens'] ?? null) !== 14000
            || ! is_array($body['messages'] ?? null) || count($body['messages']) !== 1
            || array_keys($body['messages'][0]) !== ['role', 'content']
            || ($body['messages'][0]['role'] ?? null) !== 'user'
            || ! is_string($body['messages'][0]['content'] ?? null)
            || ! hash_equals($this->promptSha256, hash('sha256', $body['messages'][0]['content']))) {
            throw new RuntimeException('northstar_eval_unexpected_provider_payload');
        }

        // Serialized UTF-8 bytes plus framing bound input tokens conservatively.
        // Cache-write pricing is reserved even though this payload requests no caching.
        $inputBound = 512 + strlen((string) $request->getBody());
        $maximum = app(AiPricing::class)->maximum('claude-haiku-5-5', $inputBound, 14000, true);
        if ($maximum === null || ! is_finite($maximum) || $this->requests >= 1
            || $this->reservedUsd + $maximum > $this->budgetUsd) {
            throw new RuntimeException('northstar_eval_budget_or_request_limit');
        }
        $this->requests++;
        $this->reservedUsd += $maximum;
        $this->attempts[] = ['input_token_bound' => $inputBound,
            'max_output_tokens' => 14000, 'maximum_usd' => $maximum];

        return $request;
    }
}
