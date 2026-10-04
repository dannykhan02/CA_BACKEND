<?php

namespace Tests\Unit;

use App\Jobs\ExtractDocumentEntitiesJob;
use App\Models\AiPrompt;
use App\Services\AI\PromptManager;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntityTimeoutBudgetTest extends TestCase
{
    private function prompt(): void
    {
        $prompt = new AiPrompt(['name' => 'document_entities', 'version' => 1,
            'template' => '<document>{{document_text}}</document>']);
        $manager = \Mockery::mock(PromptManager::class);
        $manager->shouldReceive('resolve')->once()->with('document_entities')->andReturn($prompt);
        $manager->shouldReceive('render')->once()->andReturnUsing(
            fn ($model, $vars) => strtr($model->template, $vars)
        );
        $this->app->instance(PromptManager::class, $manager);
    }

    private function response(string $text, string $stop): array
    {
        return ['model' => 'claude-test', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function test_entity_http_job_horizon_and_redis_timeouts_are_ordered(): void
    {
        $http = config('services.anthropic.entity_timeout');
        $job = new ExtractDocumentEntitiesJob('document-id');
        $supervisor = config('horizon.environments.production.supervisor-extraction.timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertSame(120, $http);
        $this->assertSame(10, config('services.anthropic.entity_connect_timeout'));
        $this->assertGreaterThan(2 * $http + 40, $job->timeout);
        $this->assertGreaterThan($job->timeout, $supervisor);
        $this->assertGreaterThan($supervisor, $retryAfter);
        $this->assertSame(390, $retryAfter);
    }

    public function test_entity_max_tokens_correction_remains_bounded_to_one_retry(): void
    {
        $this->prompt();
        config(['services.anthropic.max_tokens' => 4096,
            'services.anthropic.structured_max_tokens_ceiling' => 8192]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('{"entities":', 'max_tokens'))
            ->push($this->response('{"entities":[]}', 'end_turn'))]);

        $result = app(AnthropicClient::class)->extractDocumentEntities('Acme Ltd', 'Report.pdf');

        $this->assertSame([], $result['entities']);
        $this->assertSame([4096, 8192], Http::recorded()
            ->map(fn ($entry) => $entry[0]['max_tokens'])->all());
        Http::assertSentCount(2);
    }

    public function test_entity_provider_error_does_not_start_unbounded_transport_retries(): void
    {
        $this->prompt();
        Http::fake(['api.anthropic.com/*' => Http::response([], 503)]);

        try {
            app(AnthropicClient::class)->extractDocumentEntities('Acme Ltd', 'Report.pdf');
            $this->fail('A provider error must escape the entity stage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status 503', $e->getMessage());
        }
        Http::assertSentCount(1);
    }
}
