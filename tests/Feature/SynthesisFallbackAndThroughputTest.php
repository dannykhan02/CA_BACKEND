<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentResource;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\SynthesisCheckpoint;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Synthesis fallback ladder, synthesis budget recovery, record-limited extraction,
 * density-aware planning, bounded recursion, concurrency caps and monotonic progress.
 */
class SynthesisFallbackAndThroughputTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Revenue increased to USD 10 in 2024.';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-4-6',
            'document_intelligence.large_tokens' => 100, 'document_intelligence.budget_base_usd' => 2]);
    }

    private function document(?string $text = null, string $type = 'PDF'): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        // Long prose around one evidence sentence, so each ladder level visibly shrinks the source.
        $text ??= str_repeat("Programme delivery continued across partner countries with measured results.\n\n", 60)
            .self::QUOTE."\n\n".str_repeat("Operating costs were stable and staffing levels remained unchanged.\n\n", 60);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Sector report.pdf', 'type' => $type, 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'fallback'.$user->id),
            'extracted_text' => $text]);
    }

    private function record(array $extra = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company',
            'quote' => self::QUOTE, 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => []], $extra);
    }

    private function summary(): array
    {
        return ['executive_summary' => 'Revenue increased.', 'key_findings' => ['Revenue increased.'],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [], 'recommended_attention' => []];
    }

    private function response(array $data, string $model = 'claude-sonnet-4-6', int $input = 100, int $output = 20): array
    {
        return ['model' => $model, 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
            'content' => [['type' => 'text', 'text' => json_encode($data)]]];
    }

    /** Extraction finished and merged: the document is waiting for synthesis. */
    private function merged(?Document $document = null): Document
    {
        $document ??= $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 1500, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        $document->refresh();
        $chunks = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->get();
        foreach ($chunks as $chunk) {
            $slice = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
            $chunk->update(['status' => 'completed', 'attempts' => 1,
                'result' => ['records' => str_contains($slice, self::QUOTE) ? [$this->record()] : []]]);
        }
        app(EvidenceMerger::class)->merge($document->fresh());

        return $document->fresh();
    }

    private function runSynthesis(Document $document, bool $redelivery = false): void
    {
        (new GenerateDocumentSummaryJob($document->id, $redelivery))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
    }

    private function synthesisUnits(Document $document)
    {
        return DocumentChunk::where('document_id', $document->id)->where('stage', 'synthesis')->orderBy('created_at')->orderBy('id')->get();
    }

    private function prompt($request): string
    {
        return json_decode($request->body(), true)['messages'][0]['content'];
    }

    /** Source context sent in the Nth provider request (decoded from the rendered prompt). */
    private function sentSourceModes(): array
    {
        $modes = [];
        foreach (Http::recorded() as [$request]) {
            if (! str_ends_with($request->url(), '/messages')) {
                continue;
            }
            $prompt = $this->prompt($request);
            $modes[] = match (true) {
                str_contains($prompt, '"coverage":"full"') => 'full',
                str_contains($prompt, '"coverage":"excerpts"') => 'excerpts',
                default => 'omitted',
            };
        }

        return $modes;
    }

    private function committed(Document $document): float
    {
        return (float) DocumentChunk::where('document_id', $document->id)->sum('reserved_cost');
    }

    // SYNTHESIS FALLBACK LADDER

    public function test_timeout_on_full_context_then_success_on_reduced_context(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary()))]);

        $this->runSynthesis($document);
        $document->refresh();
        self::assertSame('Processing', $document->status);
        self::assertSame(1, $document->ai_pipeline['synthesis_reductions']);
        self::assertEquals([['from' => 0, 'to' => 1, 'reason' => 'timeout']], $document->ai_pipeline['synthesis_degradations']);
        self::assertSame('synthesis_retry', $document->ai_pipeline['progress_stage']['key']);
        Bus::assertDispatchedTimes(GenerateDocumentSummaryJob::class, 1);

        $this->runSynthesis($document, true);
        $document->refresh();
        self::assertSame('Ready', $document->status);
        self::assertSame(['full', 'excerpts'], $this->sentSourceModes());
        $units = $this->synthesisUnits($document);
        self::assertSame(['superseded', 'completed'], $units->pluck('status')->all());
        self::assertSame([0, 1], $units->map(fn ($u) => $u->cost_accounting['synthesis_level'])->all());
        // Same validated evidence at both levels: only source context changed.
        $evidenceId = DocumentEvidence::where('document_id', $document->id)->sole()->source_id;
        foreach (Http::recorded() as [$request]) {
            self::assertStringContainsString(json_encode($evidenceId), $this->prompt($request));
        }
    }

    public function test_two_timeouts_then_success_with_evidence_excerpts_only(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->pushFailedConnection('cURL error 28: Operation timed out')->push($this->response($this->summary()))]);
        $this->runSynthesis($document);
        $this->runSynthesis($document, true);
        $this->runSynthesis($document, true);
        self::assertSame('Ready', $document->fresh()->status);
        self::assertSame(['full', 'excerpts', 'excerpts'], $this->sentSourceModes());
        // Each level carries materially less source context than the one before.
        $sizes = collect(Http::recorded())->map(fn ($pair) => strlen($this->prompt($pair[0])))->all();
        self::assertLessThan($sizes[0] / 2 + 4096, $sizes[1]);
        self::assertLessThan($sizes[1], $sizes[2]);
        self::assertSame(2, $this->synthesisUnits($document)->last()->cost_accounting['synthesis_level']);
    }

    public function test_evidence_only_fallback_succeeds_after_three_timeouts(): void
    {
        $document = $this->merged();
        $sequence = Http::sequence();
        foreach (range(1, 3) as $_) {
            $sequence->pushFailedConnection('cURL error 28: Operation timed out');
        }
        Http::fake(['*/messages' => $sequence->push($this->response($this->summary()))]);
        foreach (range(1, 4) as $attempt) {
            $this->runSynthesis($document, $attempt > 1);
        }
        $document->refresh();
        self::assertSame('Ready', $document->status);
        self::assertSame(['full', 'excerpts', 'excerpts', 'omitted'], $this->sentSourceModes());
        self::assertSame(3, $document->ai_pipeline['synthesis_reductions']);
        self::assertNull($document->error_message);
    }

    public function test_all_fallbacks_fail_with_a_short_accurate_reason(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::failedConnection('cURL error 28: Operation timed out')]);
        foreach (range(1, 4) as $attempt) {
            $this->runSynthesis($document, $attempt > 1);
        }
        $document->refresh();
        self::assertSame('Needs Review', $document->status);
        self::assertSame('timeout_exhausted', $document->ai_pipeline['synthesis_failure_reason']);
        self::assertSame('The final summary could not be completed, even with reduced document context. Your extracted evidence is preserved.',
            $document->error_message);
        self::assertStringNotContainsString('cURL', $document->error_message);
        Bus::assertDispatchedTimes(GenerateDocumentSummaryJob::class, 3); // No retry after the last level.
        Http::assertSentCount(4);
        self::assertLessThanOrEqual($document->ai_pipeline['budget_usd'], $this->committed($document));
        // Evidence survives.
        self::assertSame(1, DocumentEvidence::where('document_id', $document->id)->count());
    }

    public function test_deterministic_failures_never_descend_the_ladder(): void
    {
        Http::fake(['*/messages' => Http::sequence()->push(['error' => ['message' => 'invalid x-api-key']], 401)
            ->push(['error' => ['message' => 'model not found']], 404)]);
        foreach (['authentication', 'invalid_model'] as $class) {
            $document = $this->merged();
            try {
                $this->runSynthesis($document);
            } catch (\Throwable) {
            }
            $document->refresh();
            self::assertSame('Needs Review', $document->status, $class);
            self::assertSame(0, $document->ai_pipeline['synthesis_reductions'] ?? 0);
            self::assertSame($class, $this->synthesisUnits($document)->sole()->failure_class);
        }
        Bus::assertNotDispatched(GenerateDocumentSummaryJob::class);
    }

    // SYNTHESIS BUDGET

    public function test_initial_budget_holds_primary_degraded_and_repair_reserves(): void
    {
        $document = $this->merged();
        $pipeline = $document->ai_pipeline;
        self::assertGreaterThan(0, $pipeline['synthesis_degraded_reserved_usd']);
        self::assertLessThanOrEqual($pipeline['synthesis_reserved_usd'], $pipeline['synthesis_degraded_reserved_usd']);
        // Extraction may not spend the recovery headroom.
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->first();
        $hold = $pipeline['synthesis_reserved_usd'] + $pipeline['synthesis_degraded_reserved_usd'] + $pipeline['repair_reserved_usd'];
        $chunk->update(['reserved_cost' => $pipeline['budget_usd'] - $hold - $this->committed($document) + (float) $chunk->reserved_cost]);
        self::assertFalse(app(IncrementalPipeline::class)->canReserve($document->fresh(), 0.01));
        self::assertTrue(app(IncrementalPipeline::class)->canReserve($document->fresh(), 0.01, synthesis: true));
    }

    public function test_timeout_with_unknown_usage_keeps_synthesis_bound_but_not_unsent_repair(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary(), input: 2000, output: 500))]);
        $this->runSynthesis($document);
        $failed = $this->synthesisUnits($document)->first();
        [$synthesis, $repair] = $failed->cost_accounting['components'];
        self::assertGreaterThan(0, $repair);
        self::assertFalse($failed->cost_accounting['actual_known']);
        // Conservative for the request that was sent; nothing for the repair that never was.
        self::assertSame(round($synthesis, 6), (float) $failed->reserved_cost);
        self::assertSame(round($synthesis, 6), $failed->cost_accounting['cost']);

        // Known usage settles the fallback at actual cost: Sonnet 4.6 at $3 / $15 per million.
        $this->runSynthesis($document, true);
        $success = $this->synthesisUnits($document)->last();
        self::assertTrue($success->cost_accounting['actual_known']);
        self::assertSame(round((2000 * 3 + 500 * 15) / 1000000, 6), (float) $success->reserved_cost);
        self::assertLessThan($success->cost_accounting['estimate'], (float) $success->reserved_cost);
    }

    public function test_budget_degrades_before_spending_and_never_exceeds_the_cap(): void
    {
        $document = $this->merged();
        $pipeline = $document->ai_pipeline;
        $extraction = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->first();
        // Leave room for a level-1 attempt plus repair, but not level 0 with its fallback held.
        $level1 = Document::find($document->id);
        $level1->ai_pipeline = [...$document->ai_pipeline, 'synthesis_reductions' => 1];
        $data1 = app(AnthropicClient::class)->synthesisReservation($level1, app(EvidenceBudget::class)->forSynthesis($level1), 1);
        $room = $data1['synthesis_reserved_usd'] + $data1['repair_reserved_usd'] + $data1['synthesis_degraded_reserved_usd'] + 0.0001;
        $extraction->update(['reserved_cost' => $pipeline['budget_usd'] - $room - ($this->committed($document) - (float) $extraction->reserved_cost)]);
        Http::fake(['*/messages' => Http::response($this->response($this->summary()))]);
        $this->runSynthesis($document);
        $document->refresh();
        self::assertSame('Ready', $document->status);
        self::assertEquals([['from' => 0, 'to' => 1, 'reason' => 'budget']], $document->ai_pipeline['synthesis_degradations']);
        self::assertSame(['excerpts'], $this->sentSourceModes()); // Level 0 was never sent.
        self::assertSame(['superseded', 'completed'], $this->synthesisUnits($document)->pluck('status')->all());
        self::assertLessThanOrEqual($pipeline['budget_usd'], $this->committed($document));
    }

    public function test_timeout_then_insufficient_budget_names_both_causes(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $this->runSynthesis($document);
        // Something else consumed the remaining budget before the fallback could run.
        $extraction = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->first();
        $extraction->update(['reserved_cost' => (float) $extraction->reserved_cost
            + $document->fresh()->ai_pipeline['budget_usd'] - $this->committed($document)]);
        $this->runSynthesis($document, true);
        $document->refresh();
        self::assertSame('Needs Review', $document->status);
        self::assertSame('timeout_then_budget', $document->ai_pipeline['synthesis_failure_reason']);
        self::assertSame('Final synthesis timed out and could not be retried within the remaining AI budget. Your extracted evidence is preserved.',
            $document->error_message);
        Http::assertSentCount(1);
        self::assertLessThanOrEqual($document->ai_pipeline['budget_usd'], round($this->committed($document), 6));
    }

    public function test_duplicate_synthesis_delivery_neither_calls_nor_bills_twice_and_debits_once(): void
    {
        $document = $this->merged();
        $credits = fn () => $document->workspace->credits()->first()->documents_remaining;
        $debit = fn () => DB::transaction(function () use ($document) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->first();
            app(WorkspaceCreditService::class)->accountForReadyDocument($locked);
            $locked->save();
        });
        $debit();
        $afterDebit = $credits();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary()))]);
        $this->runSynthesis($document);
        // The fallback job is delivered twice; the second finds the claimed/completed checkpoint.
        $this->runSynthesis($document, true);
        $spent = $this->committed($document);
        $this->runSynthesis($document, true);
        $this->runSynthesis($document);
        Http::assertSentCount(2);
        self::assertSame($spent, $this->committed($document));
        self::assertSame(1, $this->synthesisUnits($document)->where('status', 'completed')->count());
        $debit();
        self::assertSame($afterDebit, $credits());
    }

    public function test_worker_interruption_then_reanalysis_restarts_the_ladder_cleanly(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary()))]);
        $this->runSynthesis($document);
        // The fallback worker dies mid-request.
        $unit = app(SynthesisCheckpoint::class)->claim($document->fresh());
        self::assertSame('running', $unit->status);
        (new GenerateDocumentSummaryJob($document->id, true))->failed(new \RuntimeException('worker lost'));
        self::assertSame('uncertain', $unit->fresh()->status);
        self::assertSame('Needs Review', $document->fresh()->status);

        app(IncrementalPipeline::class)->reanalyze($document->fresh(), $document->uploaded_by, summaryOnly: true, dispatch: false);
        $document->refresh();
        self::assertSame(0, $document->ai_pipeline['synthesis_reductions']);
        self::assertSame([], $document->ai_pipeline['synthesis_degradations']);
        self::assertSame(0, $this->synthesisUnits($document)->whereIn('status', ['running', 'pending'])->count());
        $this->runSynthesis($document, true);
        self::assertSame('Ready', $document->fresh()->status);
        self::assertSame(['full', 'full'], $this->sentSourceModes());
    }

    public function test_configured_synthesis_model_drives_request_pricing_and_diagnostics(): void
    {
        $document = $this->merged();
        Http::fake(['*/messages' => Http::response($this->response($this->summary(), input: 1000, output: 100))]);
        $this->runSynthesis($document);
        Http::assertSent(fn ($request) => $request['model'] === 'claude-sonnet-4-6' && $request['max_tokens'] === 8192
            && $request['output_config']['effort'] === 'medium');
        self::assertSame('claude-sonnet-4-6', $document->fresh()->ai_pipeline['synthesis_model']);
        self::assertSame('claude-sonnet-4-6', DocumentAiRun::where('purpose', 'document_summary')->sole()->model);
        self::assertSame('claude-sonnet-4-6', $document->fresh()->intelligenceSummary->model);
        self::assertSame(round((1000 * 3 + 100 * 15) / 1000000, 6), (float) $this->synthesisUnits($document)->sole()->reserved_cost);
    }

    // EXTRACTION PLANNING AND RECOVERY

    public function test_dense_sixty_two_k_document_plans_few_coarse_roots(): void
    {
        $row = fn ($i) => "County {$i}\t1,2{$i}0,000\t1,3{$i}0,000\t8.{$i}%";
        $table = implode("\n", array_map($row, range(1, 9000)));
        $document = $this->document($table, 'XLSX');
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 62125])]);
        self::assertTrue(app(IncrementalPipeline::class)->route($document));
        $routing = $document->fresh()->ai_pipeline['routing'];
        self::assertSame('coarse', $routing['mode']);
        self::assertTrue($routing['dense']);
        self::assertEquals(6, $routing['records_per_1k_tokens']);
        self::assertGreaterThanOrEqual(4, $routing['root_chunks']);
        self::assertLessThanOrEqual(6, $routing['root_chunks']);
        self::assertSame(app(ExtractionCapacity::class)->recordLimit(), $routing['record_limit']);
    }

    public function test_normal_prose_pdf_routing_is_unchanged(): void
    {
        $capacity = app(ExtractionCapacity::class);
        $prose = str_repeat("The programme expanded its partner network and improved delivery in 2024 across regions.\n\n", 50);
        $density = $capacity->density($prose, 'PDF');
        self::assertFalse($density['dense']);
        $decision = $capacity->decide(62125, $density);
        // Whole-record sizing: 79 records at 4 per 1k tokens (a full slice's ceil'd output stays under 12,000).
        self::assertSame(19750, $decision['partition_tokens']);
        self::assertSame(4, $decision['planned_partitions']);
        self::assertSame('direct', $capacity->decide(15385, $density)['mode']);
        self::assertTrue($capacity->density("a\tb\tc\n1\t2\t3\n", 'PDF')['dense']);
        self::assertTrue($capacity->density('Narrative text only.', 'XLSX')['dense']);
    }

    public function test_extraction_requests_carry_a_record_limit_and_saturation_marks_coverage_incomplete(): void
    {
        $document = $this->document(self::QUOTE."\n\n".str_repeat("Programme delivery continued.\n\n", 10));
        config(['document_intelligence.extraction_max_tokens' => 1000, 'document_intelligence.output_fill_ratio' => 0.75]);
        $limit = app(ExtractionCapacity::class)->recordLimit();
        self::assertSame(intdiv(750 - 64, 150), $limit);
        $records = array_fill(0, $limit, $this->record());
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50]),
            '*/messages' => Http::response($this->response(['records' => $records], 'claude-haiku-4-5-20251001'))]);
        $document->forceFill(['ai_pipeline' => ['tokens' => 1000, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && json_decode($request['messages'][0]['content'], true)['max_records'] === $limit);
        $chunk->refresh();
        self::assertSame('completed', $chunk->status);
        self::assertTrue($chunk->result['_saturated']);
        self::assertSame($limit, $chunk->result['_returned_records']);
        self::assertArrayHasKey('queue_wait_ms', $chunk->cost_accounting);
        self::assertSame(1, $chunk->cost_accounting['document_running']);
        app(EvidenceMerger::class)->merge($document->fresh());
        $coverage = app(EvidenceBudget::class)->forDocument($document->fresh())['coverage'];
        self::assertSame(1, $coverage['saturated_chunks']);
        self::assertFalse($coverage['comprehensive']);
    }

    public function test_recursive_splitting_stops_at_depth_two(): void
    {
        $document = $this->document();
        $document->forceFill(['ai_pipeline' => ['key' => 'depth-key', 'route' => 'incremental', 'tokens' => 3000]])->save();
        self::assertSame(2, config('document_intelligence.max_split_depth'));
        $chunk = DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => 'depth-key', 'identity' => 'chunk:0.0.0', 'input_hash' => 'x', 'pipeline_version' => '1', 'prompt_version' => '2',
            'start_offset' => 0, 'end_offset' => mb_strlen($document->extracted_text), 'depth' => 2, 'status' => 'running', 'failure_class' => 'max_tokens']);
        app(IncrementalPipeline::class)->split($chunk, $document);
        self::assertSame('split_limit', $chunk->fresh()->failure_class);
        self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count());
    }

    public function test_partial_extraction_with_failed_leaves_still_merges_and_synthesizes(): void
    {
        config(['document_intelligence.chunk_max_tokens' => 1500, 'document_intelligence.chunk_overlap_tokens' => 5]);
        $document = $this->merged();
        self::assertGreaterThan(1, DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->count());
        $other = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')
            ->get()->first(fn ($c) => ! str_contains(mb_substr($document->extracted_text, $c->start_offset, $c->end_offset - $c->start_offset), self::QUOTE));
        $other?->update(['status' => 'failed', 'failure_class' => 'split_limit', 'result' => null]);
        Http::fake(['*/messages' => Http::response($this->response($this->summary()))]);
        $this->runSynthesis($document);
        $document->refresh();
        self::assertSame('Ready', $document->status);
        self::assertTrue($document->ai_pipeline['partial']);
        self::assertSame(1, DocumentEvidence::where('document_id', $document->id)->count());
    }

    // CONCURRENCY AND PROGRESS

    public function test_per_document_concurrency_cap_is_configurable_and_respected(): void
    {
        config(['document_intelligence.chunk_max_tokens' => 100, 'document_intelligence.chunk_overlap_tokens' => 5,
            'document_intelligence.concurrency' => 4]);
        $document = $this->document(str_repeat(self::QUOTE."\n\n", 120));
        $document->forceFill(['ai_pipeline' => ['tokens' => 2000, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        self::assertGreaterThan(4, DocumentChunk::where('stage', 'extraction')->count());
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 4);
        app(IncrementalPipeline::class)->pump($document->id);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 4);
        self::assertSame(4, DocumentChunk::where('status', 'queued')->count());
    }

    public function test_progress_never_moves_backwards_when_a_section_splits(): void
    {
        config(['document_intelligence.chunk_max_tokens' => 400, 'document_intelligence.chunk_overlap_tokens' => 5,
            'document_intelligence.minimum_split_chars' => 10, 'document_intelligence.concurrency' => 10]);
        $document = $this->document(str_repeat(self::QUOTE."\n\n", 120));
        $document->forceFill(['ai_pipeline' => ['tokens' => 2000, 'route' => 'incremental'], 'progress' => 50])->save();
        $pipeline = app(IncrementalPipeline::class);
        $pipeline->start($document);
        $roots = DocumentChunk::where('stage', 'extraction')->orderBy('start_offset')->get();
        self::assertGreaterThan(2, $roots->count());
        $seen = [(int) $document->fresh()->progress];
        $roots[0]->update(['status' => 'completed']);
        $pipeline->pump($document->id);
        $seen[] = (int) $document->fresh()->progress;
        // Root 1 hits max_tokens and splits: more leaves, but the percentage must not drop.
        $roots[1]->update(['status' => 'running', 'failure_class' => 'max_tokens']);
        $pipeline->split($roots[1]->fresh(), $document->fresh());
        $pipeline->pump($document->id);
        $seen[] = (int) $document->fresh()->progress;
        $stage = $document->fresh()->ai_pipeline['progress_stage'];
        self::assertSame('recovering', $stage['key']);
        self::assertSame($roots->count(), $stage['total']);
        DocumentChunk::where('parent_id', $roots[1]->id)->update(['status' => 'completed']);
        $pipeline->pump($document->id);
        $seen[] = (int) $document->fresh()->progress;
        self::assertSame($seen, collect($seen)->sort()->values()->all());
        self::assertGreaterThan($seen[1], $seen[3]);
        self::assertSame('Extracting intelligence 3 of '.$roots->count(), $document->fresh()->ai_pipeline['progress_stage']['label']);
        // Stage is exposed to the frontend while processing, alongside the existing integer.
        $resource = (new DocumentResource($document->fresh()))->toArray(request());
        self::assertSame('extracting', $resource['progressStage']['key']);
        self::assertSame($seen[3], $resource['progress']);
    }

    public function test_budget_blocked_chunk_waits_for_running_sibling_then_fails_only_when_nothing_can_release(): void
    {
        config(['document_intelligence.chunk_max_tokens' => 1500, 'document_intelligence.chunk_overlap_tokens' => 5]);
        $document = $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 1500, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        [$first, $second] = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->orderBy('start_offset')->get();
        // The first unit is running with a large outstanding reservation; nothing else fits.
        $pipeline = $document->fresh()->ai_pipeline;
        $first->update(['status' => 'running', 'reserved_cost' => $pipeline['budget_usd'] - $pipeline['synthesis_reserved_usd']
            - $pipeline['synthesis_degraded_reserved_usd'] - $pipeline['repair_reserved_usd']]);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 50])]);
        $second->update(['status' => 'queued']);
        (new ProcessDocumentChunkJob($second->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('pending', $second->fresh()->status); // Deferred, not failed.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));

        // The sibling is gone without releasing anything: now the budget failure is final.
        $first->update(['status' => 'failed']);
        $second->update(['status' => 'queued']);
        (new ProcessDocumentChunkJob($second->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('budget', $second->fresh()->status);
    }

    public function test_extraction_reservation_uses_counted_tokens_not_bytes(): void
    {
        $document = $this->document();
        $document->forceFill(['ai_pipeline' => ['tokens' => 1500, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->sole();
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 1200]),
            '*/messages' => Http::response($this->response(['records' => [$this->record()]], 'claude-haiku-4-5-20251001'))]);
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        $chunk->refresh();
        $slice = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
        self::assertSame(1200, $chunk->cost_accounting['counted_input_tokens']);
        // Smaller than the old byte bound, never smaller than the counted tokens plus escaping.
        $bytes = app(AiPricing::class)->reserve('claude-haiku-4-5-20251001', strlen(json_encode($slice)) + 8000, 16000, cacheWrite: true);
        self::assertLessThan($bytes, $chunk->cost_accounting['estimate']);
        self::assertGreaterThan(round((1200 * 1.25 + 16000 * 5) / 1000000, 6), $chunk->cost_accounting['estimate']);
    }
}
