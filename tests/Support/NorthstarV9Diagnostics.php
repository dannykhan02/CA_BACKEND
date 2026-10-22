<?php

namespace Tests\Support;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Services\AI\AiPricing;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\CachingStream;
use Illuminate\Http\Client\ConnectionException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Test-only diagnostics. Never retain request headers, raw responses or exception messages. */
final class NorthstarV9Diagnostics
{
    private array $attempts = [];

    private array $report;

    private string $path;

    public function __construct(private readonly NorthstarV9RequestGuard $guard, array $metadata)
    {
        $this->report = $metadata + ['outcome' => 'running', 'error_category' => null];
        $dir = dirname(__DIR__, 2).'/storage/framework/testing/docintel-v9-evaluation';
        if ((! is_dir($dir) && ! mkdir($dir, 0700, true)) || ! chmod($dir, 0700)) {
            throw new RuntimeException('northstar_eval_diagnostic_directory_unavailable');
        }
        $mode = ($metadata['mode'] ?? null) === 'live' ? 'live' : 'recorded';
        $this->path = $dir.'/'.$mode.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $this->persist(); // Fail before any dispatch if a restricted report cannot be written.
    }

    public function path(): string
    {
        return $this->path;
    }

    public function capture(callable $evaluation): mixed
    {
        try {
            $result = $evaluation();
            $this->report['outcome'] = 'success';

            return $result;
        } catch (Throwable $error) {
            $this->report['outcome'] = 'failed';
            $this->report['error_category'] = self::errorCategory($error);
            throw $error;
        } finally {
            $this->persist();
        }
    }

    /** Only the harness's explicit analytical report belongs here, never a request or exception. */
    public function recordResult(array $report): void
    {
        $this->report = array_replace($this->report, $report);
        $this->persist();
    }

    /** The synchronous application parser records its verdict before another request starts. */
    public function recordValidationOutcome(string $status): void
    {
        $index = array_key_last($this->attempts);
        if ($index === null || $this->attempts[$index]['state'] !== 'response_received'
            || $this->attempts[$index]['http_status'] < 200 || $this->attempts[$index]['http_status'] >= 300
            || ! in_array($status, ['success', 'malformed_output', 'truncated', 'invalid_schema', 'empty_response'], true)) {
            return;
        }
        $this->attempts[$index]['validation_status'] = $status;
        if ($status !== 'success') {
            $this->attempts[$index]['error_category'] = $status;
        }
        $this->persist();
    }

    public function middleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                // Use the existing guard at the actual HTTP boundary, including every retry.
                $request = $this->guard->inspect($request);
                $index = count($this->attempts);
                $this->attempts[] = ['attempt' => $index + 1, 'state' => 'dispatching',
                    'request_body_sha256' => hash('sha256', (string) $request->getBody()),
                    'http_status' => null, 'stop_reason' => null, 'usage' => null,
                    'latency_ms' => null, 'estimated_cost_usd' => null, 'error_category' => null,
                    'validation_status' => null];
                $this->persist();
                $started = hrtime(true);
                try {
                    $promise = $handler($request, $options);
                } catch (Throwable $error) {
                    $this->recordException($index, $started, $error);
                    throw $error;
                }

                $credentials = array_filter([...$request->getHeader('x-api-key'), ...$request->getHeader('authorization')]);

