<?php

namespace Tests\Feature;

use App\Services\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderErrorDetailTest extends TestCase
{
    public function test_a_provider_400_surfaces_its_error_type_and_message_without_request_data(): void
    {
        $secretImage = base64_encode(random_bytes(3000));
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'example']], 400)]);

        try {
            app(AnthropicClient::class)->extractTextFromImage($secretImage, 'image/png');
            $this->fail('A 400 from the provider must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status 400', $e->getMessage());
            $this->assertStringContainsString('invalid_request_error', $e->getMessage());
            $this->assertStringContainsString('example', $e->getMessage());
            $this->assertStringNotContainsString(substr($secretImage, 0, 60), $e->getMessage());
        }
    }

    public function test_a_provider_message_that_echoes_long_encoded_data_is_redacted_and_bounded(): void
    {
        $echo = base64_encode(random_bytes(3000));
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'message' => "bad data {$echo} end"]], 400)]);

        try {
            app(AnthropicClient::class)->extractTextFromImage(base64_encode('x'), 'image/png');
            $this->fail('A 400 from the provider must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(substr($echo, 0, 60), $e->getMessage());
            $this->assertLessThan(500, strlen($e->getMessage()));
        }
    }

    public function test_a_response_without_an_error_body_keeps_the_original_message(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('not json', 400)]);

        try {
            app(AnthropicClient::class)->extractTextFromImage(base64_encode('x'), 'image/png');
            $this->fail('A 400 from the provider must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Anthropic API request failed with status 400.', $e->getMessage());
        }
    }
}
