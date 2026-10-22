<?php

namespace Tests\Feature;

use App\Services\AI\LocalEvaluationCapture;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalEvaluationCaptureTest extends TestCase
{
    public function test_offline_capture_preserves_exact_body_and_blocks_a_second_request(): void
    {
        $directory = storage_path('framework/testing/docintel-local-evaluation/test-'.bin2hex(random_bytes(5)));
        $capture = new LocalEvaluationCapture($directory, 'claude-haiku-5-5', hash('sha256', 'safe prompt'), 1, 0.01);
        Http::globalMiddleware($capture->middleware());
        $response = '{"model":"claude-haiku-5-5","stop_reason":"end_turn","usage":{"input_tokens":10,"output_tokens":5},"content":[]}';
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($response, 200,
            ['Content-Type' => 'application/json'])]);
        $body = ['model' => 'claude-haiku-5-5', 'max_tokens' => 14000,
            'messages' => [['role' => 'user', 'content' => 'safe prompt']]];
        try {
            $result = Http::withHeaders(['x-api-key' => 'TEST-SECRET'])
                ->post('https://api.anthropic.com/v1/messages', $body);
            self::assertSame(200, $result->status());
            self::assertSame($response, file_get_contents($directory.'/response-1.json'));
            self::assertSame(1, $capture->requests);
            self::assertSame(0600, fileperms($directory.'/request-1.json') & 0777);
            self::assertSame(0600, fileperms($directory.'/response-1.json') & 0777);
            self::assertSame(0700, fileperms($directory) & 0777);
            self::assertStringNotContainsString('TEST-SECRET', file_get_contents($directory.'/request-1.json'));
            $this->expectExceptionMessage('Evaluation request or spending limit exhausted.');
            Http::post('https://api.anthropic.com/v1/messages', $body);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_budget_is_checked_before_dispatch(): void
    {
        $directory = storage_path('framework/testing/docintel-local-evaluation/test-'.bin2hex(random_bytes(5)));
        $capture = new LocalEvaluationCapture($directory, 'claude-haiku-5-5', hash('sha256', 'safe prompt'), 1, 0.000001);
        Http::globalMiddleware($capture->middleware());
        Http::fake(['api.anthropic.com/v1/messages' => Http::response(['content' => []])]);
        try {
            try {
                Http::post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-5-5', 'max_tokens' => 14000,
                    'messages' => [['role' => 'user', 'content' => 'safe prompt']],
                ]);
                self::fail('Budget denial should stop before HTTP dispatch.');
            } catch (\RuntimeException $error) {
                self::assertSame('Evaluation request or spending limit exhausted.', $error->getMessage());
            }
            self::assertSame(0, $capture->requests);
            self::assertFileDoesNotExist($directory.'/request-1.json');
        } finally {
            rmdir($directory);
        }
    }
}