                return $promise->then(function (ResponseInterface $response) use ($index, $started, $credentials) {
                    $elapsed = $this->elapsed($started);
                    // Preserve the response stream for the genuine application parser.
                    $body = $response->getBody();
                    if (! $body->isSeekable()) {
                        $body = new CachingStream($body);
                        $response = $response->withBody($body);
                    }
                    $position = $body->tell();
                    $decoded = json_decode((string) $body, true);
                    $body->seek($position);
                    $stop = $decoded['stop_reason'] ?? null;
                    $stop = in_array($stop, ['end_turn', 'stop_sequence', 'max_tokens', 'refusal',
                        'tool_use', 'pause_turn', 'model_context_window_exceeded'], true)
                        ? $stop : ($stop === null ? null : 'unrecognized');
                    $usage = null;
                    if (is_array($decoded['usage'] ?? null)) {
                        $usage = [];
                        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
                            $value = $decoded['usage'][$key] ?? null;
                            $usage[$key] = is_int($value) && $value >= 0 ? $value : null;
                        }
                    }
                    $cost = isset($usage['input_tokens'], $usage['output_tokens'])
                        ? app(AiPricing::class)->estimate('claude-haiku-5-5', $usage) : null;
                    $status = $response->getStatusCode();
                    $category = self::httpErrorCategory($status);
                    if ($category === null) {
                        $category = match (true) {
                            ! is_array($decoded) => 'invalid_response',
                            $stop === 'max_tokens' => 'truncated',
                            $stop === 'refusal' => 'refusal',
                            ! in_array($stop, ['end_turn', 'stop_sequence'], true) => 'unexpected_stop_reason',
                            default => null,
                        };
                    }
                    $this->attempts[$index] = array_replace($this->attempts[$index], [
                        'state' => 'response_received', 'http_status' => $status, 'stop_reason' => $stop,
                        'usage' => $usage, 'latency_ms' => $elapsed, 'estimated_cost_usd' => $cost,
                        'error_category' => $category]);
                    // The authorized live evaluation retains only model text, never the
                    // response envelope, headers or provider error messages. Redact any
                    // accidental echo of a credential before the private file is written.
                    if (($this->report['mode'] ?? null) === 'live' && $status >= 200 && $status < 300) {
                        $this->attempts[$index]['model_output_text'] = array_values(array_map(
                            fn ($block) => str_replace($credentials, '[REDACTED]', $block['text']),
                            array_filter(is_array($decoded['content'] ?? null) ? $decoded['content'] : [],
                                fn ($block) => is_array($block) && ($block['type'] ?? null) === 'text'
                                    && is_string($block['text'] ?? null))));
                    }
                    $this->persist();

                    return $response;
                }, function ($reason) use ($index, $started) {
                    $this->recordException($index, $started, $reason);

                    return Create::rejectionFor($reason);
                });
            };
        };
    }

    public static function errorCategory(mixed $error): string
    {
        if ($error instanceof AnthropicStructuredOutputException) {
            return in_array($error->outputStatus, ['malformed_output', 'truncated', 'invalid_schema', 'empty_response'], true)
                ? $error->outputStatus : 'structured_output_error';
        }
        if ($error instanceof AiProcessingException) {
            return in_array($error->classification, ['authentication', 'billing', 'invalid_model',
                'timeout', 'transient', 'deterministic', 'terminal_document'], true)
                ? $error->classification : 'provider_error';
        }
        if ($error instanceof ConnectException) {
            return ($error->getHandlerContext()['errno'] ?? null) === 28 ? 'timeout' : 'connection_error';
        }
        if ($error instanceof ConnectionException) {
            return 'connection_error';
        }

        // Match only fixed internal codes. Never store arbitrary exception text or class names.
        return match ($error instanceof RuntimeException ? $error->getMessage() : null) {
            'northstar_eval_budget_or_request_limit' => 'budget_or_request_limit',
            'northstar_eval_unexpected_http_request' => 'unexpected_http_request',
            'northstar_eval_unexpected_provider_payload' => 'unexpected_provider_payload',
            default => 'evaluation_exception',
        };
    }

    private static function httpErrorCategory(int $status): ?string
    {
        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 401 => 'authentication',
            $status === 403 => 'permission',
            $status === 404 => 'invalid_model_or_endpoint',
            $status === 408 => 'timeout',
            $status === 413 => 'request_too_large',
            $status === 429 => 'rate_limit',
            $status === 529 => 'overloaded',
            $status >= 500 => 'provider_error',
            $status >= 400 => 'invalid_request',
            default => 'unexpected_http_status',
        };
    }

    private function recordException(int $index, int $started, mixed $error): void
    {
        $this->attempts[$index] = array_replace($this->attempts[$index], [
            'state' => 'transport_error', 'latency_ms' => $this->elapsed($started),
            'error_category' => self::errorCategory($error)]);
        $this->persist();
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1000000);
    }

    private function persist(): void
    {
        $costs = array_column($this->attempts, 'estimated_cost_usd');
        $report = array_replace($this->report, ['requests' => $this->guard->requests,
            'maximum_reserved_usd' => $this->guard->reservedUsd, 'request_bounds' => $this->guard->attempts,
            'provider_attempts' => $this->attempts,
            'known_estimated_cost_usd' => round(array_sum($costs), 6),
            'cost_complete' => ! in_array(null, $costs, true)]);
        // Atomic replacement keeps the last complete snapshot if writing is interrupted.
        // tempnam creates the file as 0600, inside the already private directory.
        $temporary = tempnam(dirname($this->path), '.northstar-');
        if ($temporary === false || dirname($temporary) !== dirname($this->path)) {
            throw new RuntimeException('northstar_eval_diagnostic_write_failed');
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $json) !== strlen($json)
                || ! rename($temporary, $this->path)) {
                throw new RuntimeException('northstar_eval_diagnostic_write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
