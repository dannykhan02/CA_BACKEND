<?php

namespace App\Services\AI;

use GuzzleHttp\Psr7\CachingStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** A process-local paid-request boundary and restricted, replayable HTTP capture. */
class LocalEvaluationCapture
{
    public int $requests = 0;

    public float $reservedUsd = 0;

    public array $attempts = [];

    public function __construct(
        public readonly string $directory,
        private readonly string $model,
        private readonly string $promptHash,
        private readonly int $maxRequests,
        private readonly float $maxUsd,
    ) {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Evaluation capture directory unavailable.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('Could not restrict the evaluation capture directory.');
        }
    }

    public function middleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                if ($request->getMethod() !== 'POST'
                    || (string) $request->getUri() !== 'https://api.anthropic.com/v1/messages') {
                    throw new RuntimeException('Evaluation blocked an unexpected HTTP request.');
                }
                $raw = (string) $request->getBody();
                $body = json_decode($raw, true);
                if (! is_array($body) || ($body['model'] ?? null) !== $this->model
                    || count($body['messages'] ?? []) !== 1
                    || ($body['messages'][0]['role'] ?? null) !== 'user'
                    || ! is_string($body['messages'][0]['content'] ?? null)
                    || ! hash_equals($this->promptHash, hash('sha256', $body['messages'][0]['content']))
                    || ! is_int($body['max_tokens'] ?? null)
                    || $body['max_tokens'] < 1 || $body['max_tokens'] > 20000
                    || array_diff(array_keys($body), ['model', 'max_tokens', 'messages']) !== []) {
                    throw new RuntimeException('Evaluation blocked an unexpected provider payload.');
                }
                $inputBound = 512 + strlen($raw);
                $maximum = app(AiPricing::class)->maximum($this->model, $inputBound, $body['max_tokens'], true);
                if ($maximum === null || ! is_finite($maximum) || $this->requests >= $this->maxRequests
                    || $this->reservedUsd + $maximum > $this->maxUsd) {
                    throw new RuntimeException('Evaluation request or spending limit exhausted.');
                }
                $this->requests++;
                $this->reservedUsd += $maximum;
                $number = $this->requests;
                $this->write("request-{$number}.json", $raw);
                $this->attempts[] = ['number' => $number, 'method' => 'POST',
                    'url' => 'https://api.anthropic.com/v1/messages',
                    'anthropic_version' => $request->getHeaderLine('anthropic-version'),
                    'request_sha256' => hash('sha256', $raw),
                    'input_token_bound' => $inputBound, 'maximum_reserved_usd' => $maximum,
                    'response_file' => null, 'http_status' => null, 'usage' => null,
                    'estimated_cost_usd' => null, 'duration_ms' => null];
                $started = hrtime(true);
                try {
                    $promise = $handler($request, $options);
                } catch (\Throwable $error) {
                    $this->attempts[$number - 1]['duration_ms'] = (int) ((hrtime(true) - $started) / 1e6);
                    throw $error;
                }

                return $promise->then(function (ResponseInterface $response) use ($number, $started) {
                    $stream = $response->getBody();
                    if (! $stream->isSeekable()) {
                        $stream = new CachingStream($stream);
                        $response = $response->withBody($stream);
                    }
                    $position = $stream->tell();
                    $stream->rewind();
                    $raw = (string) $stream;
                    $stream->seek($position);
                    $this->write("response-{$number}.json", $raw);
                    $decoded = json_decode($raw, true);
                    $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null;
                    $this->attempts[$number - 1] = array_replace($this->attempts[$number - 1], [
                        'response_file' => "response-{$number}.json",
                        'response_sha256' => hash('sha256', $raw),
                        'http_status' => $response->getStatusCode(),
                        'stop_reason' => $decoded['stop_reason'] ?? null,
                        'usage' => $usage,
                        'estimated_cost_usd' => $usage ? app(AiPricing::class)->estimate($this->model, $usage) : null,
                        'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                    ]);

                    return $response;
                });
            };
        };
    }

    public function write(string $name, string $contents): string
    {
        if (! preg_match('/\A[a-z0-9.-]+\z/', $name)) {
            throw new RuntimeException('Invalid evaluation file name.');
        }
        $path = $this->directory.'/'.$name;
        if (file_put_contents($path, $contents, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Could not write restricted evaluation capture.');
        }

        return $path;
    }
}
