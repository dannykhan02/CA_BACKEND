<?php

namespace Tests\Support;

use App\Services\AI\AiPricing;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Test-only HTTP boundary. This limits this process, not the Anthropic account. */
final class NorthstarV9RequestGuard
{
    public int $requests = 0;

    public float $reservedUsd = 0.0;

    public array $attempts = [];

    public function __construct(private readonly float $budgetUsd, private readonly int $maxRequests = 2) {}

    public function inspect(RequestInterface $request): RequestInterface
    {
        if ($request->getMethod() !== 'POST'
            || (string) $request->getUri() !== 'https://api.anthropic.com/v1/messages') {
            throw new RuntimeException('northstar_eval_unexpected_http_request');
        }
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (($body['model'] ?? null) !== 'claude-haiku-5-5'
            || ! is_array($body['messages'] ?? null)
            || ! is_int($body['max_tokens'] ?? null)
            || ! in_array($body['max_tokens'], [6400, 8192], true)
            || array_diff(array_keys($body), ['model', 'messages', 'max_tokens']) !== []) {
            throw new RuntimeException('northstar_eval_unexpected_provider_payload');
        }

        // Count all serialized JSON bytes as tokens, plus provider framing. This
        // overstates the normal text token count and includes any correction text.
        // Price at the highest input/cache rate for the request's tier.
        $inputBound = 512 + strlen((string) $request->getBody());
        $maximum = app(AiPricing::class)->maximum('claude-haiku-5-5', $inputBound,
            $body['max_tokens'], true);
        if ($maximum === null || ! is_finite($maximum)
            || $this->requests >= $this->maxRequests
            || $this->reservedUsd + $maximum > $this->budgetUsd) {
            throw new RuntimeException('northstar_eval_budget_or_request_limit');
        }
        $this->requests++;
        $this->reservedUsd += $maximum;
        $this->attempts[] = ['input_token_bound' => $inputBound,
            'max_output_tokens' => $body['max_tokens'], 'maximum_usd' => $maximum];

        return $request;
    }
}
