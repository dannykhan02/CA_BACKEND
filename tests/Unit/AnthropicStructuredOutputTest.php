<?php

namespace Tests\Unit;

use App\Exceptions\AnthropicStructuredOutputException;
use App\Exceptions\AiProcessingException;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\PromptManager;
use App\Services\AnthropicClient;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AnthropicStructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        config(['ai_credits.enabled' => false]);
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1,
            'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'extracted_text' => 'Fixture text.']);
    }

    private function entitiesClient(): AnthropicClient
    {
        $client = app(AnthropicClient::class);
        (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'entities');

        return $client;
    }

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
        foreach (['{"entities":', '{"entities":[]}'] as $text) {
            try {
                $this->decode($this->response($text, 'max_tokens'));
                $this->fail('Truncation accepted.');
            } catch (AnthropicStructuredOutputException $e) {
                $this->assertSame('truncated', $e->outputStatus);
            }
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
        config(['document_intelligence.structured_models' => []]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('invalid'))
            ->push($this->response('{"entities":[]}'))]);
        $client = $this->entitiesClient();
        $parse = fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))
            ->invoke($client, $response);
        $result = (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))
            ->invoke($client, 'Return JSON', $this->document(), 'entities', $parse);
        $this->assertSame(['entities' => []], $result);
        Http::assertSentCount(2);
    }

    public function test_second_malformed_response_fails_without_another_retry(): void
    {
        config(['document_intelligence.structured_models' => []]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('bad'))->push($this->response('still bad'))]);
        $client = $this->entitiesClient();
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', $this->document(), 'entities',
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
        $client = $this->entitiesClient();
        (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
            $client, 'Return JSON', $this->document(), 'entities',
            fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response),
            ['max_tokens' => 100]
        );
        $requests = Http::recorded()->map(fn ($entry) => $entry[0]['max_tokens'])->all();
        $this->assertSame([100, 200], $requests);
    }

    public function test_other_structured_operations_keep_the_generic_output_cap(): void
    {
        config(['services.anthropic.max_tokens' => 1234,
            'document_intelligence.structured_output_tokens.insights' => 6400,
            'document_intelligence.structured_output_tokens.entities' => 6144]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('{"risks":[]}'))]);
        $client = app(AnthropicClient::class);
        (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'risks');
        $result = (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
            $client, 'Return JSON', $this->document(), 'risks',
            fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
        );

        self::assertSame(['risks' => []], $result);
        Http::assertSentCount(1);
        self::assertSame(1234, Http::recorded()->first()[0]['max_tokens']);
    }

    public function test_insights_truncation_records_both_paid_responses_and_only_returns_the_complete_json(): void
    {
        config(['document_intelligence.structured_output_tokens.insights' => 6400,
            'services.anthropic.structured_max_tokens_ceiling' => 8192]);
        $prompt = new AiPrompt(['name' => 'document_insights', 'version' => 8,
            'template' => '<document>{{document_text}}</document>']);
        $manager = \Mockery::mock(PromptManager::class);
        $manager->shouldReceive('resolve')->once()->with('document_insights')->andReturn($prompt);
        $manager->shouldReceive('render')->once()->andReturnUsing(
            fn ($model, $vars) => strtr($model->template, $vars));
        $this->app->instance(PromptManager::class, $manager);
        $first = $this->response('{"kpis":[', 'max_tokens');
        $first['usage']['output_tokens'] = 6400;
        $second = $this->response('{"kpis":[],"charts":[],"insights":[]}');
        $second['usage']['output_tokens'] = 5000;
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($first)->push($second)]);
        $document = $this->document();

        $result = app(AnthropicClient::class)->extractDocumentInsights(
            $document->extracted_text, $document->name, $document);

        self::assertSame([], $result['kpis']);
        self::assertSame(8, $result['prompt_version']);
        self::assertSame([6400, 8192], Http::recorded()
            ->map(fn ($entry) => $entry[0]['max_tokens'])->all());
        self::assertSame(1, DocumentAiRun::where('document_id', $document->id)
            ->where('status', 'truncated')->where('output_tokens', 6400)->count());
        self::assertSame(1, DocumentAiRun::where('document_id', $document->id)
            ->where('status', 'success')->where('output_tokens', 5000)->count());
        self::assertSame(2, DB::table('provider_call_attempts')
            ->where('scope_key', 'document:'.$document->id)->where('status', 'settled')->count());
        self::assertSame(0, DocumentKpi::where('document_id', $document->id)->count());
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
        $client = $this->entitiesClient();
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', $this->document(), 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response),
                ['max_tokens' => 200]
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
        $client = $this->entitiesClient();
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', $this->document(), 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response)
            );
            $this->fail('Refusal accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('refusal', $e->outputStatus);
        }
        Http::assertSentCount(1);
    }

    public function test_literal_control_character_is_classified_before_cleaning(): void
    {
        $raw = "{\"risks\":[{\"title\":\"line\nfeed\"}]}";
        try {
            $this->decode($this->response($raw));
            $this->fail('Literal newline in a JSON string was accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('malformed_output', $e->outputStatus);
            $this->assertSame('raw_output', $e->diagnostics['parse_phase']);
            $this->assertSame(JSON_ERROR_CTRL_CHAR, $e->diagnostics['json_error_category']);
        }
    }

    public function test_escaped_newlines_quotes_backslashes_and_unicode_survive_cleaning(): void
    {
        $value = "line\n\"quoted\" \\ path ☃";
        $raw = json_encode(['risks' => [['evidence' => $value]]], JSON_THROW_ON_ERROR);
        $this->assertSame($value, $this->decode($this->response("```json\n{$raw}\n```"))['risks'][0]['evidence']);
        $this->assertSame($value, $this->decode($this->response(" \n{$raw}\n "))['risks'][0]['evidence']);
    }

    public function test_tool_block_and_multiple_complete_json_blocks_are_not_accepted(): void
    {
        $tool = $this->response('');
        $tool['content'] = [['type' => 'tool_use', 'input' => ['risks' => []]]];
        try {
            $this->decode($tool);
            $this->fail('Tool block was accepted as a text response.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('unsupported_response', $e->outputStatus);
        }
        $two = $this->response('{"risks":[]}');
        $two['content'][] = ['type' => 'text', 'text' => '{"risks":[]}'];
        $this->expectException(AnthropicStructuredOutputException::class);
        $this->decode($two);
    }

    public function test_schema_constrained_legacy_response_uses_one_request_and_requires_fields(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('{"risks":[]}'))]);
        $client = app(AnthropicClient::class);
        (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'risks');
        $document = $this->document();
        $result = (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
            $client, 'Return JSON', $document, 'risks',
            fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'parseRisksResponse'))->invoke($client, $response));
        $this->assertSame([], $result['risks']);
        Http::assertSentCount(1);
        $schema = Http::recorded()->first()[0]['output_config']['format'];
        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame(['risks'], $schema['schema']['required']);
        $this->assertContains('evidence', $schema['schema']['properties']['risks']['items']['required']);
        $this->assertFalse($schema['schema']['properties']['risks']['items']['additionalProperties']);

        foreach (['document_type', 'entities', 'deadlines'] as $purpose) {
            $schema = \App\Services\AI\LegacyIntelligenceSchema::for($purpose);
            $this->assertContains($purpose === 'document_type' ? 'document_type' : $purpose, $schema['required']);
            $this->assertFalse($schema['additionalProperties']);
        }
    }

    public function test_schema_constrained_malformed_response_has_no_prompt_correction(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->response("{\"risks\":\"bad\nvalue\"}"))]);
        $client = app(AnthropicClient::class);
        (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'risks');
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', $this->document(), 'risks',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response));
            $this->fail('Malformed constrained output accepted.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('json_schema', $e->diagnostics['structured_output_mode']);
            $this->assertSame('raw_output', $e->diagnostics['parse_phase']);
        }
        Http::assertSentCount(1);
    }

    public function test_missing_required_risk_field_remains_validation_failure(): void
    {
        $this->expectException(\RuntimeException::class);
        (new \ReflectionMethod(AnthropicClient::class, 'parseRisksResponse'))
            ->invoke(app(AnthropicClient::class), $this->response('{"risks":[{"title":"Item"}]}'));
    }

    public function test_insight_schemas_follow_prompt_versions(): void
    {
        $legacy = \App\Services\AI\LegacyIntelligenceSchema::for('insights', 6);
        $this->assertSame(['kpis', 'charts', 'insights'], $legacy['required']);
        $this->assertArrayNotHasKey('identity', $legacy['properties']['kpis']['items']['properties']);

        $v9 = \App\Services\AI\LegacyIntelligenceSchema::for('insights', 9);
        $this->assertArrayHasKey('identity', $v9['properties']['kpis']['items']['properties']);
        $this->assertArrayHasKey('source_context', $v9['properties']['kpis']['items']['properties']);
        $this->assertArrayHasKey('charts', $v9['properties']);

        $v10 = \App\Services\AI\LegacyIntelligenceSchema::for('insights', 10);
        $this->assertSame(['kpis', 'insights'], $v10['required']);
        $this->assertArrayNotHasKey('charts', $v10['properties']);
        $this->assertSame(['type' => 'null'], $v10['properties']['kpis']['items']['properties']['trend']);
    }

    public function test_unsupported_provider_schema_is_terminal_and_audited(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error',
                'message' => 'output_config.format.schema: keyword maxItems is not supported'],
        ], 400, ['request-id' => 'req_synthetic_schema'])]);
        $client = $this->entitiesClient();
        $document = $this->document();
        try {
            (new \ReflectionMethod(AnthropicClient::class, 'structuredCall'))->invoke(
                $client, 'Return JSON', $document, 'entities',
                fn (array $response) => (new \ReflectionMethod(AnthropicClient::class, 'decodeJsonContent'))->invoke($client, $response));
            $this->fail('Unsupported schema was accepted.');
        } catch (AiProcessingException $e) {
            $this->assertSame('unsupported_schema', $e->classification);
        }
        Http::assertSentCount(1);
        $run = DocumentAiRun::where('document_id', $document->id)->sole();
        $this->assertSame('unsupported_schema', $run->failure_class);
        $this->assertSame('req_synthetic_schema', $run->provider_request_id);
    }
}
