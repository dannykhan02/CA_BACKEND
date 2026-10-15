<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\SynthesisBatches;
use App\Services\AI\Incremental\SynthesisCoverage;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SynthesisQueryScaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['document_intelligence.synthesis_token_budget' => 4000,
            'document_intelligence.synthesis_max_batches' => 48,
            'document_intelligence.synthesis_source_max_tokens' => 0,
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5',
            'intelligence_v2.enabled' => false]);
    }

    private function documentWithEvidence(int $count): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Evidence report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'synthesis scale '.$count),
            'extracted_text' => 'Evidence report.']);
        $key = hash('sha256', $document->id);
        $document->forceFill(['ai_pipeline' => ['key' => $key, 'route' => 'incremental',
            'tokens' => 10, 'budget_usd' => 100]])->save();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => $key,
                'identity' => hash('sha256', 'fact '.$i), 'kind' => 'fact', 'source_id' => 'fact:'.$i,
                'data' => json_encode(['label' => 'Fact '.$i, 'value' => 'Observed '.$i, 'quote' => 'Evidence report.']),
                'sources' => json_encode([['page' => 1, 'start_offset' => $i, 'end_offset' => $i + 1]]),
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('document_evidence')->insert($chunk);
        }
        $batches = app(SynthesisCoverage::class)->plan($document)['batches'];
        foreach ($batches as $batch) {
            DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'pipeline_key' => $key, 'identity' => app(SynthesisBatches::class)->identity($batch),
                'stage' => 'synthesis_batch', 'input_hash' => hash('sha256', json_encode($batch)),
                'pipeline_version' => '1', 'prompt_version' => '1', 'status' => 'completed',
                'result' => ['findings' => [['id' => $batch['items'][0]['id']]]]]);
        }

        return [$document->fresh(), count($batches)];
    }

    private function profile(callable $callback): array
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $result = $callback();
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
        $bySql = array_count_values(array_column($queries, 'query'));
        arsort($bySql);

        return ['result' => $result, 'count' => count($queries),
            'db_ms' => round(array_sum(array_column($queries, 'time')), 3),
            'repeated' => $bySql ? max($bySql) : 0,
            'batch_reads' => count(array_filter($queries, static fn ($query) => str_contains($query['query'], '"document_chunks"')
                && str_contains($query['query'], '"identity"') && str_starts_with($query['query'], 'select'))),
            'top_sql' => array_key_first($bySql)];
    }

    #[DataProvider('sizes')]
    public function test_completed_batch_reads_remain_bounded_and_coverage_is_preserved(int $count): void
    {
        [$document, $batchCount] = $this->documentWithEvidence($count);
        $client = app(AnthropicClient::class);
        $claim = $this->profile(fn () => app(SynthesisBatches::class)->processNext($document, null, $client));
        self::assertSame('ready', $claim['result']);
        $coverage = $this->profile(fn () => app(EvidenceBudget::class)->forSynthesis($document));
        self::assertSame($count, $coverage['result']['coverage']['evidence_total']);
        self::assertSame($count, $coverage['result']['coverage']['synthesis_considered']);
        self::assertSame(0, $coverage['result']['coverage']['synthesis_uncovered']);
        self::assertSame($batchCount > 1 ? 3 : 1, $claim['count']);
        self::assertSame($batchCount > 1 ? 4 : 3, $coverage['count']);
        self::assertSame($batchCount > 1 ? 1 : 0, $claim['batch_reads']);
        self::assertSame($batchCount > 1 ? 1 : 0, $coverage['batch_reads']);
        self::assertLessThanOrEqual(2, $claim['repeated']);
        self::assertLessThanOrEqual(2, $coverage['repeated']);

        if (getenv('DOCINTEL_QUERY_PROFILE')) {
            fwrite(STDERR, json_encode(['evidence' => $count, 'batches' => $batchCount,
                'claim' => $claim, 'coverage' => array_diff_key($coverage, ['result' => true])], JSON_THROW_ON_ERROR)."\n");
        }

        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [['type' => 'text', 'text' => json_encode(['executive_summary' => 'Evidence is present.',
                'key_findings' => ['Evidence is present.'], 'critical_risks' => [], 'upcoming_deadlines' => [],
                'important_entities' => [], 'recommended_attention' => []])]]])]);
        (new GenerateDocumentSummaryJob($document->id))->handle($client, app(PipelineStageRecorder::class));
        self::assertSame('Ready', $document->fresh()->status);
        $expectedSummary = match ($count) {
            10 => 'Evidence is present.',
            100 => "Fact 0: Observed 0 [fact:0]\nFact 55: Observed 55 [fact:55]\nFact 99: Observed 99 [fact:99]\nFact 50: Observed 50 [fact:50]\nFact 19: Observed 19 [fact:19]\nFact 37: Observed 37 [fact:37]",
            799 => "Fact 0: Observed 0 [fact:0]\nFact 163: Observed 163 [fact:163]\nFact 798: Observed 798 [fact:798]\nFact 399: Observed 399 [fact:399]\nFact 19: Observed 19 [fact:19]\nFact 37: Observed 37 [fact:37]",
        };
        self::assertSame($expectedSummary, $document->fresh()->intelligenceSummary->executive_summary);
        self::assertSame($count === 10 ? 0 : ($count === 100 ? 92 : 781),
            $document->fresh()->ai_pipeline['coverage']['presentation_omitted']);
        if (getenv('DOCINTEL_QUERY_PROFILE')) {
            fwrite(STDERR, json_encode(['evidence' => $count,
                'summary' => $document->fresh()->intelligenceSummary->executive_summary,
                'coverage' => $document->fresh()->ai_pipeline['coverage']], JSON_THROW_ON_ERROR)."\n");
        }
        self::assertSame($count, $document->fresh()->ai_pipeline['coverage']['evidence_total']);
        Http::assertSentCount(1);
    }

    public static function sizes(): array
    {
        return ['ten' => [10], 'hundred' => [100], 'seven hundred ninety nine' => [799]];
    }

    public function test_bulk_checkpoint_lookup_keeps_failed_batch_coverage_exact(): void
    {
        [$document] = $this->documentWithEvidence(100);
        $first = app(SynthesisCoverage::class)->plan($document)['batches'][0];
        DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])
            ->where('identity', app(SynthesisBatches::class)->identity($first))
            ->update(['status' => 'failed', 'failure_class' => 'invalid_schema']);

        $data = app(EvidenceBudget::class)->forSynthesis($document);
        self::assertSame(count($first['items']), $data['coverage']['evidence_omitted']);
        self::assertSame(100 - count($first['items']), $data['coverage']['synthesis_considered']);
        self::assertSame([['group' => $first['group'], 'reason' => 'invalid_schema',
            'count' => count($first['items'])]], $data['coverage']['uncovered_groups']);
        self::assertFalse($data['coverage']['comprehensive']);
        self::assertSame('ready', app(SynthesisBatches::class)->processNext($document, null, app(AnthropicClient::class)));
    }
}
