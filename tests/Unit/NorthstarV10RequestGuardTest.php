<?php

namespace Tests\Unit;

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\NorthstarV10RequestGuard;
use Tests\TestCase;

class NorthstarV10RequestGuardTest extends TestCase
{
    private const PROMPT = 'V10 independently cited observations from Northstar';

    private function guard(float $budget = 0.01): NorthstarV10RequestGuard
    {
        return new NorthstarV10RequestGuard($budget, hash('sha256', self::PROMPT));
    }

    private function request(array $changes = []): Request
    {
        return new Request('POST', 'https://api.anthropic.com/v1/messages', [], json_encode(array_replace([
            'model' => 'claude-haiku-5-5', 'max_tokens' => 14000,
            'messages' => [['role' => 'user', 'content' => self::PROMPT]],
        ], $changes), JSON_THROW_ON_ERROR));
    }

    public function test_one_request_is_reserved_at_the_full_possible_cost_and_a_second_is_blocked(): void
    {
        $guard = $this->guard();
        $guard->inspect($this->request());
        self::assertSame(1, $guard->requests);
        self::assertGreaterThan(0, $guard->reservedUsd);
        self::assertLessThanOrEqual(0.01, $guard->reservedUsd);
        self::assertSame(14000, $guard->attempts[0]['max_output_tokens']);
        $this->expectExceptionMessage('northstar_eval_budget_or_request_limit');
        $guard->inspect($this->request());
    }

    public function test_budget_is_checked_before_any_dispatch(): void
    {
        $guard = $this->guard(0.000001);
        try {
            $guard->inspect($this->request());
            self::fail('Over-budget request was admitted.');
        } catch (RuntimeException $error) {
            self::assertSame('northstar_eval_budget_or_request_limit', $error->getMessage());
        }
        self::assertSame(0, $guard->requests);
    }

    public function test_prompt_model_output_cap_and_extra_options_cannot_change(): void
    {
        foreach ([
            ['messages' => [['role' => 'user', 'content' => 'Different prompt']]],
            ['model' => 'claude-sonnet-5-5'],
            ['max_tokens' => 20000],
            ['system' => 'Additional paid input'],
            ['messages' => [['role' => 'user', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => 'Correction']]],
        ] as $change) {
            $guard = $this->guard();
            try {
                $guard->inspect($this->request($change));
                self::fail('Unexpected provider payload was admitted.');
            } catch (RuntimeException $error) {
                self::assertSame('northstar_eval_unexpected_provider_payload', $error->getMessage());
            }
            self::assertSame(0, $guard->requests);
        }
    }

    public function test_guard_runs_at_http_boundary_even_with_mocked_provider_responses(): void
    {
        $guard = $this->guard();
        Http::globalRequestMiddleware(fn ($request) => $guard->inspect($request));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);
        $body = json_decode((string) $this->request()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        Http::post('https://api.anthropic.com/v1/messages', $body);
        self::assertCount(1, Http::recorded());
        $this->expectExceptionMessage('northstar_eval_budget_or_request_limit');
        Http::post('https://api.anthropic.com/v1/messages', $body);
    }

    public function test_other_endpoints_and_invalid_budget_configuration_are_blocked(): void
    {
        $this->expectExceptionMessage('northstar_eval_invalid_guard_configuration');
        $this->guard(0.011);
    }

    public function test_other_endpoint_is_blocked(): void
    {
        $this->expectExceptionMessage('northstar_eval_unexpected_http_request');
        $this->guard()->inspect(new Request('GET', 'https://api.anthropic.com/v1/models'));
    }
}
