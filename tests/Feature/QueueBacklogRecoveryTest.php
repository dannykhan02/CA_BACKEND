<?php

namespace Tests\Feature;

use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Jobs\ProcessDocumentVisualJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\VisualPlanner;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use App\Support\QueueInspector;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Recovery distinguishes a lost dispatch from a merely backed-up queue. */
class QueueBacklogRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5', 'document_intelligence.budget_base_usd' => 2]);
    }

    /** What recovery would see in Redis: null = cannot inspect. */
    private function inspector(?array $tokens): void
    {
        $this->app->instance(QueueInspector::class, new class($tokens) extends QueueInspector
        {
            public function __construct(private ?array $fake) {}

            public function pendingDispatchTokens(): ?array
            {
                return $this->fake;
            }
        });
    }

    private function queuedChunk(): DocumentChunk
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'backlog'.$user->id),
            'extracted_text' => 'Revenue increased to USD 10 in 2024.']);
        $document->forceFill(['ai_pipeline' => ['tokens' => 30, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
    }

    private function age(DocumentChunk $unit, int $minutes): void
    {
        DocumentChunk::whereKey($unit->id)->update(['dispatched_at' => now()->subMinutes($minutes), 'updated_at' => now()->subMinutes($minutes)]);
    }

    public function test_a_backlog_older_than_ten_minutes_is_left_alone_without_duplicate_messages(): void
    {
        $chunk = $this->queuedChunk();
        $token = $chunk->dispatch_token;
        $this->age($chunk, 45);
        $this->inspector([$token => true]); // Message still waiting in Redis.
        app(IncrementalPipeline::class)->recover($chunk->document);
        self::assertSame(['queued', $token], [$chunk->fresh()->status, $chunk->fresh()->dispatch_token]);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 1); // Only the original dispatch.
    }

    public function test_a_genuinely_lost_dispatch_is_recovered_once_and_the_old_message_becomes_harmless(): void
    {
        $chunk = $this->queuedChunk();
        $old = $chunk->dispatch_token;
        $this->age($chunk, 45);
        $this->inspector([]); // Redis no longer has any message for it.
        app(IncrementalPipeline::class)->recover($chunk->document);
        $chunk->refresh();
        self::assertSame('queued', $chunk->status);
        self::assertNotSame($old, $chunk->dispatch_token);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 2);
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->dispatchToken === $chunk->dispatch_token && $job->queue === 'extraction');
        // A second recovery pass right away finds the fresh dispatch young: no more messages.
        app(IncrementalPipeline::class)->recover($chunk->document);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 2);

        // If the "lost" message does surface later, it is dropped before any provider call.
        Http::fake();
        (new ProcessDocumentChunkJob($chunk->id, $old))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        Http::assertNothingSent();
        self::assertSame(['queued', 0], [$chunk->fresh()->status, $chunk->fresh()->attempts]);
    }

    public function test_uninspectable_queue_falls_back_to_age_but_stays_idempotent(): void
    {
        $chunk = $this->queuedChunk();
        $old = $chunk->dispatch_token;
        $this->age($chunk, 45);
        $this->inspector(null);
        app(IncrementalPipeline::class)->recover($chunk->document);
        self::assertNotSame($old, $chunk->fresh()->dispatch_token);
        Http::fake();
        (new ProcessDocumentChunkJob($chunk->id, $old))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        Http::assertNothingSent();
    }

    public function test_pre_token_messages_from_before_deploy_still_recover_and_run(): void
    {
        $chunk = $this->queuedChunk();
        DocumentChunk::whereKey($chunk->id)->update(['dispatch_token' => null, 'dispatched_at' => null, 'updated_at' => now()->subMinutes(45)]);
        $this->inspector([]);
        app(IncrementalPipeline::class)->recover($chunk->document);
        self::assertNotNull($chunk->fresh()->dispatch_token);
        // A pre-token message (null token) is still accepted; the DB claim keeps it single-run.
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]), '*/messages' => Http::response(['model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'content' => [['type' => 'text', 'text' => '{"records": []}']]])]);
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        (new ProcessDocumentChunkJob($chunk->id, $chunk->fresh()->dispatch_token))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('completed', $chunk->fresh()->status);
        self::assertSame(1, DocumentAiRun::count());
    }

    public function test_running_paid_calls_are_marked_uncertain_never_replayed(): void
    {
        $chunk = $this->queuedChunk();
        DocumentChunk::whereKey($chunk->id)->update(['status' => 'running', 'started_at' => now()->subMinutes(8)]);
        $this->inspector([]);
        app(IncrementalPipeline::class)->recover($chunk->document);
        self::assertSame('uncertain', $chunk->fresh()->status);
        Http::fake();
        (new ProcessDocumentChunkJob($chunk->id, $chunk->dispatch_token))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        Http::assertNothingSent();
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 1);
    }

    public function test_duplicate_delivery_of_the_same_dispatch_runs_once(): void
    {
        $chunk = $this->queuedChunk();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]), '*/messages' => Http::response(['model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'content' => [['type' => 'text', 'text' => '{"records": []}']]])]);
        $job = fn () => (new ProcessDocumentChunkJob($chunk->id, $chunk->dispatch_token))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        $job();
        $job();
        Http::assertSentCount(2); // One free count, one generation.
        self::assertSame(1, DocumentAiRun::count());
    }

    public function test_backed_up_merge_and_visual_units_are_not_redispatched(): void
    {
        $chunk = $this->queuedChunk();
        $document = $chunk->document;
        $chunk->update(['status' => 'completed', 'result' => ['records' => []]]);
        app(IncrementalPipeline::class)->pump($document->id);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        Bus::assertDispatched(MergeDocumentEvidenceJob::class, fn ($job) => $job->queue === 'synthesis' && $job->dispatchToken === $merge->dispatch_token);
        $this->age($merge, 30);
        $this->inspector([$merge->dispatch_token => true]);
        app(IncrementalPipeline::class)->recover($document->fresh());
        Bus::assertDispatchedTimes(MergeDocumentEvidenceJob::class, 1);
        // Superseded merge delivery is a no-op.
        (new MergeDocumentEvidenceJob($merge->id, 'superseded-token'))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame('queued', $merge->fresh()->status);

        $document->update(['status' => 'Ready']);
        $visual = DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'], 'identity' => 'visual:1', 'stage' => 'visual', 'input_hash' => 'h',
            'pipeline_version' => '1', 'prompt_version' => '1', 'status' => 'queued', 'dispatch_token' => (string) Str::uuid(),
            'dispatched_at' => now()->subMinutes(30), 'result' => ['path' => 'p']]);
        $this->inspector([$visual->dispatch_token => true]);
        app(VisualPlanner::class)->recover($document->fresh());
        self::assertSame('queued', $visual->fresh()->status);
        $oldToken = $visual->dispatch_token;
        $this->inspector([]);
        app(VisualPlanner::class)->recover($document->fresh());
        self::assertNotSame($oldToken, $visual->fresh()->dispatch_token);
        Bus::assertDispatched(ProcessDocumentVisualJob::class, fn ($job) => $job->queue === 'extraction');
    }

    public function test_reanalysis_redispatches_across_queues_with_fresh_tokens(): void
    {
        $chunk = $this->queuedChunk();
        $document = $chunk->document;
        $chunk->update(['status' => 'failed', 'failure_class' => 'timeout']);
        $document->update(['status' => 'Needs Review']);
        app(IncrementalPipeline::class)->reanalyze($document->fresh(), $document->uploaded_by);
        $chunk->refresh();
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->queue === 'extraction' && $job->dispatchToken === $chunk->dispatch_token);
    }

    public function test_redis_inspector_finds_tokens_in_ready_delayed_and_reserved_messages(): void
    {
        config(['database.redis.gate-test' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'password' => null],
            'queue.connections.redis.connection' => 'gate-test', 'queue.default' => 'redis']);
        try {
            Redis::connection('gate-test')->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not reachable on 127.0.0.1:6379: '.$e->getMessage());
        }
        $redis = Redis::connection('gate-test');
        foreach (['extraction', 'synthesis', 'default'] as $queue) {
            $redis->del('queues:'.$queue, 'queues:'.$queue.':delayed', 'queues:'.$queue.':reserved', 'queues:'.$queue.':notify');
        }
        $ready = (string) Str::uuid();
        $delayed = (string) Str::uuid();
        $merge = (string) Str::uuid();
        Queue::connection('redis')->pushOn('extraction', new ProcessDocumentChunkJob('chunk-1', $ready));
        Queue::connection('redis')->laterOn('extraction', 600, new ProcessDocumentChunkJob('chunk-2', $delayed));
        Queue::connection('redis')->pushOn('synthesis', new MergeDocumentEvidenceJob('merge-1', $merge));
        $inspector = new QueueInspector;
        $tokens = $inspector->pendingDispatchTokens();
        self::assertEqualsCanonicalizing([$ready, $delayed, $merge], array_keys($tokens));
        $depths = $inspector->depths();
        self::assertSame(['ready' => 1, 'delayed' => 1, 'reserved' => 0], array_intersect_key($depths['extraction'], array_flip(['ready', 'delayed', 'reserved'])));
        self::assertSame(1, $depths['synthesis']['ready']);
        self::assertNotNull($depths['extraction']['oldest_ready_age_seconds']);
        foreach (['extraction', 'synthesis', 'default'] as $queue) {
            $redis->del('queues:'.$queue, 'queues:'.$queue.':delayed', 'queues:'.$queue.':reserved', 'queues:'.$queue.':notify');
        }
    }
}
