<?php

namespace Tests\Feature;

use App\DataObjects\DocumentContext;
use App\Exceptions\ProviderBusyException;
use App\Jobs\CompareDocumentsJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\OcrPageBatchJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Services\AI\DocumentContextRetriever;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\ProviderGate;
use App\Services\AI\ProviderGate\MemoryGateStore;
use App\Services\AnthropicClient;
use App\Services\DocumentComparisonService;
use App\Services\EntitlementService;
use App\Services\Ocr\ClaudeVisionOcrProvider;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\OcrProviderInterface;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentQaPromptSeederV2;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Global Anthropic admission (ANTHROPIC_MAX_INFLIGHT) and per-document fairness. */
class ProviderGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5', 'document_intelligence.budget_base_usd' => 2,
            'document_intelligence.provider_gate.job_wait_seconds' => 0, 'document_intelligence.provider_gate.web_wait_seconds' => 0]);
    }

    private function gate(): ProviderGate
    {
        return app(ProviderGate::class);
    }

    /** Occupy permits as other processes would (memory store is shared like Redis). */
    private function occupy(int $count, string $document = ''): void
    {
        $store = new MemoryGateStore;
        for ($i = 0; $i < $count; $i++) {
            [$granted] = $store->acquire('other-'.$i.'-'.uniqid(), $document, 240000, $this->gate()->max(), 2, false, 60000);
            self::assertTrue($granted);
        }
    }

    private function document(string $text = 'Revenue increased to USD 10 in 2024.'): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'gate'.$user->id.uniqid()), 'extracted_text' => $text]);
    }

    private function queuedChunk(?Document $document = null): DocumentChunk
    {
        $document ??= $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 30, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->firstOrFail();
    }

    private function runChunk(DocumentChunk $chunk): void
    {
        (new ProcessDocumentChunkJob($chunk->id, $chunk->fresh()->dispatch_token))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
    }

    private function response(array $data): array
    {
        return ['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20], 'content' => [['type' => 'text', 'text' => json_encode($data)]]];
    }

    // Semaphore semantics

    public function test_global_cap_is_never_exceeded_and_permits_are_released_in_finally(): void
    {
        config(['document_intelligence.provider_gate.max_inflight' => 2]);
        $gate = $this->gate();
        $other = new ProviderGate; // A second app instance sharing the store.
        $gate->hold('doc-a', function () use ($other) {
            $other->hold('doc-b', function () {
                self::assertSame(2, $this->gate()->snapshot()['active']);
                try {
                    (new ProviderGate)->hold('doc-c', fn () => self::fail('Third permit granted'));
                } catch (ProviderBusyException $e) {
                    self::assertSame('global', $e->reason);
                }
            });
        });
        self::assertSame(0, $gate->snapshot()['active']);
        try {
            $gate->hold('doc-a', fn () => throw new \RuntimeException('provider exploded'));
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $gate->snapshot()['active']);
        self::assertSame(1, $gate->snapshot()['counters']['denied_global']);
    }

    public function test_a_held_permit_is_reused_by_nested_calls_not_double_counted(): void
    {
        config(['document_intelligence.provider_gate.max_inflight' => 1]);
        $result = $this->gate()->hold('doc', fn () => $this->gate()->call(fn () => $this->gate()->call(fn () => 'nested', 'doc')));
        self::assertSame('nested', $result);
        self::assertSame(1, $this->gate()->snapshot()['counters']['acquired']);
    }

    public function test_lease_expires_after_a_simulated_crash(): void
    {
        MemoryGateStore::$clock = 1000.0;
        $this->occupy(2);
        $this->expectProviderBusy(fn () => $this->gate()->hold('doc', fn () => null));
        // The holders died without releasing; their leases expire after lease_seconds.
        MemoryGateStore::$clock = 1000.0 + config('document_intelligence.provider_gate.lease_seconds') + 1;
        self::assertSame('ok', $this->gate()->hold('doc', fn () => 'ok'));
        self::assertSame(2, $this->gate()->snapshot()['counters']['lease_expired']);
    }

    private function expectProviderBusy(callable $callback): ProviderBusyException
    {
        try {
            $callback();
        } catch (ProviderBusyException $e) {
            return $e;
        }
        self::fail('Expected ProviderBusyException');
    }

    public function test_one_document_cannot_take_every_permit_while_another_waits(): void
    {
        config(['document_intelligence.provider_gate.max_inflight' => 2, 'document_intelligence.concurrency' => 2]);
        $store = new MemoryGateStore;
        [$a1] = $store->acquire('a1', 'doc-a', 240000, 2, 2, false, 60000);
        self::assertTrue($a1);
        // Uncontended, document A may use its full per-document allowance...
        [$a2, , , $member] = $store->acquire('a2', 'doc-a', 240000, 2, 2, false, 60000);
        self::assertTrue($a2);
        $store->release($member);
        // ...but once B is waiting (or B has queued work), the last permit is kept for B.
        [$denied] = $store->acquire('b-wait', 'doc-b', 240000, 1, 2, false, 60000); // B denied while full: registers waiting.
        self::assertFalse($denied);
        [$a2again, $reason] = $store->acquire('a2', 'doc-a', 240000, 2, 2, false, 60000);
        self::assertSame([false, 'fairness'], [$a2again, $reason]);
        [$b] = $store->acquire('b1', 'doc-b', 240000, 2, 2, false, 60000);
        self::assertTrue($b);
        // Per-document limit holds regardless of free global capacity.
        [, $limit] = $store->acquire('a3', 'doc-a', 240000, 8, 1, false, 60000);
        self::assertSame('document', $limit);
        // reserveForOthers: A (already holding one) cannot take the last of 3 when others have queued work.
        MemoryGateStore::reset();
        $store->acquire('a1', 'doc-a', 240000, 3, 2, false, 60000);
        $store->acquire('c1', 'doc-c', 240000, 3, 2, false, 60000);
        [$granted, $why] = $store->acquire('a2', 'doc-a', 240000, 3, 2, true, 60000);
        self::assertSame([false, 'fairness'], [$granted, $why]);
    }

    public function test_per_document_and_global_limits_combine_for_two_documents(): void
    {
        // DOCINTEL_EXTRACTION_CONCURRENCY=2, ANTHROPIC_MAX_INFLIGHT=4: both documents progress,
        // neither can hold all four permits.
        config(['document_intelligence.provider_gate.max_inflight' => 4, 'document_intelligence.concurrency' => 2]);
        $store = new MemoryGateStore;
        $grants = [];
        foreach (['a1' => 'doc-a', 'a2' => 'doc-a', 'a3' => 'doc-a', 'b1' => 'doc-b', 'b2' => 'doc-b', 'b3' => 'doc-b'] as $token => $doc) {
            $grants[$token] = $store->acquire($token, $doc, 240000, 4, 2, false, 60000)[0];
        }
        self::assertSame(['a1' => true, 'a2' => true, 'a3' => false, 'b1' => true, 'b2' => true, 'b3' => false], $grants);
        self::assertSame(4, $store->stats()['active']);

        // In the pipeline: each document dispatches at most its window of chunks, both progress.
        $text = str_repeat("Revenue increased to USD 10 in 2024.\n\n", 40);
        config(['document_intelligence.chunk_max_tokens' => 100]);
        $first = $this->document($text);
        $second = $this->document($text);
        foreach ([$first, $second] as $document) {
            $document->forceFill(['ai_pipeline' => ['tokens' => 500, 'route' => 'incremental']])->save();
            app(IncrementalPipeline::class)->start($document);
            app(IncrementalPipeline::class)->pump($document->id);
        }
        foreach ([$first, $second] as $document) {
            self::assertSame(2, DocumentChunk::where('document_id', $document->id)->where('status', 'queued')->count());
            self::assertGreaterThan(2, DocumentChunk::where('document_id', $document->id)->count());
        }
    }

    // Jobs defer without consuming attempts or touching durable state

    public function test_busy_chunk_job_defers_without_claim_cost_attempt_or_provider_call(): void
    {
        $chunk = $this->queuedChunk();
        $this->occupy(2);
        Http::fake();
        $this->runChunk($chunk);
        $chunk->refresh();
        self::assertSame(['queued', 0, 0.0], [$chunk->status, $chunk->attempts, (float) $chunk->reserved_cost]);
        self::assertSame(0, DocumentAiRun::count());
        Http::assertNothingSent(); // Not even the free token count.
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->delay !== null && $job->dispatchToken === $chunk->dispatch_token
            && $job->queue === 'extraction' && $job->job === null);
    }

    public function test_admitted_chunk_job_holds_one_permit_and_releases_it_on_timeout(): void
    {
        $chunk = $this->queuedChunk();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]),
            '*/messages' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $this->runChunk($chunk);
        self::assertSame(0, $this->gate()->snapshot()['active']);
        self::assertSame(1, $this->gate()->snapshot()['counters']['acquired']); // Count + extraction share one permit.
        self::assertSame('timeout', DocumentAiRun::sole()->failure_class);
    }

    public function test_busy_summary_job_defers_before_any_checkpoint_or_stage(): void
    {
        $document = $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 30, 'route' => 'incremental', 'key' => 'k']])->save();
        $this->occupy(2);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        self::assertSame(0, DocumentChunk::where('stage', 'synthesis')->count());
        self::assertSame(0, $document->processingJobs()->count());
        Bus::assertDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue === null || $job->queue === 'synthesis');
    }

    public function test_provider_retry_reacquires_a_permit_per_http_attempt(): void
    {
        config(['document_intelligence.provider_gate.job_wait_seconds' => 1]);
        Http::fake(['*/messages' => Http::sequence()->push([], 503)->push($this->response(['ok' => true]))]);
        $client = app(AnthropicClient::class);
        $call = (new \ReflectionClass($client))->getMethod('callWithRetry');
        $call->invoke($client, [['role' => 'user', 'content' => 'x']], 1, ['max_attempts' => 2, 'timeout' => 5]);
        self::assertSame(2, $this->gate()->snapshot()['counters']['acquired']);
        self::assertSame(0, $this->gate()->snapshot()['active']);
    }

    // Other Anthropic call paths share the same cap

    public function test_ocr_vision_calls_are_gated_and_a_busy_batch_defers_instead_of_failing(): void
    {
        $this->occupy(2);
        Http::fake();
        Storage::fake('documents');
        $document = $this->document();
        try {
            app(ClaudeVisionOcrProvider::class)->extractPage(tempnam(sys_get_temp_dir(), 'ocr'), $document);
            self::fail('OCR bypassed the provider gate.');
        } catch (ProviderBusyException) {
        }
        Http::assertNothingSent();

        Storage::disk('documents')->put('ai-ocr/page.png', 'image');
        $provider = \Mockery::mock(OcrProviderInterface::class);
        $provider->shouldReceive('extractPage')->andThrow(new ProviderBusyException('global', 15));
        $resolver = $this->mock(OcrEngineResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($provider);
        (new OcrPageBatchJob($document->id, ['ai-ocr/page.png'], 1, true, storedPageImages: true))
            ->handle($resolver, app(PipelineStageRecorder::class));
        self::assertSame('Processing', $document->fresh()->status);
        Bus::assertDispatched(OcrPageBatchJob::class, fn ($job) => $job->delay !== null);
    }

    public function test_comparison_waits_for_a_permit_before_reserving_or_calling(): void
    {
        $a = $this->document();
        $b = Document::create([...$a->only(['workspace_id', 'uploaded_by', 'type', 'classification', 'size_kb', 'year', 'pages', 'extracted_text']),
            'name' => 'Other.pdf', 'status' => 'Ready', 'file_hash' => hash('sha256', 'other')]);
        $comparison = DocumentComparison::create(['workspace_id' => $a->workspace_id, 'created_by' => $a->uploaded_by,
            'base_document_id' => $a->id, 'compared_document_id' => $b->id, 'fingerprint' => hash('sha256', 'gate'),
            'status' => 'queued', 'metadata' => ['base' => [], 'compared' => [], 'ai_context' => ['base' => [], 'compared' => []]]]);
        $this->occupy(2);
        Http::fake();
        $ledger = DB::table('credit_ledger')->count();
        (new CompareDocumentsJob($comparison->id))->handle(app(DocumentComparisonService::class));
        self::assertSame('queued', $comparison->fresh()->status);
        self::assertSame($ledger, DB::table('credit_ledger')->count());
        Http::assertNothingSent();
        Bus::assertDispatched(CompareDocumentsJob::class, fn ($job) => $job->delay !== null);
    }

    public function test_synchronous_qa_shares_the_cap_and_answers_busy_gracefully(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $context = new DocumentContext(
            [['id' => 'doc', 'name' => 'Budget.pdf', 'document_type' => null, 'classification' => 'Internal']], [], [], [],
            [['document_id' => 'doc', 'text' => 'Total budget for Q3 was 64.2 million.', 'score' => 1.0]]);
        $this->mock(DocumentContextRetriever::class, fn ($mock) => $mock->shouldReceive('retrieve')->andReturn($context));
        $this->mock(EntitlementService::class, fn ($mock) => $mock->shouldReceive('assertAiAccess'));
        $this->seed(DocumentQaPromptSeederV2::class);
        $this->occupy(2);
        Http::fake();
        Sanctum::actingAs($user->fresh());
        $response = $this->postJson('/api/documents/query', ['question' => 'What was the Q3 budget?']);
        $response->assertStatus(503)->assertHeader('Retry-After', '15')
            ->assertJsonPath('message', 'AI is busy right now. Please try again in a moment.');
        self::assertStringNotContainsString('global', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_token_counting_never_waits_and_falls_back_conservatively(): void
    {
        config(['document_intelligence.provider_gate.job_wait_seconds' => 30]);
        $this->occupy(2);
        Http::fake();
        $started = microtime(true);
        try {
            app(AnthropicClient::class)->countTokens('text');
            self::fail('Expected busy');
        } catch (ProviderBusyException) {
        }
        self::assertLessThan(5, microtime(true) - $started);
        Http::assertNothingSent();
    }

    // Retry-After

    public function test_retry_after_accepts_seconds_and_http_dates(): void
    {
        $client = app(AnthropicClient::class);
        $parse = fn (?string $value) => (new \ReflectionClass($client))->getMethod('retryAfterSeconds')
            ->invoke($client, new Response(new \GuzzleHttp\Psr7\Response(429, $value === null ? [] : ['Retry-After' => $value])));
        self::assertSame(7, $parse('7'));
        self::assertEqualsWithDelta(30, $parse(gmdate('D, d M Y H:i:s', time() + 30).' GMT'), 2);
        self::assertSame(0, $parse(gmdate('D, d M Y H:i:s', time() - 60).' GMT'));
        self::assertSame(0, $parse('soon'));
        self::assertSame(0, $parse(null));

        $chunk = $this->queuedChunk();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]),
            '*/messages' => Http::response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 90).' GMT'])]);
        $this->runChunk($chunk);
        // The date is honoured (not truncated to zero): the redispatch waits at least ~90s.
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->delay !== null
            && (is_int($job->delay) ? $job->delay : now()->diffInSeconds($job->delay)) >= 85);
    }

    // Real Redis: atomicity across processes

    private function useRedis(string $prefix): void
    {
        config(['database.redis.gate-test' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'password' => null],
            'document_intelligence.provider_gate.driver' => 'redis', 'document_intelligence.provider_gate.connection' => 'gate-test',
            'document_intelligence.provider_gate.prefix' => $prefix]);
        try {
            Redis::connection('gate-test')->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not reachable on 127.0.0.1:6379: '.$e->getMessage());
        }
        foreach (['leases', 'waiting', 'counters', 'peak', 'inside', 'go'] as $key) {
            Redis::connection('gate-test')->del($prefix.':'.$key);
        }
    }

    public function test_redis_semaphore_is_atomic_across_parallel_processes(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the multi-process atomicity test.');
        }
        $prefix = 'gate-test-'.uniqid();
        $this->useRedis($prefix);
        config(['document_intelligence.provider_gate.max_inflight' => 3]);
        $children = [];
        for ($i = 0; $i < 12; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    Redis::purge('gate-test'); // Separate connection per process, like separate replicas.
                    $gate = new ProviderGate;
                    while (! Redis::connection('gate-test')->get($prefix.':go')) {
                        usleep(1000); // Start barrier: every process contends at once.
                    }
                    for ($n = 0; $n < 15; $n++) {
                        try {
                            $gate->hold('doc-'.$i, function () use ($prefix) {
                                $inside = Redis::connection('gate-test')->eval(
                                    "local v = redis.call('INCR', KEYS[1]) local p = tonumber(redis.call('GET', KEYS[2]) or '0') if v > p then redis.call('SET', KEYS[2], v) end return v",
                                    2, $prefix.':inside', $prefix.':peak');
                                usleep(random_int(15000, 30000));
                                Redis::connection('gate-test')->decr($prefix.':inside');
                            });
                        } catch (ProviderBusyException) {
                            usleep(random_int(500, 2000));
                        }
                    }
                } finally {
                    // Never let a child continue into PHPUnit; skip shutdown handlers that would close shared sockets.
                    posix_kill(getmypid(), SIGKILL);
                }
            }
            $children[] = $pid;
        }
        usleep(300000);
        Redis::connection('gate-test')->set($prefix.':go', 1);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $redis = Redis::connection('gate-test');
        self::assertLessThanOrEqual(3, (int) $redis->get($prefix.':peak'));
        self::assertSame(3, (int) $redis->get($prefix.':peak')); // Saturated: real contention happened.
        self::assertSame(0, (int) $redis->zcard($prefix.':leases'));
        self::assertGreaterThan(0, (int) $redis->hget($prefix.':counters', 'denied_global'));
    }

    public function test_redis_lease_expires_when_a_holder_crashes(): void
    {
        $prefix = 'gate-test-'.uniqid();
        $this->useRedis($prefix);
        config(['document_intelligence.provider_gate.max_inflight' => 1, 'document_intelligence.provider_gate.lease_seconds' => 1]);
        $store = $this->gate()->store();
        [$granted] = $store->acquire('crashed', 'doc', 1000, 1, 2, false, 60000); // Never released.
        self::assertTrue($granted);
        $this->expectProviderBusy(fn () => $this->gate()->hold('doc-2', fn () => null));
        usleep(1_200_000);
        self::assertSame('ok', $this->gate()->hold('doc-2', fn () => 'ok'));
        self::assertSame(1, $this->gate()->snapshot()['counters']['lease_expired']);
        self::assertSame(0, $this->gate()->snapshot()['active']);
    }

    public function test_queue_status_reports_permits_and_counters_as_metadata_only(): void
    {
        $this->occupy(1, 'doc-a');
        try {
            (new ProviderGate)->hold('doc-a', fn () => null); // Second permit for the same doc still fits (max 2, per-doc 2).
            $this->occupy(1);
            (new ProviderGate)->hold('doc-b', fn () => null);
        } catch (ProviderBusyException) {
        }
        Artisan::call('docintel:queue-status', ['--json' => true]);
        $status = json_decode(Artisan::output(), true);
        self::assertSame([], $status['queues']); // Tests use the sync queue: not inspectable.
        self::assertSame(2, $status['provider']['max_inflight']);
        self::assertSame(2, $status['provider']['active']);
        self::assertGreaterThan(0, $status['provider']['counters']['denied_global']);
        self::assertSame(240, $status['provider']['lease_seconds']);
    }

    public function test_extraction_saturation_cannot_starve_synthesis_of_permits(): void
    {
        // Both permits held by bulk extraction; a summary arrives and is deferred...
        $store = new MemoryGateStore;
        [, , , $first] = $store->acquire('x1', 'doc-a', 240000, 2, 2, false, 60000);
        $store->acquire('x2', 'doc-b', 240000, 2, 2, false, 60000);
        $document = $this->document();
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Bus::assertDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue === 'synthesis' && $job->delay !== null);
        self::assertSame(1, $this->gate()->snapshot()['priority_waiters']);
        // ...a permit frees up: the next bulk extraction call may not take it...
        $store->release($first);
        [$bulk, $reason] = $store->acquire('x3', 'doc-c', 240000, 2, 2, false, 60000);
        self::assertSame([false, 'priority'], [$bulk, $reason]);
        // ...the deferred summary (same waiter claim) gets it on re-delivery.
        $ran = $this->gate()->hold($document->id, fn () => 'synthesized', priority: true, waiter: 'synthesis:'.$document->id);
        self::assertSame('synthesized', $ran);
        self::assertSame(0, $this->gate()->snapshot()['priority_waiters']);
    }

    public function test_an_abandoned_web_request_does_not_keep_reserving_a_permit(): void
    {
        $this->occupy(2);
        $gate = $this->gate();
        $call = (new \ReflectionClass($gate))->getMethod('acquire');
        try {
            $call->invoke($gate, null, 0.0, false, true, null); // Interactive priority caller that gives up.
        } catch (ProviderBusyException) {
        }
        self::assertSame(0, $gate->snapshot()['priority_waiters']);
    }
}
