<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\OcrPageBatchJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Jobs\ProcessDocumentVisualJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\ContextResolver;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\ResponseValidator;
use App\Services\AnthropicClient;
use App\Services\DocumentIntelligenceService;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\OcrPageResult;
use App\Services\Ocr\OcrProviderInterface;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncrementalDocumentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'document_intelligence.large_tokens' => 100, 'document_intelligence.chunk_target_tokens' => 80,
            'document_intelligence.chunk_max_tokens' => 100, 'document_intelligence.chunk_overlap_tokens' => 5,
            'document_intelligence.minimum_split_chars' => 10, 'document_intelligence.budget_base_usd' => 2]);
    }

    private function document(?string $text = null): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Synthetic report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'test'.$user->id),
            'extracted_text' => $text ?? str_repeat("Revenue increased to USD 10 in 2024.\n\n", 25)]);
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

    private function plan(Document $document): DocumentChunk
    {
        $document->forceFill(['ai_pipeline' => ['tokens' => 1000, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        $document->refresh();

        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->orderBy('start_offset')->firstOrFail();
    }

    private function executeChunk(DocumentChunk $chunk): void
    {
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
    }

    private function record(array $extra = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company',
            'quote' => 'Revenue increased to USD 10 in 2024.', 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => []], $extra);
    }

    public function test_small_document_keeps_simple_path_without_generation_or_chunks(): void
    {
        $document = $this->document('Short report.');
        self::assertFalse(app(IncrementalPipeline::class)->route($document));
        self::assertSame(0, DocumentChunk::count());
        Http::assertNothingSent();
    }

    public function test_large_document_plans_bounded_independent_jobs(): void
    {
        $document = $this->document();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 600])]);
        self::assertTrue(app(IncrementalPipeline::class)->route($document));
        self::assertGreaterThan(2, DocumentChunk::count());
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 2);
        self::assertSame(2, DocumentChunk::where('status', 'queued')->count());
        self::assertSame(0, DocumentChunk::whereNotNull('parent_id')->count());
    }

    public function test_no_heading_unicode_document_preserves_coverage_and_limits(): void
    {
        $text = str_repeat('漢字 revenue without punctuation ', 80);
        $chunks = app(ChunkPlanner::class)->plan($text);
        $covered = 0;
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(80, $chunk['token_count']);
            self::assertLessThanOrEqual($covered, $chunk['start_offset']);
            self::assertGreaterThan($covered, $chunk['end_offset']);
            $covered = $chunk['end_offset'];
            self::assertSame($chunk['input_hash'], hash('sha256', mb_substr($text, $chunk['start_offset'], $chunk['end_offset'] - $chunk['start_offset'])));
        }
        self::assertSame(mb_strlen($text), $covered);
    }

    public function test_page_mapping_survives_planning(): void
    {
        $text = str_repeat('A', 160)."\f".str_repeat('B', 160)."\f".str_repeat('C', 160);
        foreach (app(ChunkPlanner::class)->plan($text) as $chunk) {
            self::assertSame(1 + substr_count(mb_substr($text, 0, $chunk['start_offset']), "\f"), $chunk['start_page']);
        }
    }

    public function test_completed_chunk_and_redelivery_do_not_repeat_provider_work(): void
    {
        $this->fakeProvider();
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        $this->executeChunk($chunk);
        Http::assertSentCount(2); // One free token count and one generation.
        self::assertSame('completed', $chunk->fresh()->status);
        self::assertSame(1, DocumentAiRun::where('chunk_id', $chunk->id)->count());
    }

    public function test_running_duplicate_dispatch_never_calls_provider(): void
    {
        $chunk = $this->plan($this->document());
        $chunk->update(['status' => 'running']);
        $this->executeChunk($chunk);
        Http::assertNothingSent();
    }

    public function test_truncation_splits_only_offending_chunk_without_retrying_request(): void
    {
        $this->fakeProvider($this->response(['records' => []], 'max_tokens'));
        $chunk = $this->plan($this->document());
        $siblings = DocumentChunk::where('id', '!=', $chunk->id)->pluck('input_hash', 'id');
        $this->executeChunk($chunk);
        self::assertSame('split', $chunk->fresh()->status);
        self::assertGreaterThanOrEqual(2, DocumentChunk::where('parent_id', $chunk->id)->count());
        self::assertSame($siblings->all(), DocumentChunk::whereIn('id', $siblings->keys())->pluck('input_hash', 'id')->all());
        Http::assertSentCount(2);
    }

    public function test_transient_provider_failures_have_bounded_attempts(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::response([], 429)]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('queued', $chunk->fresh()->status);
        $this->executeChunk($chunk);
        $this->executeChunk($chunk);
        $this->executeChunk($chunk);
        self::assertSame('failed', $chunk->fresh()->status);
        self::assertSame(3, $chunk->fresh()->attempts);
        self::assertSame(3, DocumentAiRun::where('chunk_id', $chunk->id)->count());
    }

    public function test_deterministic_error_does_not_retry(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::response([], 400)]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        $this->executeChunk($chunk);
        Http::assertSentCount(2);
        self::assertSame('failed', $chunk->fresh()->status);
    }

    public function test_budget_exhaustion_preserves_completed_work(): void
    {
        $document = $this->document();
        $chunk = $this->plan($document);
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => 0]])->save();
        $this->executeChunk($chunk);
        Http::assertNothingSent();
        self::assertSame('budget', $chunk->fresh()->status);
    }

    public function test_workspace_mismatch_cannot_call_provider(): void
    {
        $chunk = $this->plan($this->document());
        $other = $this->document();
        $chunk->update(['workspace_id' => $other->workspace_id]);
        $this->executeChunk($chunk);
        Http::assertNothingSent();
    }

    public function test_restart_resumes_pending_chunks_preserving_completed_checkpoints(): void
    {
        $this->fakeProvider();
        $document = $this->document();
        $chunk = $this->plan($document);
        $this->executeChunk($chunk);
        app(IncrementalPipeline::class)->start($document->fresh());
        self::assertSame('completed', $chunk->fresh()->status);
        $this->executeChunk($chunk);
        Http::assertSentCount(2);
    }

    public function test_malformed_provider_data_fails_safely(): void
    {
        $this->fakeProvider($this->response(['records' => [['kind' => 'metric']]]));
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('failed', $chunk->fresh()->status);
        self::assertNull($chunk->fresh()->result);
    }

    public function test_success_telemetry_contains_tokens_cache_and_cost(): void
    {
        $this->fakeProvider();
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        $run = DocumentAiRun::where('chunk_id', $chunk->id)->sole();
        self::assertSame(100, $run->input_tokens);
        self::assertSame(10, $run->cache_read_tokens);
        self::assertSame(15, $run->cache_creation_tokens);
        self::assertGreaterThan(0, $run->estimated_cost_usd);
        self::assertNotNull($run->duration_ms);
    }

    public function test_failure_telemetry_contains_classification_without_credentials(): void
    {
        config(['services.anthropic.api_key' => 'test-secret-never-store']);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::response(['error' => ['message' => 'private contents']], 400)]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        $run = DocumentAiRun::where('chunk_id', $chunk->id)->sole();
        self::assertSame('deterministic', $run->failure_class);
        self::assertStringNotContainsString('test-secret', $run->toJson());
        self::assertStringNotContainsString('private contents', $run->toJson());
    }

    public function test_metric_identity_preserves_different_observations_and_source_mapping(): void
    {
        $document = $this->document('Revenue increased to USD 10 in 2024. Revenue increased to USD 20 in 2025.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record(), $this->record(),
            $this->record(['value' => '20', 'period' => '2025', 'quote' => 'Revenue increased to USD 20 in 2025.'])]]]);
        app(EvidenceMerger::class)->merge($document);
        app(EvidenceMerger::class)->merge($document);
        self::assertSame(2, $document->kpis()->count());
        self::assertSame(1, $document->kpis()->distinct()->count('kpi_definition_id'));
        self::assertSame(2, DocumentEvidence::count());
        foreach (DocumentEvidence::all() as $evidence) {
            $source = $evidence->sources[0];
            self::assertSame($evidence->data['quote'], mb_substr($document->extracted_text, $source['start_offset'], $source['end_offset'] - $source['start_offset']));
            self::assertStringStartsWith('kpi:', $evidence->source_id);
        }
    }

    public function test_entities_normalize_safely_without_similarity_merging(): void
    {
        $merger = app(EvidenceMerger::class);
        $a = $this->record(['kind' => 'entity', 'value' => 'ACME, Ltd.', 'entity_type' => 'organization']);
        self::assertSame($merger->identity($a), $merger->identity([...$a, 'value' => 'acme ltd']));
        self::assertNotSame($merger->identity($a), $merger->identity([...$a, 'value' => 'Acme Foundation']));
    }

    public function test_unresolved_reference_retrieves_nearby_and_distant_candidates(): void
    {
        $reference = new DocumentEvidence(['kind' => 'unresolved', 'data' => $this->record(['quote' => 'This initiative expands clean energy.']), 'sources' => [['start_offset' => 100]]]);
        $candidate = new DocumentEvidence(['kind' => 'fact', 'data' => $this->record(['label' => 'Clean energy program', 'value' => 'Clean energy initiative']), 'sources' => [['start_offset' => 90000]]]);
        $reference->id = 'reference';
        $candidate->id = 'target';
        self::assertSame('target', app(ContextResolver::class)->candidates($reference, [$candidate])[0]['id']);
    }

    public function test_invalid_quote_is_rejected_before_merge(): void
    {
        $this->expectException(AiProcessingException::class);
        EvidenceSchema::validate(['records' => [$this->record()]], 'Unrelated source.');
    }

    public function test_visual_timeout_is_partial_and_preserves_ready_document(): void
    {
        Storage::fake('documents');
        $document = $this->document();
        $unit = $this->plan($document);
        $document->update(['status' => 'Ready']);
        $unit->update(['stage' => 'visual', 'status' => 'running', 'result' => ['path' => 'temporary-visual']]);
        (new ProcessDocumentVisualJob($unit->id))->failed(new \RuntimeException('timeout'));
        self::assertSame('Ready', $document->fresh()->status);
        self::assertSame('visual_timeout', $unit->fresh()->failure_class);
        self::assertTrue(app(DocumentIntelligenceService::class)->processingDetails($document)['partial']);
    }

    public function test_evidence_budget_trims_whole_records_retaining_sources(): void
    {
        $document = $this->document('Revenue increased to USD 10 in 2024.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        app(EvidenceMerger::class)->merge($document);
        config(['document_intelligence.synthesis_token_budget' => 1]);
        $data = app(EvidenceBudget::class)->forDocument($document);
        self::assertSame(1, $data['coverage']['evidence_omitted']);
        self::assertFalse($data['coverage']['comprehensive']);
        self::assertSame(1, DocumentEvidence::count());
    }

    private function coreSummary(): array
    {
        return ['executive_summary' => 'Revenue increased.', 'key_findings' => ['Revenue increased.'],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [], 'recommended_attention' => []];
    }

    public function test_valid_synthesis_keeps_siblings_and_drops_invalid_trend_and_tension(): void
    {
        $validator = app(ResponseValidator::class);
        $valid = ['observation' => 'Revenue rose.', 'significance' => 'Growth.', 'source_ids' => ['kpi:1']];
        $invalid = [...$valid, 'source_ids' => ['kpi:foreign']];
        $result = $validator->validateSummary($this->coreSummary() + ['trends' => [$valid, $invalid], 'tensions' => [$invalid, $valid]], ['kpi:1']);
        self::assertSame([$valid], $result['trends']);
        self::assertSame([$valid], $result['tensions']);
        self::assertSame(['trends' => 1, 'tensions' => 1], $result['_optional_items_dropped']);
        self::assertSame('Revenue increased.', $result['executive_summary']);
    }

    public function test_required_summary_fields_still_fail_safely(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ResponseValidator::class)->validateSummary([...$this->coreSummary(), 'executive_summary' => '']);
    }

    public function test_merge_to_synthesis_retains_sources_and_reuses_checkpoint(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $document = $this->document('Revenue increased to USD 10 in 2024.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        app(IncrementalPipeline::class)->pump($document->id);
        Bus::assertDispatchedTimes(MergeDocumentEvidenceJob::class, 1);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        (new MergeDocumentEvidenceJob($merge->id))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame('Ready', $document->fresh()->status);
        $sourceId = DocumentEvidence::sole()->source_id;
        Http::fake(['*/messages' => Http::response($this->response($this->coreSummary() + [
            'trends' => [['observation' => 'Revenue rose.', 'significance' => 'Growth.', 'source_ids' => [$sourceId]]]]))]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Http::assertSentCount(1);
        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Http::assertSentCount(1);
        self::assertSame([$sourceId], $document->fresh()->intelligenceSummary->trends[0]['source_ids']);
    }

    public function test_required_field_repair_does_not_regenerate_valid_optional_siblings(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $document = $this->document();
        $this->plan($document);
        $source = ['entities' => [], 'risks' => [], 'deadlines' => [], 'kpis' => [['id' => 'kpi:1', 'value' => '10']]];
        $trend = ['observation' => 'Revenue rose.', 'significance' => 'Growth.', 'source_ids' => ['kpi:1']];
        Http::fake(['*/messages' => Http::sequence()->push($this->response([...$this->coreSummary(), 'executive_summary' => '', 'trends' => [$trend]]))
            ->push($this->response($this->coreSummary()))]);
        $result = app(AnthropicClient::class)->generateDocumentSummary(json_encode($source), $document->name, $document);
        self::assertSame([$trend], $result['trends']);
        Http::assertSentCount(2);
    }

    public function test_synthesis_context_overflow_reduces_evidence_without_restarting_extraction(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $document = $this->document('Revenue increased to USD 10 in 2024.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        app(EvidenceMerger::class)->merge($document);
        $document->update(['status' => 'Ready']);
        Http::fake(['*/messages' => Http::response(['error' => ['message' => 'context too long']], 400)]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        self::assertSame(1, $document->fresh()->ai_pipeline['synthesis_reductions']);
        self::assertSame('completed', $chunk->fresh()->status);
        Bus::assertDispatchedTimes(GenerateDocumentSummaryJob::class, 1);
        Http::assertSentCount(1);
    }

    public function test_summary_transient_retry_records_attempt_and_clears_recovered_partial_state(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $document = $this->document('Revenue increased to USD 10 in 2024.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [$this->record()]]]);
        app(EvidenceMerger::class)->merge($document);
        $document->update(['status' => 'Ready']);
        Http::fake(['*/messages' => Http::sequence()->push([], 503)->push($this->response($this->coreSummary()))]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Http::assertSentCount(2);
        self::assertSame(2, DocumentAiRun::where('status', 'success')->sole()->request_attempt);
        self::assertFalse($document->fresh()->ai_pipeline['partial']);
    }

    public function test_benchmark_exports_observed_metrics_without_provider_requests(): void
    {
        $document = $this->document();
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'completed_at' => now(), 'attempts' => 1]);
        $this->artisan('docintel:benchmark', ['document' => $document->id])->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_decimal_punctuation_does_not_collapse_different_observations(): void
    {
        $merger = app(EvidenceMerger::class);
        self::assertNotSame($merger->identity($this->record(['value' => '1.2'])), $merger->identity($this->record(['value' => '12'])));
    }

    public function test_unsupported_optional_visuals_settle_without_provider_work(): void
    {
        $document = $this->document();
        $this->plan($document);
        $document->update(['status' => 'Ready', 'type' => 'XLSX']);
        app()->call([(new AnalyzeEmbeddedVisualsJob($document->id)), 'handle']);
        self::assertSame('skipped', DocumentChunk::where('stage', 'visual_plan')->sole()->status);
        self::assertSame('Ready', $document->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_normal_summary_budget_keeps_complete_source_records(): void
    {
        config(['document_intelligence.synthesis_token_budget' => 100]);
        $data = app(EvidenceBudget::class)->trimNormal(['deadlines' => [['id' => 'deadline:1', 'title' => 'Due']],
            'entities' => [['id' => 'entity:1', 'value' => str_repeat('A', 100)]]]);
        self::assertSame('deadline:1', $data['deadlines'][0]['id']);
        self::assertSame([], $data['entities']);
        self::assertSame(1, $data['coverage']['evidence_omitted']);
        self::assertSame(0, DocumentChunk::count());
    }

    public function test_malformed_visual_response_logs_usage_as_failure(): void
    {
        $document = $this->document();
        $this->fakeProvider($this->response(['wrong' => 'shape']));
        try {
            app(AnthropicClient::class)->extractChartDataFromImage(base64_encode('synthetic'), 'image/png', $document);
            self::fail('Expected malformed visual output to fail validation.');
        } catch (\RuntimeException) {
            $run = DocumentAiRun::sole();
            self::assertSame('invalid_schema', $run->status);
            self::assertSame(100, $run->input_tokens);
            self::assertGreaterThan(0, $run->estimated_cost_usd);
        }
    }

    public function test_explicit_alias_merges_while_preserving_both_source_locations(): void
    {
        $text = 'International Bank (IB) published a report. IB approved the plan.';
        $document = $this->document($text);
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [
            $this->record(['kind' => 'entity', 'entity_type' => 'organization', 'value' => 'International Bank', 'aliases' => ['IB'], 'quote' => 'International Bank (IB) published a report.']),
            $this->record(['kind' => 'entity', 'entity_type' => 'organization', 'value' => 'IB', 'quote' => 'IB approved the plan.'])]]]);
        app(EvidenceMerger::class)->merge($document);
        self::assertSame(1, $document->entities()->count());
        self::assertCount(2, DocumentEvidence::sole()->sources);
    }

    public function test_cross_chunk_reference_makes_one_targeted_resolution_call(): void
    {
        $document = $this->document('The clean energy program launched. This initiative expands clean energy.');
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'completed', 'result' => ['records' => [
            $this->record(['kind' => 'fact', 'label' => 'Clean energy program', 'value' => 'Program launched', 'quote' => 'The clean energy program launched.']),
            $this->record(['kind' => 'unresolved', 'label' => 'Initiative reference', 'value' => '', 'reference' => 'This initiative', 'quote' => 'This initiative expands clean energy.'])]]]);
        app(EvidenceMerger::class)->merge($document);
        $ref = DocumentEvidence::where('kind', 'unresolved')->sole();
        $target = DocumentEvidence::where('kind', 'fact')->sole();
        Http::fake(['*/messages' => Http::response($this->response(['resolutions' => [['reference_id' => $ref->id, 'target_id' => $target->id, 'confidence' => 0.99]]]))]);
        app(ContextResolver::class)->resolve($document);
        app(ContextResolver::class)->resolve($document);
        Http::assertSentCount(1);
        self::assertSame($target->id, $ref->fresh()->data['resolved_evidence_id']);
    }

    public function test_worker_interruption_does_not_reissue_ambiguous_provider_request(): void
    {
        $document = $this->document();
        $chunk = $this->plan($document);
        $chunk->update(['status' => 'running', 'started_at' => now()->subMinutes(8)]);
        app(IncrementalPipeline::class)->recover($document);
        self::assertSame('uncertain', $chunk->fresh()->status);
        $this->executeChunk($chunk);
        Http::assertNothingSent();
    }

    public function test_provider_context_overflow_splits_input_before_any_identical_retry(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]),
            '*/messages' => Http::response(['error' => ['message' => 'context too long']], 400)]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('split', $chunk->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_actual_token_overflow_splits_before_paid_generation(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 200])]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('split', $chunk->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_ocr_resume_uses_durable_page_assets_and_skips_completed_pages(): void
    {
        Storage::fake('documents');
        $document = $this->document();
        $document->ocrResults()->create(['workspace_id' => $document->workspace_id, 'page_number' => 1,
            'engine' => 'printed', 'raw_text' => 'Already extracted', 'confidence' => 0.9]);
        Storage::disk('documents')->put('ai-ocr/first.png', 'first image');
        Storage::disk('documents')->put('ai-ocr/second.png', 'second image');
        $temporaryPath = null;
        $provider = \Mockery::mock(OcrProviderInterface::class);
        $provider->shouldReceive('extractPage')->once()->andReturnUsing(function ($path) use (&$temporaryPath) {
            $temporaryPath = $path;
            self::assertSame('second image', file_get_contents($path));

            return new OcrPageResult('New text', 0.9);
        });
        $provider->shouldReceive('engine')->once()->andReturn('printed');
        $resolver = $this->mock(OcrEngineResolver::class);
        $resolver->shouldReceive('resolve')->twice()->andReturn($provider);
        $job = new OcrPageBatchJob($document->id, ['ai-ocr/first.png', 'ai-ocr/second.png'], 1, false, storedPageImages: true);
        $job->handle($resolver, app(PipelineStageRecorder::class));
        $job->handle($resolver, app(PipelineStageRecorder::class));
        self::assertSame(2, $document->ocrResults()->count());
        self::assertFileDoesNotExist($temporaryPath);
        Storage::disk('documents')->assertMissing('ai-ocr/first.png');
        Storage::disk('documents')->assertMissing('ai-ocr/second.png');
    }

    public function test_503_does_not_trigger_unbounded_transport_retries(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::response([], 503)]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('queued', $chunk->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_normal_routing_is_cached_and_never_recounts_unchanged_text(): void
    {
        $document = $this->document(str_repeat('Some text ', 20));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50])]);
        self::assertFalse(app(IncrementalPipeline::class)->route($document));
        self::assertFalse(app(IncrementalPipeline::class)->route($document->fresh()));
        Http::assertSentCount(1);
        self::assertSame(0, DocumentChunk::count());
    }

    public function test_kpi_target_and_actual_are_not_deduplicated(): void
    {
        $merger = app(EvidenceMerger::class);
        self::assertNotSame($merger->identity($this->record(['value_basis' => 'actual'])),
            $merger->identity($this->record(['value_basis' => 'target'])));
    }

    public function test_same_date_does_not_merge_distinct_obligations(): void
    {
        $merger = app(EvidenceMerger::class);
        $record = $this->record(['kind' => 'obligation', 'date_type' => 'explicit', 'due_date' => '2026-12-31']);
        self::assertNotSame($merger->identity([...$record, 'label' => 'Submit accounts']),
            $merger->identity([...$record, 'label' => 'Renew insurance']));
    }

    public function test_connection_timeout_splits_input_without_identical_retries(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('split', $chunk->fresh()->status);
        self::assertSame(1, $chunk->fresh()->attempts);
        self::assertSame('timeout', DocumentAiRun::where('chunk_id', $chunk->id)->sole()->failure_class);
    }

    public function test_missing_optional_summary_fields_do_not_discard_valid_core(): void
    {
        $result = app(ResponseValidator::class)->validateSummary(['executive_summary' => 'Revenue rose.', 'key_findings' => ['Revenue rose.', null]]);
        self::assertSame(['Revenue rose.'], $result['key_findings']);
        self::assertSame([], $result['critical_risks']);
    }

    public function test_network_connection_failure_uses_bounded_transient_retry(): void
    {
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]), '*/messages' => Http::failedConnection('Could not resolve host')]);
        $chunk = $this->plan($this->document());
        $this->executeChunk($chunk);
        self::assertSame('queued', $chunk->fresh()->status);
        self::assertSame('transient', DocumentAiRun::where('chunk_id', $chunk->id)->sole()->failure_class);
    }
}
