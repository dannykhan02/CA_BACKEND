<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Truncated extraction: output-aware sizing, record salvage + remainder-only continuation, spend ceiling. */
class ExtractionTruncationTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Revenue increased to USD 10 in 2024. Costs fell to USD 4 in 2024. Staff grew to 50 in 2024.';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5', 'document_intelligence.budget_base_usd' => 2]);
    }

    private function chunk(string $text = self::TEXT): DocumentChunk
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'trunc'.$user->id), 'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['tokens' => 30, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
    }

    private function record(string $quote, array $extra = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Metric', 'value' => '1', 'subject' => 'Company',
            'quote' => $quote, 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.9, 'aliases' => []], $extra);
    }

    private function response(string $text, string $stop = 'end_turn', int $output = 20): array
    {
        return ['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 100, 'output_tokens' => $output], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** Two complete records (one with a fabricated quote) and a third cut off mid-string. */
    private function truncated(): string
    {
        $valid = $this->record('Revenue increased to USD 10 in 2024.', ['label' => 'Revenue {total} "reported"', 'value' => '10']);
        $invented = $this->record('Profit reached USD 99 in 2024.', ['label' => 'Profit', 'value' => '99']);

        return '{"records": ['.json_encode($valid).', '.json_encode($invented).', {"kind": "metric", "label": "Costs", "quote": "Costs fell to US';
    }

    private function execute(DocumentChunk $chunk): void
    {
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
    }

    // 1. Output-aware sizing

    public function test_partitions_are_sized_so_expected_output_stays_under_the_configured_cap(): void
    {
        $capacity = app(ExtractionCapacity::class);
        $cap = (int) config('document_intelligence.extraction_max_tokens');
        self::assertSame($cap, $capacity->outputTokens());
        foreach ([(float) config('document_intelligence.expected_records_per_1k_tokens'), (float) config('document_intelligence.dense_records_per_1k_tokens')] as $density) {
            $partition = $capacity->partitionTokens($density);
            self::assertLessThanOrEqual($capacity->outputCapacity(), $capacity->expectedOutputTokens($partition, $density));
            self::assertLessThan($cap, $capacity->outputCapacity());
            // A 62k-token document: every planned slice fits one response.
            $text = str_repeat("Revenue in region 4.2 rose to USD 42 million in 2024 while costs were stable.\n\n", 3200);
            foreach (app(ChunkPlanner::class)->partition($text, $partition, 62125 / strlen($text)) as $slice) {
                self::assertLessThanOrEqual($capacity->outputCapacity(), $capacity->expectedOutputTokens($slice['token_count'], $density));
            }
        }
        // The record limit sent with every request also fits the planned output.
        self::assertLessThanOrEqual($capacity->outputCapacity(), $capacity->recordLimit() * (int) config('document_intelligence.output_tokens_per_record') + 64);
        // Config, not code: a larger cap yields larger slices (fewer requests).
        $before = $capacity->partitionTokens();
        config(['document_intelligence.extraction_max_tokens' => 32000]);
        self::assertGreaterThan($before, $capacity->partitionTokens());
    }

    // 2. Salvage + remainder-only continuation

    public function test_salvage_parser_keeps_only_complete_record_objects(): void
    {
        $records = EvidenceSchema::salvage($this->truncated());
        self::assertCount(2, $records);
        self::assertSame('Revenue {total} "reported"', $records[0]['label']);
        self::assertSame([], EvidenceSchema::salvage('{"records": [{"kind": "metric", "label": "Cut'));
        self::assertSame([], EvidenceSchema::salvage('not json at all'));
    }

    public function test_truncated_response_keeps_valid_records_and_requests_only_the_remainder(): void
    {
        $chunk = $this->chunk();
        $remaining = $this->record('Costs fell to USD 4 in 2024.', ['label' => 'Costs', 'value' => '4']);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]),
            '*/messages' => Http::sequence()->push($this->response($this->truncated(), 'max_tokens', 16000))
                ->push($this->response(json_encode(['records' => [$remaining]])))]);
        $this->execute($chunk);

        $chunk->refresh();
        self::assertSame('completed', $chunk->status);
        // Strict validation still applies: the fabricated quote is dropped, the cut-off record never parsed.
        self::assertSame(['Revenue increased to USD 10 in 2024.'], array_column($chunk->result['records'], 'quote'));
        self::assertSame(1, $chunk->result['_dropped_records']['invalid_evidence']);
        self::assertTrue($chunk->result['_truncated']);
        self::assertTrue($chunk->result['_continued']);
        self::assertFalse($chunk->result['_saturated']);
        // A continuation over the same text, not split halves.
        $continuation = DocumentChunk::where('parent_id', $chunk->id)->sole();
        self::assertSame([$chunk->start_offset, $chunk->end_offset, $chunk->depth], [$continuation->start_offset, $continuation->end_offset, $continuation->depth]);
        self::assertSame(0, DocumentChunk::where('status', 'split')->count());

        $this->execute($continuation);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/messages')) {
                return false;
            }
            $payload = json_decode($request['messages'][0]['content'], true);

            return array_column($payload['already_extracted'] ?? [], 'label') === ['Revenue {total} "reported"'];
        });
        self::assertSame('completed', $continuation->fresh()->status);
        $document = Document::findOrFail($chunk->document_id);
        app(EvidenceMerger::class)->merge($document);
        self::assertEqualsCanonicalizing(['Revenue increased to USD 10 in 2024.', 'Costs fell to USD 4 in 2024.'],
            DocumentEvidence::all()->map(fn ($e) => $e->data['quote'])->all());
        $coverage = app(EvidenceBudget::class)->forDocument($document)['coverage'];
        self::assertSame(0, $coverage['failed_chunks']);
        self::assertSame(0, $coverage['saturated_chunks']);
    }

    public function test_continuations_are_bounded_and_the_remainder_is_disclosed(): void
    {
        config(['document_intelligence.max_truncation_continuations' => 0]);
        $chunk = $this->chunk();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]),
            '*/messages' => Http::response($this->response($this->truncated(), 'max_tokens', 16000))]);
        $this->execute($chunk);
        $chunk->refresh();
        self::assertSame('completed', $chunk->status);
        self::assertTrue($chunk->result['_saturated']);
        self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count());
        $coverage = app(EvidenceBudget::class)->forDocument(Document::findOrFail($chunk->document_id))['coverage'];
        self::assertSame(1, $coverage['saturated_chunks']);
        self::assertFalse($coverage['comprehensive']);
    }

    public function test_truncation_without_any_valid_complete_record_still_splits(): void
    {
        $chunk = $this->chunk(str_repeat(self::TEXT.' ', 60));
        $invented = $this->record('Profit reached USD 99 in 2024.');
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 1500]),
            '*/messages' => Http::response($this->response('{"records": ['.json_encode($invented).', {"kind": "met', 'max_tokens', 16000))]);
        config(['document_intelligence.minimum_split_chars' => 100]);
        $this->execute($chunk);
        self::assertSame('split', $chunk->fresh()->status);
        self::assertNull($chunk->fresh()->result);
        self::assertGreaterThanOrEqual(2, DocumentChunk::where('parent_id', $chunk->id)->count());
        self::assertSame(0, DocumentEvidence::count());
    }

    // 3. Per-document hard spend ceiling

    public function test_spend_ceiling_is_capped_by_config(): void
    {
        config(['document_intelligence.budget_max_usd' => 3]);
        $chunk = $this->chunk();
        $document = Document::findOrFail($chunk->document_id);
        $document->forceFill(['ai_pipeline' => ['tokens' => 50000000, 'route' => 'incremental']])->save();
        DocumentChunk::query()->delete();
        app(IncrementalPipeline::class)->start($document->fresh());
        self::assertSame(3.0, (float) $document->fresh()->ai_pipeline['budget_usd']);
    }

    /** Leaves only the synthesis + repair hold (plus a cent) once the running attempt is billed. */
    private function exhaustDuringRequest(DocumentChunk $chunk, array $response, int $status = 200): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 30]),
            '*/messages' => function () use ($chunk, $response, $status) {
                $document = Document::findOrFail($chunk->document_id);
                $pipeline = $document->ai_pipeline;
                $document->forceFill(['ai_pipeline' => [...$pipeline, 'budget_usd' => $pipeline['synthesis_reserved_usd']
                    + $pipeline['repair_reserved_usd'] + 0.01]])->save();

                return Http::response($response, $status);
            }]);
    }

    public function test_spend_ceiling_stops_splitting(): void
    {
        $chunk = $this->chunk();
        $this->exhaustDuringRequest($chunk, $this->response('{"records": [', 'max_tokens', 16000));
        $this->execute($chunk);
        $chunk->refresh();
        self::assertSame('budget', $chunk->status);
        self::assertSame('budget_exceeded', $chunk->failure_class);
        self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count());
        Http::assertSentCount(2); // One count, one generation: nothing re-sent.
    }

    public function test_spend_ceiling_stops_retrying(): void
    {
        $chunk = $this->chunk();
        $this->exhaustDuringRequest($chunk, [], 429);
        $this->execute($chunk);
        self::assertSame('budget', $chunk->fresh()->status);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 1); // Only the original dispatch, no retry.
    }

    public function test_spend_ceiling_keeps_salvaged_evidence_but_stops_the_continuation(): void
    {
        $salvaged = $this->chunk();
        $this->exhaustDuringRequest($salvaged, $this->response($this->truncated(), 'max_tokens', 16000));
        $this->execute($salvaged);
        $salvaged->refresh();
        // Paid-for valid evidence is kept; the unaffordable remainder is disclosed, not requested.
        self::assertSame('completed', $salvaged->status);
        self::assertCount(1, $salvaged->result['records']);
        self::assertTrue($salvaged->result['_saturated']);
        self::assertSame(0, DocumentChunk::where('parent_id', $salvaged->id)->count());
    }

    // 4. Never split on invalid_evidence

    public function test_invalid_evidence_never_splits_even_with_budget_left(): void
    {
        $chunk = $this->chunk(str_repeat(self::TEXT.' ', 60));
        config(['document_intelligence.minimum_split_chars' => 100]);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 1500]),
            '*/messages' => Http::response($this->response(json_encode(['records' => [$this->record('Profit reached USD 99 in 2024.')]])))]);
        $this->execute($chunk);
        $chunk->refresh();
        self::assertSame('failed', $chunk->status);
        self::assertSame('invalid_evidence', $chunk->failure_class);
        self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count());
        self::assertSame(0, DocumentChunk::where('status', 'split')->count());
        Http::assertSentCount(2);
        // The splitter itself refuses too.
        $chunk->update(['status' => 'running']);
        app(IncrementalPipeline::class)->split($chunk->fresh(), Document::findOrFail($chunk->document_id));
        self::assertSame('failed', $chunk->fresh()->status);
        self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count());
    }
}
