<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentIntelligenceResource;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\DocumentSourceSpan;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * Span-based evidence grounding, end to end: segmentation into chunks, the prompt contract,
 * strict ID validation, DocIntel-side retrieval, persistence and provider-call behaviour.
 */
class EvidenceSpanPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5',
            'document_intelligence.evidence_spans' => true,
            'document_intelligence.large_tokens' => 100,
            'document_intelligence.chunk_max_tokens' => 100, 'document_intelligence.chunk_overlap_tokens' => 5,
            'document_intelligence.minimum_split_chars' => 10, 'document_intelligence.budget_base_usd' => 2]);
    }

    private const PARAGRAPH = "The Bank approved thirty-seven sovereign operations during the 2024 financial year.\n\n"
        ."Total commitments reached USD 10 in 2024, compared with USD 9 in 2023.\n\n"
        ."Private-sector financing increased substantially against a demanding external backdrop.\n\n";

    private function document(?string $text = null, int $repeat = 6): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Synthetic report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'test'.$user->id),
            'extracted_text' => $text ?? str_repeat(self::PARAGRAPH, $repeat)]);
    }

    private function plan(Document $document): Document
    {
        $document->forceFill(['ai_pipeline' => ['tokens' => 1000, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return $document->refresh();
    }

    private function leaves(Document $document)
    {
        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')
            ->orderBy('start_offset')->get();
    }

    private function response(array $data, string $stop = 'end_turn'): array
    {
        return ['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 15],
            'content' => [['type' => 'text', 'text' => json_encode($data)]]];
    }

    private function fakeProvider(?array $response = null): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]),
            '*/messages' => Http::response($response ?? $this->response(['records' => []]), 200, ['request-id' => 'test-request'])]);
    }

    private function record(array $extra = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Commitments', 'value' => '10', 'subject' => 'The Bank',
            'reference' => '', 'entity_type' => null, 'unit' => 'USD', 'metric_type' => null, 'value_basis' => null,
            'aggregation' => null, 'quantity_kind' => null, 'period' => '2024', 'date_type' => null,
            'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => [],
            'evidence_ids' => ['E001']], $extra);
    }

    private function executeChunk(DocumentChunk $chunk): void
    {
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
    }

    /** The span IDs a chunk actually showed the model. */
    private function chunkIds(Document $document, DocumentChunk $chunk): array
    {
        return app(EvidenceGrounding::class)->chunkSpans($document, $chunk)->keys();
    }

    // --- planning and chunking -------------------------------------------------------------

    public function test_planning_persists_one_span_set_and_records_the_grounding_mode(): void
    {
        $document = $this->plan($this->document());
        $version = app(EvidenceGrounding::class)->version($document);

        self::assertSame(EvidenceGrounding::SPANS, $document->ai_pipeline['grounding']);
        self::assertSame($version, $document->ai_pipeline['extraction_version']);
        self::assertGreaterThan(0, DocumentSourceSpan::where('document_id', $document->id)->count());
        self::assertSame(DocumentSourceSpan::count(),
            DocumentSourceSpan::where('extraction_version', $version)->count());
        // Offsets only: the span table never holds a second copy of the document text.
        self::assertFalse(in_array('text', array_keys(DocumentSourceSpan::first()->getAttributes()), true));
    }

    public function test_the_span_set_is_built_once_and_reused_by_later_calls(): void
    {
        $document = $this->plan($this->document());
        $count = DocumentSourceSpan::count();

        app(EvidenceGrounding::class)->spans($document->fresh());
        app(IncrementalPipeline::class)->start($document->fresh());

        self::assertSame($count, DocumentSourceSpan::count());
    }

    public function test_chunk_boundaries_are_always_span_boundaries(): void
    {
        $document = $this->plan($this->document(null, 10));
        $spans = app(EvidenceGrounding::class)->spans($document);
        $starts = array_column($spans->all(), 'start_offset');
        $ends = array_column($spans->all(), 'end_offset');
        $leaves = $this->leaves($document);

        self::assertGreaterThan(1, $leaves->count());
        foreach ($leaves as $chunk) {
            self::assertContains($chunk->start_offset, $starts, 'a chunk started inside a span');
            self::assertContains($chunk->end_offset, $ends, 'a chunk ended inside a span');
            // Every span the chunk covers is whole.
            foreach ($spans->forRange($chunk->start_offset, $chunk->end_offset)->all() as $span) {
                self::assertGreaterThanOrEqual($chunk->start_offset, $span['start_offset']);
                self::assertLessThanOrEqual($chunk->end_offset, $span['end_offset']);
            }
        }
    }

    public function test_every_span_is_covered_by_at_least_one_chunk(): void
    {
        $document = $this->plan($this->document(null, 10));
        $covered = [];
        foreach ($this->leaves($document) as $chunk) {
            $covered = [...$covered, ...$this->chunkIds($document, $chunk)];
        }

        self::assertSame(app(EvidenceGrounding::class)->spans($document)->keys(),
            array_values(array_unique($covered)));
    }

    public function test_chunk_token_sizing_stays_within_the_configured_budget(): void
    {
        $document = $this->plan($this->document(null, 12));
        $limit = (int) config('document_intelligence.chunk_max_tokens');

        foreach ($this->leaves($document) as $chunk) {
            // A single oversized span is allowed to exceed the budget; a multi-span chunk is not.
            if (count($this->chunkIds($document, $chunk)) > 1) {
                self::assertLessThanOrEqual($limit * 2, $chunk->token_count);
            }
        }
    }

    public function test_the_prompt_sends_labeled_spans_and_never_a_raw_source_text_field(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $ids = $this->chunkIds($document, $chunk);
        $this->fakeProvider($this->response(['records' => [$this->record(['evidence_ids' => [$ids[0]]])]]));
        $this->executeChunk($chunk);

        $sent = null;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/messages')) {
                $sent = json_decode($request->data()['messages'][0]['content'], true);
            }
        }
        self::assertNotNull($sent);
        self::assertArrayNotHasKey('source_text', $sent);
        self::assertArrayHasKey('evidence_spans', $sent);
        self::assertSame(3, $sent['max_evidence_ids']);
        foreach ($ids as $id) {
            self::assertStringContainsString('['.$id.']', $sent['evidence_spans']);
        }
        // The labeled source still carries the document's exact characters.
        self::assertStringContainsString('Total commitments reached USD 10 in 2024', $sent['evidence_spans']);
    }

    // --- grounding behaviour ---------------------------------------------------------------

    public function test_a_completed_chunk_with_valid_span_references_stores_resolved_evidence(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $id = $this->chunkIds($document, $chunk)[1];
        $this->fakeProvider($this->response(['records' => [$this->record(['evidence_ids' => [$id]])]]));
        $this->executeChunk($chunk);

        $chunk->refresh();
        self::assertSame('completed', $chunk->status);
        $record = $chunk->result['records'][0];
        self::assertSame([$id], $record['evidence_ids']);
        self::assertSame($record['evidence'][0]['text'], mb_substr($document->extracted_text,
            $record['evidence'][0]['start_offset'],
            $record['evidence'][0]['end_offset'] - $record['evidence'][0]['start_offset']));
        self::assertSame(EvidenceGrounding::SPANS, $chunk->result['_validation']['evidence_grounding_mode']);
    }

    public function test_formatting_differences_in_a_model_supplied_quote_no_longer_reject_anything(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $id = $this->chunkIds($document, $chunk)[0];
        // A quote with collapsed whitespace, a non-breaking space and a curly apostrophe: the exact
        // class of difference that produced quote_not_found_in_source in production.
        $this->fakeProvider($this->response(['records' => [$this->record(['evidence_ids' => [$id],
            'quote' => "The  Bank\u{00A0}approved thirty\u{2013}seven operations"])]]));
        $this->executeChunk($chunk);

        $chunk->refresh();
        self::assertSame('completed', $chunk->status);
        self::assertSame(0, $chunk->result['_validation']['records_dropped']);
        // The stored quote is DocIntel's, resolved from the span, not the model's text.
        self::assertStringContainsString($chunk->result['records'][0]['quote'], $document->extracted_text);
    }

    public function test_a_chunk_whose_every_reference_is_invalid_fails_without_splitting(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $this->fakeProvider($this->response(['records' => [
            $this->record(['evidence_ids' => ['E999']]), $this->record(['evidence_ids' => ['INVENTED']]),
        ]]));
        $this->executeChunk($chunk);

        $chunk->refresh();
        self::assertSame('failed', $chunk->status);
        self::assertSame('invalid_evidence', $chunk->failure_class);
        self::assertSame(2, $chunk->result['_validation']['rejection_reasons']['unknown_evidence_id']);
        // Output-contract failures are never retried on smaller input.
        self::assertSame(0, DocumentChunk::whereNotNull('parent_id')->count());
    }

    public function test_an_id_belonging_to_another_chunk_is_rejected_while_valid_ones_are_kept(): void
    {
        $document = $this->plan($this->document(null, 10));
        $leaves = $this->leaves($document);
        self::assertGreaterThan(1, $leaves->count());
        $first = $leaves->first();
        $foreign = array_values(array_diff($this->chunkIds($document, $leaves->last()),
            $this->chunkIds($document, $first)));
        self::assertNotEmpty($foreign);

        $this->fakeProvider($this->response(['records' => [
            $this->record(['evidence_ids' => [$this->chunkIds($document, $first)[0]]]),
            $this->record(['evidence_ids' => [$foreign[0]]]),
        ]]));
        $this->executeChunk($first);

        $first->refresh();
        self::assertSame('completed', $first->status);
        self::assertSame(1, $first->result['_validation']['records_kept']);
        self::assertSame(1, $first->result['_validation']['rejection_reasons']['evidence_id_outside_chunk']);
    }

    public function test_ids_generated_against_another_extraction_version_are_rejected(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $id = $this->chunkIds($document, $chunk)[0];
        // The pipeline believes it planned against a different extraction of the same document.
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'extraction_version' => 'stale-version']])->save();
        $this->fakeProvider($this->response(['records' => [$this->record(['evidence_ids' => [$id]])]]));
        $this->executeChunk($chunk->fresh());

        $chunk->refresh();
        self::assertSame('failed', $chunk->status);
        self::assertSame(1, $chunk->result['_validation']['rejection_reasons']['evidence_id_wrong_extraction_version']);
    }

    // --- splitting and retries -------------------------------------------------------------

    public function test_a_capacity_split_keeps_span_boundaries_and_preserves_source_order(): void
    {
        $document = $this->plan($this->document(null, 12));
        $parent = $this->leaves($document)->first();
        $spans = app(EvidenceGrounding::class)->spans($document);
        $parentIds = $this->chunkIds($document, $parent);
        self::assertGreaterThan(1, count($parentIds));

        $parent->update(['status' => 'running', 'failure_class' => 'max_tokens',
            'token_count' => max(2, (int) $parent->token_count)]);
        app(IncrementalPipeline::class)->split($parent->fresh(), $document);

        $children = DocumentChunk::where('parent_id', $parent->id)->orderBy('start_offset')->get();
        self::assertGreaterThan(1, $children->count());
        self::assertSame('split', $parent->fresh()->status);

        $seen = [];
        $previousEnd = null;
        foreach ($children as $child) {
            self::assertContains($child->start_offset, array_column($spans->all(), 'start_offset'));
            self::assertContains($child->end_offset, array_column($spans->all(), 'end_offset'));
            // Source ordering is preserved and children stay inside the parent.
            self::assertGreaterThanOrEqual($parent->start_offset, $child->start_offset);
            self::assertLessThanOrEqual($parent->end_offset, $child->end_offset);
            if ($previousEnd !== null) {
                self::assertGreaterThanOrEqual($previousEnd, $child->start_offset);
            }
            $previousEnd = $child->end_offset;
            $seen = [...$seen, ...$this->chunkIds($document, $child)];
        }
        // Every span the parent showed is still shown by exactly the same IDs.
        self::assertSame($parentIds, array_values(array_unique($seen)));
    }

    public function test_a_chunk_of_one_span_is_never_split_through_that_span(): void
    {
        $document = $this->plan($this->document('A single standalone sentence of evidence lives here alone.'));
        $chunk = $this->leaves($document)->first();
        self::assertCount(1, $this->chunkIds($document, $chunk));

        $chunk->update(['status' => 'running', 'failure_class' => 'max_tokens']);
        app(IncrementalPipeline::class)->split($chunk->fresh(), $document);

        $chunk->refresh();
        self::assertSame('failed', $chunk->status);
        self::assertSame('split_limit', $chunk->failure_class);
        self::assertSame(0, DocumentChunk::whereNotNull('parent_id')->count());
    }

    public function test_a_truncation_continuation_lists_references_instead_of_quotes(): void
    {
        $document = $this->plan($this->document());
        $parent = $this->leaves($document)->first();
        $id = $this->chunkIds($document, $parent)[0];
        $parent->update(['status' => 'completed',
            'result' => ['records' => [$this->record(['evidence_ids' => [$id]])]]]);
        $child = DocumentChunk::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'pipeline_key' => $parent->pipeline_key, 'identity' => $parent->identity.'.r', 'parent_id' => $parent->id,
            'start_offset' => $parent->start_offset, 'end_offset' => $parent->end_offset,
            'input_hash' => $parent->input_hash, 'pipeline_version' => $parent->pipeline_version,
            'prompt_version' => $parent->prompt_version]);

        $context = app(IncrementalPipeline::class)->continuationContext($child);
        self::assertSame([$id], $context['already_extracted'][0]['evidence_ids']);
        self::assertArrayNotHasKey('quote', $context['already_extracted'][0]);
    }

    // --- merge, persistence and compatibility ----------------------------------------------

    public function test_merge_persists_the_exact_span_text_with_its_location(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $ids = $this->chunkIds($document, $chunk);
        $chunk->update(['status' => 'completed',
            'result' => ['records' => [$this->resolved($document, $chunk, [$ids[1]])]]]);
        $stats = app(EvidenceMerger::class)->merge($document);

        self::assertSame(1, $stats['accepted_records']);
        self::assertSame(1, $stats['span_records']);
        self::assertSame(0, $stats['legacy_quote_records']);
        self::assertSame(0, $stats['rejected_quote']);
        $evidence = DocumentEvidence::sole();
        $source = $evidence->sources[0];
        self::assertSame($ids[1], $source['span_id']);
        self::assertSame($source['quote'], mb_substr($document->extracted_text,
            $source['start_offset'], $source['end_offset'] - $source['start_offset']));
        self::assertSame($evidence->data['quote'], $source['quote']);
        self::assertArrayHasKey('page', $source);
    }

    public function test_multi_span_evidence_keeps_each_span_as_its_own_source(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $ids = array_slice($this->chunkIds($document, $chunk), 0, 2);
        $chunk->update(['status' => 'completed',
            'result' => ['records' => [$this->resolved($document, $chunk, $ids)]]]);
        app(EvidenceMerger::class)->merge($document);

        $evidence = DocumentEvidence::sole();
        self::assertCount(2, $evidence->sources);
        self::assertSame($ids, array_column($evidence->sources, 'span_id'));
        foreach ($evidence->sources as $source) {
            self::assertSame($source['quote'], mb_substr($document->extracted_text,
                $source['start_offset'], $source['end_offset'] - $source['start_offset']));
        }
        // User-facing text combines them; internal storage never does.
        self::assertSame(implode("\n\n", array_column($evidence->sources, 'quote')), $evidence->data['quote']);
    }

    public function test_legacy_quote_records_still_merge_unchanged(): void
    {
        config(['document_intelligence.evidence_spans' => false]);
        $document = $this->plan($this->document());
        self::assertSame(EvidenceGrounding::LEGACY, $document->ai_pipeline['grounding']);

        $chunk = $this->leaves($document)->first();
        $quote = 'Total commitments reached USD 10 in 2024, compared with USD 9 in 2023.';
        $legacy = $this->record(['quote' => $quote]);
        unset($legacy['evidence_ids']);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$legacy]]]);
        $stats = app(EvidenceMerger::class)->merge($document);

        self::assertSame(1, $stats['legacy_quote_records']);
        self::assertSame(0, $stats['span_records']);
        $evidence = DocumentEvidence::sole();
        self::assertSame($quote, $evidence->data['quote']);
        self::assertArrayNotHasKey('span_id', $evidence->sources[0]);
        self::assertSame($quote, mb_substr($document->extracted_text,
            $evidence->sources[0]['start_offset'],
            $evidence->sources[0]['end_offset'] - $evidence->sources[0]['start_offset']));
    }

    public function test_span_and_legacy_records_coexist_in_one_merge(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $legacy = $this->record(['label' => 'Legacy', 'value' => '9',
            'quote' => 'The Bank approved thirty-seven sovereign operations during the 2024 financial year.']);
        unset($legacy['evidence_ids']);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [
            $this->resolved($document, $chunk, [$this->chunkIds($document, $chunk)[1]]), $legacy,
        ]]]);
        $stats = app(EvidenceMerger::class)->merge($document);

        self::assertSame(1, $stats['span_records']);
        self::assertSame(1, $stats['legacy_quote_records']);
        self::assertSame(2, DocumentEvidence::count());
    }

    public function test_the_intelligence_api_still_exposes_a_quote_and_now_carries_span_locations(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $chunk->update(['status' => 'completed', 'result' => ['records' => [
            $this->resolved($document, $chunk, [$this->chunkIds($document, $chunk)[1]], ['kind' => 'risk',
                'label' => 'Concentration risk', 'severity' => 'high']),
        ]]]);
        app(EvidenceMerger::class)->merge($document);

        $payload = (new DocumentIntelligenceResource($document->fresh()))
            ->toArray(request());
        $evidence = (array) $payload['evidence'];

        self::assertNotEmpty($evidence);
        $entry = reset($evidence);
        self::assertStringContainsString($entry['quote'], $document->extracted_text);
        self::assertArrayHasKey('span_id', $entry['sources'][0]);
        self::assertArrayHasKey('page', $entry['sources'][0]);
    }

    // --- provider-call behaviour -----------------------------------------------------------

    public function test_provider_calls_stay_chunk_level_and_never_scale_with_span_count(): void
    {
        // Realistic chunk sizing: many evidence spans inside one provider call.
        config(['document_intelligence.chunk_max_tokens' => 400]);
        $document = $this->plan($this->document(null, 12));
        $leaves = $this->leaves($document);
        $spans = app(EvidenceGrounding::class)->spans($document)->count();
        self::assertGreaterThan(3 * $leaves->count(), $spans, 'the fixture must hold many spans per chunk');

        $this->fakeProvider();
        foreach ($leaves as $chunk) {
            $this->executeChunk($chunk->fresh());
        }

        $extractionCalls = DocumentAiRun::whereNotNull('chunk_id')->count();
        self::assertSame($leaves->count(), $extractionCalls);
        self::assertLessThan($spans, $extractionCalls);
    }

    public function test_diagnostics_are_logged_as_metadata_without_any_source_text(): void
    {
        $secret = 'CONFIDENTIAL BOARD MATTER XYZZY';
        $document = $this->plan($this->document($secret.".\n\n".str_repeat(self::PARAGRAPH, 4)));
        $chunk = $this->leaves($document)->first();
        $this->fakeProvider($this->response(['records' => [
            $this->record(['evidence_ids' => [$this->chunkIds($document, $chunk)[0]]]),
            $this->record(['evidence_ids' => ['E999']]),
        ]]));

        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        $this->executeChunk($chunk);

        $records = array_filter($handler->getRecords(),
            fn ($entry) => $entry['message'] === 'Document intelligence validation summary');
        self::assertNotEmpty($records);
        $context = reset($records)['context'];
        self::assertSame(EvidenceGrounding::SPANS, $context['evidence_grounding_mode']);
        self::assertSame(1, $context['records_kept']);
        self::assertSame(1, $context['rejection_reasons']['unknown_evidence_id']);
        self::assertStringNotContainsString('XYZZY', json_encode($handler->getRecords()));
    }

    public function test_the_pipeline_report_command_reconciles_counts_for_a_span_run(): void
    {
        $document = $this->plan($this->document());
        $chunk = $this->leaves($document)->first();
        $this->fakeProvider($this->response(['records' => [
            $this->record(['evidence_ids' => [$this->chunkIds($document, $chunk)[0]]]),
            $this->record(['evidence_ids' => ['E999']]),
        ]]));
        $this->executeChunk($chunk);
        app(EvidenceMerger::class)->merge($document->fresh());

        self::assertSame(0, Artisan::call('docintel:pipeline-report', ['document' => $document->id, '--json' => true]));

        $report = json_decode(Artisan::output(), true);
        self::assertSame(EvidenceGrounding::SPANS, $report['document']['grounding']);
        self::assertSame(2, $report['records']['returned']);
        self::assertSame(1, $report['records']['accepted']);
        self::assertSame(1, $report['records']['rejected']);
        self::assertEquals(50.0, $report['records']['acceptance_rate']);
        self::assertSame(0, $report['grounding']['quote_not_found_in_source']);
        self::assertSame(1, $report['grounding']['span_id_rejections']);
        self::assertGreaterThan(0, $report['grounding']['span_reference_sources']);
        self::assertSame(0, $report['grounding']['legacy_quote_sources']);
        self::assertSame(1, $report['provider']['extraction_calls']);
    }

    // --- full pipeline semantics -----------------------------------------------------------

    public function test_span_grounded_extraction_flows_through_merge_into_synthesis(): void
    {
        $document = $this->plan($this->document());
        foreach ($this->leaves($document) as $chunk) {
            $chunk->update(['status' => 'completed', 'result' => ['records' => [
                $this->resolved($document, $chunk, [$this->chunkIds($document, $chunk)[0]]),
            ]]]);
        }
        app(IncrementalPipeline::class)->pump($document->id);
        Bus::assertDispatchedTimes(MergeDocumentEvidenceJob::class, 1);

        $merge = DocumentChunk::where('stage', 'merge')->sole();
        (new MergeDocumentEvidenceJob($merge->id))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame('Processing', $document->fresh()->status);
        self::assertGreaterThan(0, DocumentEvidence::count());

        $sourceId = DocumentEvidence::first()->source_id;
        Http::fake(['*/messages' => Http::response($this->response([
            'executive_summary' => 'Commitments increased.', 'key_findings' => ['Commitments increased.'],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [],
            'trends' => [['observation' => 'Commitments rose.', 'significance' => 'Growth.', 'source_ids' => [$sourceId]]],
        ]))]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        self::assertSame([$sourceId], $document->fresh()->intelligenceSummary->trends[0]['source_ids']);
        // Evidence offered to synthesis still carries the resolved source text and its location.
        $coverage = app(EvidenceBudget::class)->forDocument($document->fresh());
        self::assertTrue($coverage['coverage']['comprehensive']);
    }

    public function test_partial_coverage_semantics_survive_a_failed_span_leaf(): void
    {
        $document = $this->plan($this->document(null, 10));
        $leaves = $this->leaves($document);
        self::assertGreaterThan(1, $leaves->count());
        foreach ($leaves as $index => $chunk) {
            $index === 0
                ? $chunk->update(['status' => 'failed', 'failure_class' => 'invalid_evidence', 'completed_at' => now(),
                    'result' => ['records' => [], '_validation' => ['records_returned' => 2, 'records_kept' => 0,
                        'records_dropped' => 2, 'rejections' => ['invalid_evidence' => 2],
                        'rejection_reasons' => ['unknown_evidence_id' => 2],
                        'evidence_grounding_mode' => EvidenceGrounding::SPANS],
                        '_dropped_records' => ['invalid_evidence' => 2]]])
                : $chunk->update(['status' => 'completed', 'result' => ['records' => [
                    $this->resolved($document, $chunk, [$this->chunkIds($document, $chunk)[0]]),
                ]]]);
        }
        app(IncrementalPipeline::class)->pump($document->id);
        // Independent successful chunks are still merged.
        Bus::assertDispatchedTimes(MergeDocumentEvidenceJob::class, 1);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        (new MergeDocumentEvidenceJob($merge->id))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));

        $coverage = app(EvidenceBudget::class)->forDocument($document->fresh())['coverage'];
        self::assertFalse($coverage['comprehensive']);
        self::assertSame(1, $coverage['failed_chunks']);
        self::assertSame(2, $coverage['dropped_records']);
        self::assertNotNull($coverage['warning']);
    }

    public function test_a_document_whose_every_span_reference_is_invalid_needs_review(): void
    {
        $document = $this->plan($this->document());
        foreach ($this->leaves($document) as $chunk) {
            $chunk->update(['status' => 'failed', 'failure_class' => 'invalid_evidence', 'completed_at' => now()]);
        }
        app(IncrementalPipeline::class)->pump($document->id);

        $document->refresh();
        self::assertSame('Needs Review', $document->status);
        self::assertTrue($document->ai_pipeline['partial']);
        self::assertSame('no_evidence', $document->ai_pipeline['synthesis']);
        Bus::assertNotDispatched(MergeDocumentEvidenceJob::class);
    }

    public function test_flipping_the_flag_starts_a_new_pipeline_instead_of_mixing_modes(): void
    {
        $document = $this->plan($this->document());
        $spanKey = $document->ai_pipeline['key'];
        self::assertSame(EvidenceGrounding::SPANS, $document->ai_pipeline['grounding']);

        config(['document_intelligence.evidence_spans' => false]);
        $document = $this->plan($document->fresh());

        self::assertNotSame($spanKey, $document->ai_pipeline['key']);
        self::assertSame(EvidenceGrounding::LEGACY, $document->ai_pipeline['grounding']);
        self::assertNull($document->ai_pipeline['extraction_version']);
        // The earlier span-based chunks are untouched, not rewritten.
        self::assertGreaterThan(0, DocumentChunk::where('pipeline_key', $spanKey)->count());
    }

    /**
     * A chunk result record as the validator would have produced it: evidence resolved by
     * DocIntel from the cited spans, never taken from the model.
     */
    private function resolved(Document $document, DocumentChunk $chunk, array $ids, array $extra = []): array
    {
        $spans = app(EvidenceGrounding::class)->chunkSpans($document, $chunk);
        $evidence = array_map(fn ($id) => $spans->resolve($id), $ids);

        return $this->record(['evidence_ids' => $ids, 'evidence' => $evidence,
            'quote' => implode("\n\n", array_column($evidence, 'text')), ...$extra]);
    }
}
