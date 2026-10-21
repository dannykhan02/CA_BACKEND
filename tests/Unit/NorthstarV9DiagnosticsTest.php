<?php

namespace Tests\Unit;

use App\Exceptions\AnthropicStructuredOutputException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NorthstarV9Diagnostics;
use Tests\Support\NorthstarV9RequestGuard;
use Tests\TestCase;

class NorthstarV9DiagnosticsTest extends TestCase
{
    private const SECRET = 'SYNTHETIC_CREDENTIAL_MUST_NOT_BE_RECORDED';

    private array $reports = [];

    protected function tearDown(): void
    {
        foreach ($this->reports as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function diagnostics(float $budget = 0.02, string $mode = 'recorded'): NorthstarV9Diagnostics
    {
        $diagnostics = new NorthstarV9Diagnostics(new NorthstarV9RequestGuard($budget), ['mode' => $mode]);
        $this->reports[] = $diagnostics->path();
        Http::globalMiddleware($diagnostics->middleware());

        return $diagnostics;
    }

    private function payload(): array
    {
        return ['model' => 'claude-haiku-5-5', 'max_tokens' => 6400,
            'messages' => [['role' => 'user', 'content' => 'Synthetic source text']]];
    }

    private function response(string $stop = 'end_turn'): array
    {
        return ['stop_reason' => $stop, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20,
            'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            'unknown_sensitive_field' => self::SECRET],
            'content' => [['type' => 'text', 'text' => self::SECRET]],
            'error' => ['message' => self::SECRET]];
    }

    private function read(NorthstarV9Diagnostics $diagnostics): array
    {
        $json = file_get_contents($diagnostics->path());
        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringNotContainsString('x-api-key', $json);
        self::assertStringNotContainsString('Authorization', $json);
        self::assertSame(0600, fileperms($diagnostics->path()) & 0777);
        self::assertSame(0700, fileperms(dirname($diagnostics->path())) & 0777);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_success_preserves_response_and_records_only_allowlisted_attempt_metadata(): void
    {
        $diagnostics = $this->diagnostics();
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(), 200,
            ['x-sensitive-response-header' => self::SECRET])]);
        $response = $diagnostics->capture(fn () => Http::withHeaders([
            'x-api-key' => self::SECRET, 'Authorization' => self::SECRET,
        ])->post('https://api.anthropic.com/v1/messages', $this->payload()));
        self::assertSame(self::SECRET, $response->json('content.0.text'));
        $report = $this->read($diagnostics);
        self::assertSame('success', $report['outcome']);
        self::assertSame(1, $report['requests']);
        self::assertSame('end_turn', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame(['input_tokens' => 100, 'output_tokens' => 20,
            'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], $report['provider_attempts'][0]['usage']);
        self::assertNull($report['provider_attempts'][0]['error_category']);
        self::assertIsInt($report['provider_attempts'][0]['latency_ms']);
        self::assertGreaterThanOrEqual(0, $report['provider_attempts'][0]['latency_ms']);
        self::assertEquals(0.00002, $report['known_estimated_cost_usd']);
        self::assertTrue($report['cost_complete']);
    }

    public function test_live_model_text_capture_redacts_credentials_and_excludes_error_envelope(): void
    {
        $diagnostics = $this->diagnostics(mode: 'live');
        $body = $this->response();
        $body['content'][0]['text'] = 'Raw model JSON '.self::SECRET;
        Http::fake(['api.anthropic.com/*' => Http::response($body)]);
        $diagnostics->capture(fn () => Http::withHeaders(['x-api-key' => self::SECRET])
            ->post('https://api.anthropic.com/v1/messages', $this->payload()));
        $report = $this->read($diagnostics);
        self::assertSame(['Raw model JSON [REDACTED]'], $report['provider_attempts'][0]['model_output_text']);
    }

    public function test_truncation_and_original_exception_are_preserved_without_exception_text(): void
    {
        $diagnostics = $this->diagnostics();
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('max_tokens'))]);
        $exception = new AnthropicStructuredOutputException('truncated', self::SECRET);
        try {
            $diagnostics->capture(function () use ($exception) {
                Http::post('https://api.anthropic.com/v1/messages', $this->payload());
                throw $exception;
            });
            self::fail('The original exception must propagate.');
        } catch (AnthropicStructuredOutputException $caught) {
            self::assertSame($exception, $caught);
        }
        $report = $this->read($diagnostics);
        self::assertSame('failed', $report['outcome']);
        self::assertSame('truncated', $report['error_category']);
        self::assertSame('max_tokens', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame('truncated', $report['provider_attempts'][0]['error_category']);
        self::assertSame(20, $report['provider_attempts'][0]['usage']['output_tokens']);
    }

    public static function providerErrors(): array
    {
        return ['authentication' => [401, 'authentication'], 'rate limit' => [429, 'rate_limit'],
            'server error' => [500, 'provider_error']];
    }

    #[DataProvider('providerErrors')]
    public function test_provider_errors_are_saved_before_application_handling(int $status, string $category): void
    {
        $diagnostics = $this->diagnostics();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => self::SECRET]], $status)]);
        try {
            $diagnostics->capture(function () use ($diagnostics) {
                $response = Http::post('https://api.anthropic.com/v1/messages', $this->payload());
                // The HTTP response is already durable even before the application throws.
                self::assertSame('response_received', $this->read($diagnostics)['provider_attempts'][0]['state']);
                $response->throw();
            });
            self::fail('Provider error must propagate.');
        } catch (RequestException) {
        }
        $report = $this->read($diagnostics);
        self::assertSame('failed', $report['outcome']);
        self::assertSame($category, $report['provider_attempts'][0]['error_category']);
        self::assertSame($status, $report['provider_attempts'][0]['http_status']);
        self::assertNull($report['provider_attempts'][0]['usage']);
        self::assertNull($report['provider_attempts'][0]['stop_reason']);
        self::assertFalse($report['cost_complete']);
    }

    public function test_rejected_transport_promise_records_latency_and_unknown_usage(): void
    {
        $diagnostics = $this->diagnostics();
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages', [], json_encode($this->payload()));
        $exception = new ConnectException(self::SECRET, $request, handlerContext: ['errno' => 28]);
        Http::fake(['api.anthropic.com/*' => fn () => Create::rejectionFor($exception)]);
        try {
            $diagnostics->capture(fn () => Http::post('https://api.anthropic.com/v1/messages', $this->payload()));
            self::fail('Connection error must propagate.');
        } catch (ConnectionException) {
        }
        $report = $this->read($diagnostics);
        self::assertSame('failed', $report['outcome']);
        self::assertSame('timeout', $report['provider_attempts'][0]['error_category']);
        self::assertIsInt($report['provider_attempts'][0]['latency_ms']);
        self::assertNull($report['provider_attempts'][0]['usage']);
        self::assertFalse($report['cost_complete']);
    }

    public function test_synchronous_dispatch_exception_is_saved_and_rethrown(): void
    {
        $diagnostics = $this->diagnostics();
        $exception = new RuntimeException(self::SECRET);
        Http::fake(['api.anthropic.com/*' => function () use ($exception) {
            throw $exception;
        }]);
        try {
            $diagnostics->capture(fn () => Http::post('https://api.anthropic.com/v1/messages', $this->payload()));
            self::fail('Synchronous exception must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        $report = $this->read($diagnostics);
        self::assertSame('evaluation_exception', $report['error_category']);
        self::assertSame('transport_error', $report['provider_attempts'][0]['state']);
        self::assertIsInt($report['provider_attempts'][0]['latency_ms']);
    }

    public function test_later_pipeline_exception_keeps_successful_attempt_diagnostics(): void
    {
        $diagnostics = $this->diagnostics();
        Http::fake(['api.anthropic.com/*' => Http::response($this->response())]);
        try {
            $diagnostics->capture(function () use ($diagnostics) {
                Http::post('https://api.anthropic.com/v1/messages', $this->payload());
                $diagnostics->recordResult(['api_serialization_error' => ['error_category' => 'evaluation_exception']]);
                throw new RuntimeException(self::SECRET);
            });
        } catch (RuntimeException) {
        }
        $report = $this->read($diagnostics);
        self::assertSame('failed', $report['outcome']);
        self::assertSame('evaluation_exception', $report['error_category']);
        self::assertSame('end_turn', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame(100, $report['provider_attempts'][0]['usage']['input_tokens']);
        self::assertSame(['error_category' => 'evaluation_exception'], $report['api_serialization_error']);
    }

    public function test_over_budget_request_is_not_dispatched_and_failure_report_is_saved(): void
    {
        $diagnostics = $this->diagnostics(0.000001);
        Http::fake();
        try {
            $diagnostics->capture(fn () => Http::post('https://api.anthropic.com/v1/messages', $this->payload()));
            self::fail('Over-budget request must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame('northstar_eval_budget_or_request_limit', $error->getMessage());
        }
        Http::assertNothingSent();
        $report = $this->read($diagnostics);
        self::assertSame('budget_or_request_limit', $report['error_category']);
        self::assertSame(0, $report['requests']);
        self::assertSame([], $report['provider_attempts']);
    }

    public function test_third_request_is_rejected_without_a_third_attempt_record(): void
    {
        $diagnostics = $this->diagnostics();
        Http::fake(['api.anthropic.com/*' => Http::response($this->response())]);
        try {
            $diagnostics->capture(function () {
                for ($i = 0; $i < 3; $i++) {
                    Http::post('https://api.anthropic.com/v1/messages', $this->payload());
                }
            });
            self::fail('Third request must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame('northstar_eval_budget_or_request_limit', $error->getMessage());
        }
        Http::assertSentCount(2);
        $report = $this->read($diagnostics);
        self::assertSame(2, $report['requests']);
        self::assertCount(2, $report['provider_attempts']);
        self::assertLessThanOrEqual(0.02, $report['maximum_reserved_usd']);
    }

    public function test_untrusted_stop_reason_and_usage_values_are_not_copied(): void
    {
        $diagnostics = $this->diagnostics();
        $response = $this->response(self::SECRET);
        $response['usage']['input_tokens'] = self::SECRET;
        Http::fake(['api.anthropic.com/*' => Http::response($response)]);
        $diagnostics->capture(fn () => Http::post('https://api.anthropic.com/v1/messages', $this->payload()));
        $report = $this->read($diagnostics);
        self::assertSame('unrecognized', $report['provider_attempts'][0]['stop_reason']);
        self::assertSame('unexpected_stop_reason', $report['provider_attempts'][0]['error_category']);
        self::assertNull($report['provider_attempts'][0]['usage']['input_tokens']);
        self::assertNull($report['provider_attempts'][0]['estimated_cost_usd']);
    }
}
