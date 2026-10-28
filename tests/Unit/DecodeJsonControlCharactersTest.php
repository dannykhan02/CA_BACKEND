<?php

namespace Tests\Unit;

use App\Exceptions\AnthropicStructuredOutputException;
use App\Services\AnthropicClient;
use Tests\TestCase;

/** Raw control characters inside a JSON string are repaired; anything still unusable is the retryable malformed_output failure. */
class DecodeJsonControlCharactersTest extends TestCase
{
    private function decode(string $text): array
    {
        $response = ['model' => 'test-model', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            'content' => [['type' => 'text', 'text' => $text]]];

        return (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke(app(AnthropicClient::class), $response);
    }

    public function test_a_raw_newline_and_tab_inside_a_string_decode(): void
    {
        $decoded = $this->decode("{\"deadlines\":[{\"label\":\"Pay in\nfull\",\"note\":\"a\tb\"}]}");

        self::assertSame("Pay in\nfull", $decoded['deadlines'][0]['label']);
        self::assertSame("a\tb", $decoded['deadlines'][0]['note']);
    }

    public function test_other_control_characters_and_carriage_returns_are_escaped(): void
    {
        $decoded = $this->decode("{\"a\":\"x\ry\x01z\"}");

        self::assertSame("x\ry\x01z", $decoded['a']);
    }

    public function test_structural_whitespace_and_existing_escapes_are_untouched(): void
    {
        $decoded = $this->decode("{\n\t\"a\": \"line\\nbreak \\\" quote\",\r\n  \"b\": [1,\n2]\n}");

        self::assertSame(['a' => "line\nbreak \" quote", 'b' => [1, 2]], $decoded);
    }

    public function test_valid_json_is_not_altered(): void
    {
        self::assertSame(['a' => 'ü €', 'b' => []], $this->decode('{"a":"ü €","b":[]}'));
    }

    public function test_unrecoverable_input_raises_the_retryable_malformed_output_exception(): void
    {
        try {
            $this->decode("{\"a\":\"broken\nvalue");
            self::fail('Expected a structured-output failure.');
        } catch (AnthropicStructuredOutputException $e) {
            self::assertSame('malformed_output', $e->outputStatus);
            self::assertStringContainsString('malformed_output', $e->getMessage());
        }
    }
}
