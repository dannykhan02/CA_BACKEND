<?php

namespace Tests\Unit;

use App\Exceptions\AnthropicStructuredOutputException;
use App\Models\AiPrompt;
use App\Services\AI\PromptManager;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AnthropicStructuredOutputTest extends TestCase
{
    private function decode(array $response): array
    {
        return (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))
            ->invoke(app(AnthropicClient::class), $response);
    }

    private function response(string $text, string $stop = 'end_turn'): array
    {
        return ['model' => 'test-model', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function test_valid_json_fences_whitespace_and_ordered_text_blocks(): void
    {
        foreach ([' {"entities":[]} ', "```json\n{\"entities\":[]}\n```", "```\n{\"entities\":[]}\n```"] as $text) {
            $this->assertSame(['entities' => []], $this->decode($this->response($text)));
        }
        $response = $this->response('{"entities":');
        $response['content'][] = ['type' => 'thinking', 'thinking' => 'ignored'];
        $response['content'][] = ['type' => 'text', 'text' => '[]}'];
        $this->assertSame(['entities' => []], $this->decode($response));
    }

    public function test_malformed_prose_and_truncation_are_distinct(): void
    {
        foreach (['{"entities":', 'Here is JSON: {"entities":[]}'] as $text) {
            try {
                $this->decode($this->response($text));
                $this->fail('Malformed output accepted.');
            } catch (AnthropicStructuredOutputException $e) {
                $this->assertSame('malformed_output', $e->outputStatus);
                $this->assertStringNotContainsString('No error', $e->getMessage());
            }
        }
        try {
            $this->decode($this->response('{"entities":', 'max_tokens'));
            $this->fail('Truncation accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('truncated', $e->outputStatus);
        }
    }

    public function test_diagnostic_preview_is_bounded(): void
    {
        Log::spy();
        try {
            $this->decode($this->response(str_repeat('x', 5000)));
        } catch (AnthropicStructuredOutputException) {
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            $this->assertArrayNotHasKey('raw_text', $context);
            $this->assertArrayNotHasKey('response_preview', $context);
            $this->assertSame(5000, $context['response_characters']);
            $this->assertSame('Syntax error', $context['json_error']);

            return true;
        });
    }

    public function test_malformed_response_retries_once_and_validates_schema(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('invalid'))
            ->push($this->response('{"entities":[]}'))]);
        $client = app(AnthropicClient::class);
        $parse = fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))
            ->invoke($client, $response);
        $result = (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))
            ->invoke($client, 'Return JSON', null, 'entities', $parse);
        $this->assertSame(['entities' => []], $result);
        Http::assertSentCount(2);
    }

    public function test_second_malformed_response_fails_without_another_retry(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('bad'))->push($this->response('still bad'))]);
        $client = app(AnthropicClient::class);
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', null, 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
            );
            $this->fail('Second malformed response accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('malformed_output', $e->outputStatus);
        }
        Http::assertSentCount(2);
    }

    public function test_max_tokens_retry_increases_allowance(): void
    {
        config(['services.anthropic.max_tokens' => 100, 'services.anthropic.structured_max_tokens_ceiling' => 200]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('{"entities":', 'max_tokens'))
            ->push($this->response('{"entities":[]}'))]);
        $client = app(AnthropicClient::class);
        (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
            $client, 'Return JSON', null, 'entities',
            fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
        );
        $requests = Http::recorded()->map(fn ($entry) => $entry[0]['max_tokens'])->all();
        $this->assertSame([100, 200], $requests);
    }

    public function test_valid_json_with_invalid_schema_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new \ReflectionMethod(AnthropicClient::class, 'parseEntitiesResponse'))
            ->invoke(app(AnthropicClient::class), $this->response('{"entities":"wrong"}'));
    }

    public function test_insights_require_complete_envelope_and_keep_large_input_with_output_caps(): void
    {
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'parseInsightsResponse'))
                ->invoke(app(AnthropicClient::class), $this->response('{}'));
            $this->fail('Missing insights schema accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('required array field', $e->getMessage());
        }

        config(['document_processing.max_extraction_chars' => 60000]);
        $prompt = new AiPrompt(['name' => 'document_insights', 'version' => 7,
            'template' => "<document>\n{{document_text}}\n</document>"]);
        $manager = \Mockery::mock(PromptManager::class);
        $manager->shouldReceive('resolve')->once()->with('document_insights')->andReturn($prompt);
        $manager->shouldReceive('render')->once()->andReturnUsing(fn ($model, $vars) => strtr($model->template, $vars));
        $this->app->instance(PromptManager::class, $manager);
        $text = str_repeat('A', 60000);
        $rendered = (new \ReflectionMethod(AnthropicClient::class, 'buildInsightsPrompt'))
            ->invoke(app(AnthropicClient::class), $text, 'Report', 'Internal');
        $this->assertStringContainsString($text, $rendered);
        $this->assertStringContainsString('at most 12 high-value KPIs', $rendered);
        $this->assertStringContainsString('3 charts', $rendered);
    }

    public function test_max_tokens_at_ceiling_does_not_retry_identical_request(): void
    {
        config(['services.anthropic.max_tokens' => 200, 'services.anthropic.structured_max_tokens_ceiling' => 200]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('{"entities":', 'max_tokens'))]);
        $client = app(AnthropicClient::class);
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', null, 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
            );
            $this->fail('Truncated response accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('truncated', $e->outputStatus);
        }
        Http::assertSentCount(1);
    }

    public function test_refusal_is_not_retried_as_malformed_json(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('', 'refusal'))]);
        $client = app(AnthropicClient::class);
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', null, 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
            );
            $this->fail('Refusal accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('provider_error', $e->outputStatus);
        }
        Http::assertSentCount(1);
    }
}
