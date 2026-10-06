<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\CompareDocumentsJob;
use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Jobs\ProcessDocumentVisualJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\ProviderGate;
use App\Services\AnthropicClient;
use App\Services\Documents\DocumentReprocessor;
use App\Services\WorkspaceService;
use App\Support\QueueTopology;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Three queues (extraction, synthesis, default): routing on every dispatch path, and pool config. */
class QueueTopologyTest extends TestCase
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

    private function document(string $text = 'Revenue increased to USD 10 in 2024.', string $status = 'Processing'): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => $status, 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'topology'.$user->id), 'extracted_text' => $text]);
    }

    private function started(?Document $document = null): Document
    {
        $document ??= $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 30, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return $document->fresh();
    }

    private function record(): array
    {
        return ['kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company',
            'quote' => 'Revenue increased to USD 10 in 2024.', 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => []];
    }

    private function chainedQueues($job): array
    {
        return array_map(fn ($payload) => [get_class($next = unserialize($payload)), $next->queue], $job->chained);
    }

    public function test_every_routed_job_maps_to_one_of_three_queues(): void
    {
        self::assertSame(['extraction', 'synthesis', 'default'], QueueTopology::queues());
        foreach (QueueTopology::ROUTES as $job => $queue) {
            self::assertContains($queue, QueueTopology::queues(), $job);
        }
        self::assertSame('synthesis', QueueTopology::for(MergeDocumentEvidenceJob::class));
        self::assertSame('synthesis', QueueTopology::for(GenerateDocumentSummaryJob::class));
        self::assertSame('extraction', QueueTopology::for(ProcessDocumentChunkJob::class));
        self::assertSame('extraction', QueueTopology::for(GenerateInsightsJob::class)); // Calls Anthropic.
        foreach ([ScanUploadedFileJob::class, ExtractDocumentTextJob::class, GenerateEmbeddingsJob::class, AnalyzeEmbeddedVisualsJob::class] as $job) {
            self::assertSame('default', QueueTopology::for($job), $job);
        }
    }

    public function test_chunks_route_to_extraction_with_a_dispatch_token_that_survives_serialization(): void
    {
        $document = $this->started();
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        self::assertNotNull($chunk->dispatch_token);
        self::assertNotNull($chunk->dispatched_at);
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->queue === 'extraction' && $job->dispatchToken === $chunk->dispatch_token);
        $job = unserialize(serialize((new ProcessDocumentChunkJob($chunk->id, $chunk->dispatch_token))->onQueue(QueueTopology::for(ProcessDocumentChunkJob::class))));
        self::assertSame(['extraction', $chunk->dispatch_token], [$job->queue, $job->dispatchToken]);
    }

    public function test_merge_and_summary_route_to_synthesis(): void
    {
        $document = $this->started();
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        app(IncrementalPipeline::class)->pump($document->id);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        Bus::assertDispatched(MergeDocumentEvidenceJob::class, fn ($job) => $job->queue === 'synthesis' && $job->dispatchToken === $merge->dispatch_token);
        app(EvidenceMerger::class)->merge($document);
        $merge->update(['status' => 'completed']);
        app(IncrementalPipeline::class)->pump($document->id);
        Bus::assertDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue === 'synthesis');
        Bus::assertNotDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue !== 'synthesis');
    }

    public function test_delayed_retries_stay_on_their_queue(): void
    {
        $document = $this->started();
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]), '*/messages' => Http::response([], 429, ['Retry-After' => '5'])]);
        (new ProcessDocumentChunkJob($chunk->id, $chunk->dispatch_token))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('queued', $chunk->fresh()->status);
        Bus::assertDispatched(ProcessDocumentChunkJob::class, fn ($job) => $job->queue === 'extraction' && $job->delay !== null
            && $job->dispatchToken === $chunk->dispatch_token);
    }

    public function test_upload_rescan_chain_routes_each_link(): void
    {
        $document = $this->document(status: 'Failed');
        app(DocumentReprocessor::class)->reprocess($document->fresh(), User::findOrFail($document->uploaded_by));
        Bus::assertDispatched(ScanUploadedFileJob::class, function ($job) {
            return $job->queue === 'default' && $this->chainedQueues($job) === [
                [ExtractDocumentTextJob::class, 'default'], [GenerateInsightsJob::class, 'extraction'], [GenerateEmbeddingsJob::class, 'default']];
        });
    }

    public function test_legacy_batch_runs_on_extraction_and_its_finally_summary_lands_on_synthesis(): void
    {
        $document = $this->document('Short report.');
        $dispatcher = new class
        {
            use DispatchesIntelligenceChain;

            public function run(Document $document): void
            {
                $this->dispatchIntelligenceChain($document);
            }
        };
        $dispatcher->run($document);
        Bus::assertBatched(function (PendingBatch $batch) {
            if ($batch->queue() !== 'extraction') {
                return false;
            }
            foreach ($batch->finallyCallbacks() as $callback) {
                $callback();
            }

            return true;
        });
        Bus::assertDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue === 'synthesis');
    }

    /** Route resolution happens in the real dispatcher, so undo the Bus fake for queue-level tests. */
    private function realDispatcher(): void
    {
        Bus::swap(Bus::getFacadeRoot()->dispatcher);
        Queue::fake();
    }

    public function test_dispatch_without_explicit_queue_is_routed_by_the_topology_map(): void
    {
        $this->realDispatcher();
        dispatch(new GenerateDocumentSummaryJob('document-id'));
        dispatch(new MergeDocumentEvidenceJob('merge-id'));
        dispatch(new GenerateEmbeddingsJob('document-id'));
        dispatch(new ProcessDocumentChunkJob('chunk-id'));
        Queue::assertPushedOn('synthesis', GenerateDocumentSummaryJob::class);
        Queue::assertPushedOn('synthesis', MergeDocumentEvidenceJob::class);
        Queue::assertPushedOn('default', GenerateEmbeddingsJob::class);
        Queue::assertPushedOn('extraction', ProcessDocumentChunkJob::class);
    }

    public function test_recovery_dispatches_to_the_correct_queues(): void
    {
        $document = $this->started();
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        $document->update(['status' => 'Ready']);
        app(IncrementalPipeline::class)->recover($document->fresh());
        Bus::assertDispatched(MergeDocumentEvidenceJob::class, fn ($job) => $job->queue === 'synthesis');
        Bus::assertDispatched(GenerateDocumentSummaryJob::class, fn ($job) => $job->queue === 'synthesis');
        Bus::assertDispatched(AnalyzeEmbeddedVisualsJob::class, fn ($job) => $job->queue === 'default');
        Bus::assertDispatched(GenerateEmbeddingsJob::class, fn ($job) => $job->queue === 'default');
    }

    public function test_extraction_backlog_cannot_delay_synthesis_or_default_work(): void
    {
        $this->realDispatcher();
        for ($i = 0; $i < 20; $i++) {
            dispatch(new ProcessDocumentChunkJob('chunk-'.$i));
        }
        dispatch(new MergeDocumentEvidenceJob('merge'));
        dispatch(new GenerateDocumentSummaryJob('document'));
        dispatch(new ScanUploadedFileJob('document'));
        self::assertSame(20, Queue::size('extraction'));
        // Completion and default work sit on their own queues, each with its own supervisor.
        self::assertSame(2, Queue::size('synthesis'));
        self::assertSame(1, Queue::size('default'));
        $pools = collect(array_replace_recursive(config('horizon.defaults'), config('horizon.environments.production')));
        self::assertSame(['default', 'extraction', 'synthesis'], $pools->pluck('queue')->flatten()->sort()->values()->all());
        self::assertSame(1, $pools->filter(fn ($pool) => $pool['queue'] === ['synthesis'])->count());
        // A long merge cannot occupy every synthesis slot.
        self::assertGreaterThanOrEqual(2, $pools['supervisor-synthesis']['maxProcesses']);
    }

    public function test_every_pool_outlives_its_longest_job_and_stays_below_redis_visibility(): void
    {
        foreach (['production', 'local'] as $environment) {
            $pools = array_replace_recursive(config('horizon.defaults'), config('horizon.environments.'.$environment));
            $byQueue = collect($pools)->mapWithKeys(fn ($pool) => [$pool['queue'][0] => $pool]);
            self::assertSame(['default', 'extraction', 'synthesis'], $byQueue->keys()->sort()->values()->all(), $environment);
            foreach (QueueTopology::ROUTES as $job => $queue) {
                $timeout = (new \ReflectionClass($job))->getDefaultProperties()['timeout'];
                self::assertLessThan($byQueue[$queue]['timeout'], $timeout, $job.' on '.$queue);
            }
            foreach ($byQueue as $pool) {
                self::assertLessThan(config('queue.connections.redis.retry_after'), $pool['timeout']);
            }
        }
        $production = array_replace_recursive(config('horizon.defaults'), config('horizon.environments.production'));
        self::assertSame([2, 2, 2], [$production['supervisor-extraction']['maxProcesses'],
            $production['supervisor-synthesis']['maxProcesses'], $production['supervisor-1']['maxProcesses']]);
        self::assertSame(['redis:default' => 30, 'redis:synthesis' => 60, 'redis:extraction' => 120], config('horizon.waits'));
        // A permit held for a whole job must outlive that job.
        foreach ([ProcessDocumentChunkJob::class, GenerateDocumentSummaryJob::class, CompareDocumentsJob::class, ProcessDocumentVisualJob::class] as $holder) {
            self::assertLessThan(config('document_intelligence.provider_gate.lease_seconds'), (new \ReflectionClass($holder))->getDefaultProperties()['timeout'], $holder);
        }
        self::assertSame(2, app(ProviderGate::class)->max());
    }

    public function test_horizon_snapshots_and_recovery_are_scheduled(): void
    {
        Artisan::call('schedule:list');
        $commands = Artisan::output();
        self::assertStringContainsString('horizon:snapshot', $commands);
        self::assertStringContainsString('docintel:resume', $commands);
    }
}
