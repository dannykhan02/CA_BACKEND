<?php

namespace Tests\Unit;

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\NorthstarV9RequestGuard;
use Tests\TestCase;

class NorthstarV9RequestGuardTest extends TestCase
{
    private function request(int $output = 6400): Request
    {
        return new Request('POST', 'https://api.anthropic.com/v1/messages', [], json_encode([
            'model' => 'claude-haiku-5-5', 'max_tokens' => $output,
            'messages' => [['role' => 'user', 'content' => str_repeat('Northstar source text ', 1000)]],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_guard_rejects_full_possible_cost_before_first_dispatch(): void
    {
        $guard = new NorthstarV9RequestGuard(0.000001);
        try {
            $guard->inspect($this->request());
            self::fail('Over-budget request was admitted.');
        } catch (RuntimeException $e) {
            self::assertSame('northstar_eval_budget_or_request_limit', $e->getMessage());
        }
        self::assertSame(0, $guard->requests);
    }

    public function test_guard_caps_all_transport_and_correction_attempts_at_two(): void
    {
        $guard = new NorthstarV9RequestGuard(0.02);
        $guard->inspect($this->request());
        $guard->inspect($this->request(8192));
        self::assertSame(2, $guard->requests);
        self::assertLessThanOrEqual(0.02, $guard->reservedUsd);
        $this->expectExceptionMessage('northstar_eval_budget_or_request_limit');
        $guard->inspect($this->request());
    }

    public function test_guard_blocks_any_other_http_endpoint(): void
    {
        $guard = new NorthstarV9RequestGuard(0.25);
        $this->expectExceptionMessage('northstar_eval_unexpected_http_request');
        $guard->inspect(new Request('GET', 'https://api.anthropic.com/v1/models'));
    }

    public function test_guard_rejects_an_unpriced_request_option(): void
    {
        $guard = new NorthstarV9RequestGuard(0.25);
        $body = json_decode((string) $this->request()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $body['inference_geo'] = 'us';
        $this->expectExceptionMessage('northstar_eval_unexpected_provider_payload');
        $guard->inspect(new Request('POST', 'https://api.anthropic.com/v1/messages', [],
            json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function test_http_dispatch_middleware_caps_every_attempt(): void
    {
        $guard = new NorthstarV9RequestGuard(0.25);
        Http::globalRequestMiddleware(fn ($request) => $guard->inspect($request));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);
        $payload = json_decode((string) $this->request()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        Http::post('https://api.anthropic.com/v1/messages', $payload);
        Http::post('https://api.anthropic.com/v1/messages', $payload);
        self::assertSame(2, $guard->requests);
        self::assertCount(2, Http::recorded());
        $this->expectExceptionMessage('northstar_eval_budget_or_request_limit');
        Http::post('https://api.anthropic.com/v1/messages', $payload);
    }
}
