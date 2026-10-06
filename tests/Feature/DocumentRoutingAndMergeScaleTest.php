<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use App\Services\Kpis\KpiIdentityResolver;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentEntitiesPromptSeeder;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/** Context-aware routing, capacity-derived partitions, merge scalability and synthesis source context. */
class DocumentRoutingAndMergeScaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        // Production model routing and production capacity configuration.
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
    }

    private function document(string $text): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Sector report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'scale'.$user->id),
            'extracted_text' => $text]);
    }

    /** Paragraph/heading structured report text of roughly $bytes bytes. */
    private function report(int $bytes): string
    {
        $text = '';
        for ($section = 1; strlen($text) < $bytes; $section++) {
            $text .= "SECTION {$section} PERFORMANCE REVIEW\n";
            for ($p = 0; $p < 6; $p++) {
                $text .= "Revenue in region {$section}.{$p} increased to USD {$section}{$p} million in 2024 while operating costs were stable. "
                    ."Programme delivery continued across all partner countries with measured results.\n\n";
            }
        }

        return $text;
    }

    private function record(array $extra = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company',
            'quote' => '', 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => []], $extra);
    }

    private function completedChunk(Document $document, string $identity, int $start, int $end, array $records, string $status = 'completed'): DocumentChunk
    {
        return DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'], 'identity' => $identity, 'stage' => 'extraction',
            'input_hash' => hash('sha256', mb_substr($document->extracted_text, $start, $end - $start)),
            'pipeline_version' => '1', 'prompt_version' => '1', 'start_offset' => $start, 'end_offset' => $end,
            'start_page' => 1, 'end_page' => 1, 'status' => $status, 'result' => ['records' => $records]]);
    }

    /**
     * $metrics metric records (KPI labels repeat every $labels), plus facts, entities
     * with an explicit alias, risks and deadlines, all quoting exact source sentences.
     */
    private function evidenceDocument(int $metrics, int $labels = 40): array
    {
        $lines = [];
        $records = [];
        for ($i = 0; $i < $metrics; $i++) {
            $quote = 'Indicator '.($i % $labels)." for Region {$i} reached {$i}0 units in 2024.";
            $lines[] = $quote;
            $records[] = $this->record(['label' => 'Indicator '.($i % $labels), 'subject' => 'Region', 'value' => $i.'0',
                'unit' => 'units', 'quote' => $quote]);
        }
        for ($i = 0; $i < 30; $i++) {
            $lines[] = $quote = "Fact {$i}: the programme expanded its partner network.";
            $records[] = $this->record(['kind' => 'fact', 'label' => "Programme fact {$i}", 'value' => 'Expanded', 'quote' => $quote]);
        }
        $lines[] = 'International Bank (IB) co-financed the programme.';
        $lines[] = 'IB approved the second tranche.';
        $records[] = $this->record(['kind' => 'entity', 'entity_type' => 'organization', 'value' => 'International Bank',
            'aliases' => ['IB'], 'quote' => 'International Bank (IB) co-financed the programme.']);
        $records[] = $this->record(['kind' => 'entity', 'entity_type' => 'organization', 'value' => 'IB', 'quote' => 'IB approved the second tranche.']);
        for ($i = 0; $i < 18; $i++) {
            $lines[] = $quote = "Partner Organisation {$i} delivered services.";
            $records[] = $this->record(['kind' => 'entity', 'entity_type' => 'organization', 'value' => "Partner Organisation {$i}", 'quote' => $quote]);
        }
        for ($i = 0; $i < 10; $i++) {
            $lines[] = $quote = "Risk {$i}: funding volatility may delay delivery.";
            $records[] = $this->record(['kind' => 'risk', 'label' => "Funding risk {$i}", 'value' => 'Delay', 'severity' => 'high', 'quote' => $quote]);
        }
        for ($i = 0; $i < 5; $i++) {
            $lines[] = $quote = "Report {$i} must be submitted by 2026-12-1{$i}.";
            $records[] = $this->record(['kind' => 'deadline', 'label' => "Submit report {$i}", 'value' => 'Submit', 'date_type' => 'explicit',
                'due_date' => "2026-12-1{$i}", 'quote' => $quote]);
        }
        $text = implode("\n", $lines);
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['key' => hash('sha256', 'scale'.$document->id), 'route' => 'incremental',
            'tokens' => 5000, 'budget_usd' => 5]])->save();
        // Two overlapping leaves: overlap duplicates must merge into one evidence row with two sources.
        $half = intdiv(count($records), 2);
        $splitAt = mb_strpos($text, $records[$half]['quote']);
        $overlap = mb_strpos($text, $records[$half - 1]['quote']);
        $this->completedChunk($document, 'chunk:0', 0, $splitAt, array_slice($records, 0, $half));
        $this->completedChunk($document, 'chunk:1', $overlap, mb_strlen($text), array_slice($records, $half - 1));

        return [$document->fresh(), $records];
    }

    private function countQueries(callable $callback, ?array &$statements = null): int
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $callback();
        $count = count($statements);
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }

    // ROUTING

    public function test_small_document_keeps_legacy_path_and_small_extraction_is_direct(): void
    {
        $document = $this->document('Short report.');
        self::assertFalse(app(IncrementalPipeline::class)->route($document));
        self::assertSame('normal', $document->fresh()->ai_pipeline['route']);
        self::assertSame(0, DocumentChunk::count());
        Http::assertNothingSent();
        self::assertSame('direct', app(ExtractionCapacity::class)->decide(2000)['mode']);
    }

    public function test_unicef_sized_document_is_one_direct_extraction(): void
    {
        $document = $this->document($this->report(62000));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385])]);
        self::assertTrue(app(IncrementalPipeline::class)->route($document));
        $pipeline = $document->fresh()->ai_pipeline;
        self::assertSame('incremental', $pipeline['route']);
        self::assertSame('direct', $pipeline['mode']);
        self::assertSame(1, $pipeline['routing']['root_chunks']);
        $chunk = DocumentChunk::where('stage', 'extraction')->sole();
        self::assertSame(0, $chunk->start_offset);
        self::assertSame(mb_strlen($document->extracted_text), $chunk->end_offset);
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, 1);
    }

    public function test_sector_report_fits_context_but_not_one_response_so_it_is_coarse(): void
    {
        $text = $this->report(250000);
        $document = $this->document($text);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 62125])]);
        self::assertTrue(app(IncrementalPipeline::class)->route($document));
        $routing = $document->fresh()->ai_pipeline['routing'];
        self::assertSame('coarse', $routing['mode']);
        self::assertLessThan($routing['input_capacity_tokens'], 62125); // Fits the context...
        self::assertGreaterThan($routing['output_capacity_tokens'], $routing['expected_output_tokens']); // ...not one response.
        $roots = DocumentChunk::where('stage', 'extraction')->whereNull('parent_id')->orderBy('start_offset')->get();
        self::assertSame($routing['root_chunks'], $roots->count());
        self::assertLessThanOrEqual(5, $roots->count());

        // The historical strategy (14k-token windows, 4096 output tokens) on the same input.
        $density = 62125 / strlen($text);
        $old = app(ChunkPlanner::class)->plan($text, 14000, tokensPerByte: $density);
        self::assertGreaterThan($roots->count(), count($old));
        $capacity = app(ExtractionCapacity::class);
        foreach ($old as $chunk) {
            // Every historical root expected more output than its 4096-token cap: truncate, split, cascade.
            self::assertGreaterThan(4096, $capacity->expectedOutputTokens($chunk['token_count']));
        }
        foreach ($roots as $root) {
            self::assertLessThanOrEqual($routing['partition_tokens'], $root->token_count);
            self::assertLessThanOrEqual($routing['output_capacity_tokens'], $capacity->expectedOutputTokens($root->token_count));
            // Partitions end on a section/paragraph boundary, never mid-word (starts include overlap).
            if ($root->end_offset < mb_strlen($text)) {
                self::assertSame("\n", mb_substr($text, $root->end_offset - 1, 1));
            }
        }
    }

    public function test_document_beyond_one_request_context_is_deep(): void
    {
        $decision = app(ExtractionCapacity::class)->decide(300000);
        self::assertSame('deep', $decision['mode']);
        self::assertGreaterThan($decision['input_capacity_tokens'], 300000);
        self::assertGreaterThanOrEqual(15, $decision['planned_partitions']);
    }

    public function test_routing_follows_configured_model_capabilities_not_a_fixed_threshold(): void
    {
        $capacity = app(ExtractionCapacity::class);
        self::assertSame('coarse', $capacity->decide(62125)['mode']);
        config(['document_intelligence.model_capabilities.claude-haiku-4-5-20251001' => ['context_window' => 60000, 'max_output_tokens' => 64000]]);
        self::assertSame('deep', $capacity->decide(62125)['mode']);
        // A smaller output cap shrinks partitions and the direct window.
        config(['document_intelligence.model_capabilities.claude-haiku-4-5-20251001' => ['context_window' => 200000, 'max_output_tokens' => 4096]]);
        self::assertSame('coarse', $capacity->decide(15385)['mode']);
        config(['document_intelligence.extraction_max_tokens' => 64000,
            'document_intelligence.model_capabilities.claude-haiku-4-5-20251001' => ['context_window' => 200000, 'max_output_tokens' => 64000]]);
        self::assertSame('direct', $capacity->decide(62125)['mode']);
    }

    public function test_prompt_schema_output_and_safety_margin_are_all_reserved(): void
    {
        $capacity = app(ExtractionCapacity::class);
        $decision = $capacity->decide(1000);
        self::assertGreaterThanOrEqual(strlen(EvidenceSchema::instructions()) + strlen(json_encode(EvidenceSchema::extraction())),
            $decision['prompt_overhead_tokens']);
        self::assertSame(20000, $decision['safety_margin_tokens']);
        self::assertSame(16000, $decision['max_output_tokens']);
        self::assertSame($decision['context_window'] - $decision['max_output_tokens'] - $decision['prompt_overhead_tokens']
            - $decision['safety_margin_tokens'], $decision['input_capacity_tokens']);
        // Fits the raw context window, but would eat into the safety margin: not one request.
        $tokens = $decision['input_capacity_tokens'] + 10;
        self::assertLessThan($decision['context_window'], $tokens + $decision['prompt_overhead_tokens'] + $decision['max_output_tokens']);
        self::assertSame('deep', $capacity->decide($tokens)['mode']);
    }

    public function test_direct_route_obeys_document_budget_and_synthesis_hold(): void
    {
        $document = $this->document($this->report(62000));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385])]);
        app(IncrementalPipeline::class)->route($document);
        $document->refresh();
        self::assertSame('direct', $document->ai_pipeline['mode']);
        self::assertGreaterThan(0, $document->ai_pipeline['synthesis_reserved_usd']);
        self::assertGreaterThan(0, $document->ai_pipeline['repair_reserved_usd']);
        $chunk = DocumentChunk::where('stage', 'extraction')->sole();
        // Leave only the synthesis + repair hold: the direct extraction may not spend it.
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => $document->ai_pipeline['synthesis_reserved_usd']
            + $document->ai_pipeline['repair_reserved_usd'] + 0.001]])->save();
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('budget', $chunk->fresh()->status);
        self::assertSame('budget_exceeded', $chunk->fresh()->failure_class);
        self::assertSame(0, DocumentChunk::whereNotNull('parent_id')->count());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_direct_extraction_uses_haiku_with_capacity_output_bound(): void
    {
        $document = $this->document($this->report(62000));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385]),
            '*/messages' => Http::response(['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 15000, 'output_tokens' => 200], 'content' => [['type' => 'text', 'text' => json_encode(['records' => []])]]])]);
        app(IncrementalPipeline::class)->route($document);
        $chunk = DocumentChunk::where('stage', 'extraction')->sole();
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        self::assertSame('completed', $chunk->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && $request['model'] === 'claude-haiku-4-5-20251001' && $request['max_tokens'] === 16000);
        self::assertLessThan($chunk->fresh()->cost_accounting['estimate'], (float) $chunk->fresh()->reserved_cost);
    }

    // CHUNKING

    public function test_only_capacity_failures_split_and_depth_and_child_size_are_bounded(): void
    {
        $text = $this->report(12000);
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['key' => 'split-key', 'route' => 'incremental', 'tokens' => 3000]])->save();
        $pipeline = app(IncrementalPipeline::class);
        $make = fn (string $identity, string $failure, int $depth = 0, ?int $end = null) => DocumentChunk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'pipeline_key' => 'split-key',
            'identity' => $identity, 'input_hash' => 'h', 'pipeline_version' => '1', 'prompt_version' => '1',
            'start_offset' => 0, 'end_offset' => $end ?? mb_strlen($text), 'token_count' => 3000, 'depth' => $depth,
            'status' => 'running', 'failure_class' => $failure]);
        foreach (['invalid_evidence', 'invalid_date', 'invalid_schema', 'budget_exceeded', 'authentication', 'billing',
            'invalid_model', 'deterministic', 'unsupported_structured_model'] as $failure) {
            $chunk = $make('terminal-'.$failure, $failure);
            $pipeline->split($chunk, $document);
            self::assertSame('failed', $chunk->fresh()->status, $failure);
            self::assertSame($failure, $chunk->fresh()->failure_class);
            self::assertSame(0, DocumentChunk::where('parent_id', $chunk->id)->count(), $failure);
        }
        foreach (IncrementalPipeline::SPLITTABLE_FAILURES as $failure) {
            $chunk = $make('capacity-'.$failure, $failure);
            $pipeline->split($chunk, $document);
            self::assertSame('split', $chunk->fresh()->status, $failure);
            $children = DocumentChunk::where('parent_id', $chunk->id)->orderBy('start_offset')->get();
            self::assertGreaterThanOrEqual(2, $children->count());
            self::assertSame(1, $children->first()->depth);
            self::assertSame(0, $children->first()->start_offset);
            self::assertSame(mb_strlen($text), $children->last()->end_offset);
        }
        $deep = $make('too-deep', 'max_tokens', (int) config('document_intelligence.max_split_depth'));
        $pipeline->split($deep, $document);
        self::assertSame('split_limit', $deep->fresh()->failure_class);
        // Children would fall below the minimum: degenerate output, not oversize input.
        $small = $make('too-small', 'max_tokens', 0, (int) config('document_intelligence.minimum_split_chars') * 2 - 1);
        $pipeline->split($small, $document);
        self::assertSame('split_limit', $small->fresh()->failure_class);
        self::assertSame(0, DocumentChunk::where('parent_id', $small->id)->count());
    }

    public function test_multibyte_partitions_keep_exact_character_offsets_and_coverage(): void
    {
        $text = '';
        for ($i = 0; $i < 400; $i++) {
            $text .= ($i % 40 === 0 ? "\fTEIL {$i} ÜBERSICHT\n" : '')."Umsatz 漢字 {$i} wuchs um €{$i} 😀 im Jahr 2024.\n\n";
        }
        $chunks = app(ChunkPlanner::class)->partition($text, 1500);
        self::assertGreaterThan(1, count($chunks));
        $covered = 0;
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual($covered, $chunk['start_offset']);
            self::assertGreaterThan($covered, $chunk['end_offset']);
            $body = mb_substr($text, $chunk['start_offset'], $chunk['end_offset'] - $chunk['start_offset']);
            self::assertTrue(mb_check_encoding($body, 'UTF-8'));
            self::assertSame($chunk['input_hash'], hash('sha256', $body));
            self::assertLessThanOrEqual(1500, $chunk['token_count']);
            self::assertSame(1 + substr_count(mb_substr($text, 0, $chunk['start_offset']), "\f"), $chunk['start_page']);
            $covered = $chunk['end_offset'];
        }
        self::assertSame(mb_strlen($text), $covered);
    }

    // MERGE

    public function test_two_hundred_records_merge_correctly_without_document_locks_and_with_linear_queries(): void
    {
        [$document, $records] = $this->evidenceDocument(140);
        self::assertGreaterThanOrEqual(200, count($records));
        $statements = [];
        $queries = $this->countQueries(function () use ($document, &$stats) {
            $stats = app(EvidenceMerger::class)->merge($document);
        }, $statements);

        // Overlap duplicate + explicit alias each collapse one record.
        $expected = count($records) - 1;
        self::assertSame($expected, DocumentEvidence::count());
        self::assertSame(140, $document->kpis()->count());
        self::assertSame(19, $document->entities()->count());
        self::assertSame(10, $document->risks()->count());
        self::assertSame(5, $document->deadlines()->count());
        self::assertSame(0, DocumentEvidence::whereNull('source_id')->count());
        self::assertSame(count($records) + 1, $stats['candidate_records']); // One overlap duplicate.
        self::assertSame($expected, $stats['inserted']);
        foreach (DocumentEvidence::all() as $evidence) {
            foreach ($evidence->sources as $source) {
                self::assertSame($source['quote'], mb_substr($document->extracted_text, $source['start_offset'], $source['end_offset'] - $source['start_offset']));
            }
        }
        $bank = DocumentEvidence::where('kind', 'entity')->get()->first(fn ($e) => $e->data['value'] === 'International Bank');
        self::assertCount(2, $bank->sources);
        self::assertCount(2, DocumentEvidence::where('kind', 'metric')->get()->first(fn ($e) => count($e->sources) > 1)->sources);

        foreach ($statements as $sql) {
            self::assertFalse(str_contains($sql, '"documents"') && str_contains($sql, 'for update'), 'Merge must not lock the document row.');
        }
        // Linear, not per-record: ~200 records, 40 distinct KPI identities.
        self::assertLessThan(1100, $queries);
    }

    public function test_metric_query_growth_is_linear_and_repeated_kpi_identities_resolve_once(): void
    {
        [$small] = $this->evidenceDocument(50, 10);
        $smallQueries = $this->countQueries(fn () => app(EvidenceMerger::class)->merge($small));
        [$large] = $this->evidenceDocument(150, 10);
        $largeQueries = $this->countQueries(function () use ($large, &$stats) {
            $stats = app(EvidenceMerger::class)->merge($large);
        });
        // 3x the metrics, same 10 KPI identities: growth is bounded by the KPI row inserts.
        self::assertLessThan($smallQueries + 100 * 4, $largeQueries);
        self::assertSame(10, $stats['kpi_alias_fast_path'] + $stats['kpi_resolutions']);
        self::assertSame(141, $stats['kpi_cache_hits']); // 150 metric records + 1 overlap duplicate, 10 resolved once each.
    }

    public function test_second_merge_is_idempotent_and_does_not_duplicate_sources_or_domain_rows(): void
    {
        [$document] = $this->evidenceDocument(140);
        $merger = app(EvidenceMerger::class);
        $merger->merge($document);
        $snapshot = DocumentEvidence::orderBy('identity')->get()->map(fn ($e) => [$e->identity, $e->source_id, count($e->sources)])->all();
        $counts = [$document->kpis()->count(), $document->entities()->count(), $document->risks()->count(), $document->deadlines()->count(), KpiDefinition::count()];
        $queries = $this->countQueries(function () use ($merger, $document, &$stats) {
            $stats = $merger->merge($document);
        });
        self::assertSame(0, $stats['inserted']);
        self::assertSame(0, $stats['updated']);
        self::assertSame(0, $stats['derived_created']);
        self::assertSame(0, $stats['kpi_resolutions']); // Learned aliases short-circuit the locked write path.
        self::assertSame($snapshot, DocumentEvidence::orderBy('identity')->get()->map(fn ($e) => [$e->identity, $e->source_id, count($e->sources)])->all());
        self::assertSame($counts, [$document->kpis()->count(), $document->entities()->count(), $document->risks()->count(), $document->deadlines()->count(), KpiDefinition::count()]);
        self::assertLessThan(120, $queries);
    }

    public function test_retry_after_partial_merge_reuses_persisted_evidence_and_finishes_derived_rows_once(): void
    {
        [$document] = $this->evidenceDocument(140);
        $second = DocumentChunk::where('identity', 'chunk:1')->sole();
        $second->update(['status' => 'pending']);
        $merger = app(EvidenceMerger::class);
        $first = $merger->merge($document);
        // Simulate a timeout after evidence was written but before some derived rows were linked.
        $unlinked = DocumentEvidence::where('kind', 'metric')->orderBy('identity')->limit(20)->get();
        foreach ($unlinked as $evidence) {
            $document->kpis()->whereKey((int) substr($evidence->source_id, 4))->delete();
            $evidence->update(['source_id' => null]);
        }
        $second->update(['status' => 'completed']);
        $retry = $merger->merge($document);
        self::assertSame($first['inserted'], $retry['existing_hits']);
        self::assertSame(20 + $retry['inserted'], $retry['derived_created']);
        self::assertSame(140, $document->kpis()->count());
        self::assertSame(0, DocumentEvidence::whereNull('source_id')->count());
        self::assertSame(DocumentEvidence::where('kind', 'metric')->count(), $document->kpis()->count());
    }

    public function test_quote_mismatch_is_still_rejected_at_merge(): void
    {
        $document = $this->document('Revenue increased to USD 10 in 2024.');
        $document->forceFill(['ai_pipeline' => ['key' => 'quote-key', 'route' => 'incremental']])->save();
        $this->completedChunk($document, 'chunk:0', 0, 36, [$this->record(['quote' => 'Revenue increased to USD 99 in 2024.']),
            $this->record(['quote' => 'Revenue increased to USD 10 in 2024.'])]);
        $stats = app(EvidenceMerger::class)->merge($document);
        self::assertSame(1, $stats['rejected_quote']);
        self::assertSame(1, DocumentEvidence::count());
        self::assertSame('Revenue increased to USD 10 in 2024.', DocumentEvidence::sole()->data['quote']);
    }

    public function test_merge_failure_and_retry_finalize_partial_evidence_with_one_debit(): void
    {
        [$document] = $this->evidenceDocument(60);
        $this->completedChunk($document, 'chunk:failed', 0, 10, [], 'failed')->update(['failure_class' => 'invalid_evidence', 'result' => null]);
        app(IncrementalPipeline::class)->pump($document->id);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        $failing = \Mockery::mock(EvidenceMerger::class.'[merge]');
        $failing->shouldReceive('merge')->once()->andReturnUsing(function ($doc) {
            app(EvidenceMerger::class)->merge($doc);
            throw new \RuntimeException('Simulated worker interruption');
        });
        try {
            (new MergeDocumentEvidenceJob($merge->id))->handle($failing, app(PipelineStageRecorder::class));
            self::fail('Expected merge failure');
        } catch (\RuntimeException) {
        }
        self::assertSame('failed', $merge->fresh()->status);
        self::assertNull($document->fresh()->credit_accounted_at);
        $kpis = $document->kpis()->count();
        $job = new MergeDocumentEvidenceJob($merge->id);
        $job->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        $job->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame('completed', $merge->fresh()->status);
        self::assertSame($kpis, $document->kpis()->count());
        self::assertSame(1, DB::table('credit_ledger')->where('related_id', $document->id)->where('direction', 'debit')->count());
        $diagnostics = $merge->fresh()->result['diagnostics'];
        self::assertGreaterThan(0, $diagnostics['existing_hits']);
        self::assertSame(0, $diagnostics['inserted']);
        self::assertArrayNotHasKey('quote', $diagnostics);
        self::assertStringNotContainsString('Indicator', json_encode($diagnostics));
        // Partial coverage stays visible after finalization.
        self::assertSame(1, app(EvidenceBudget::class)->forDocument($document->fresh())['coverage']['failed_chunks']);
        Bus::assertDispatched(GenerateDocumentSummaryJob::class);
    }

    // SYNTHESIS

    private function mergedForSynthesis(string $text, array $records): Document
    {
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['key' => 'synth-key', 'route' => 'incremental', 'tokens' => (int) ceil(strlen($text) / 4), 'budget_usd' => 5]])->save();
        $this->completedChunk($document, 'chunk:0', 0, mb_strlen($text), $records);
        app(EvidenceMerger::class)->merge($document->fresh());

        return $document->fresh();
    }

    public function test_full_source_is_given_to_sonnet_synthesis_with_evidence_authoritative(): void
    {
        $text = 'Revenue increased to USD 10 in 2024. The board also discussed regional context in detail.';
        $document = $this->mergedForSynthesis($text, [$this->record(['quote' => 'Revenue increased to USD 10 in 2024.'])]);
        $data = app(EvidenceBudget::class)->forSynthesis($document);
        self::assertSame('full', $data['source_context']['coverage']);
        self::assertSame($text, $data['source_context']['text']);
        self::assertSame('full', $data['coverage']['source_text']);
        DocumentChunk::where('identity', 'chunk:0')->update(['status' => 'completed']);
        $sourceId = DocumentEvidence::sole()->source_id;
        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 100], 'content' => [['type' => 'text', 'text' => json_encode([
                'executive_summary' => 'Revenue increased.', 'key_findings' => ['Revenue increased.'], 'critical_risks' => [],
                'upcoming_deadlines' => [], 'important_entities' => [], 'recommended_attention' => [],
                'trends' => [['observation' => 'Revenue rose.', 'significance' => 'Growth.', 'source_ids' => [$sourceId]],
                    ['observation' => 'Invented margin of 40%.', 'significance' => 'Unsupported.', 'source_ids' => ['kpi:invented']]]])]]])]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        Http::assertSent(function ($request) use ($text) {
            $prompt = $request['messages'][0]['content'];

            return $request['model'] === 'claude-sonnet-5-5' && str_contains($prompt, json_encode($text, JSON_UNESCAPED_UNICODE))
                && str_contains($prompt, 'validated evidence records stay authoritative')
                && str_contains($prompt, 'never surface a figure, date or obligation that appears only in source_context');
        });
        $summary = $document->fresh()->intelligenceSummary;
        // Unsupported citations are dropped: only evidence IDs can ground surfaced findings.
        self::assertSame([[$sourceId]], array_column($summary->trends, 'source_ids'));
        self::assertSame('Ready', $document->fresh()->status);
    }

    public function test_source_is_reduced_to_evidence_excerpts_or_omitted_when_it_does_not_fit(): void
    {
        $filler = str_repeat('Background narrative without figures. ', 400);
        $text = $filler.'Revenue increased to USD 10 in 2024.'.$filler.'Grant income fell to USD 4 in 2024.'.$filler;
        $document = $this->mergedForSynthesis($text, [
            $this->record(['quote' => 'Revenue increased to USD 10 in 2024.']),
            $this->record(['label' => 'Grant income', 'value' => '4', 'quote' => 'Grant income fell to USD 4 in 2024.'])]);
        $budget = app(EvidenceBudget::class);
        config(['document_intelligence.synthesis_source_max_tokens' => 1000]);
        $context = $budget->forSynthesis($document)['source_context'];
        self::assertSame('excerpts', $context['coverage']);
        self::assertCount(2, $context['excerpts']);
        $bytes = 0;
        foreach ($context['excerpts'] as $excerpt) {
            self::assertSame($excerpt['text'], mb_substr($text, $excerpt['start_offset'], $excerpt['end_offset'] - $excerpt['start_offset']));
            $bytes += strlen($excerpt['text']);
        }
        self::assertStringContainsString('Revenue increased to USD 10 in 2024.', $context['excerpts'][0]['text']);
        self::assertLessThanOrEqual(1000 * strlen($text) / ceil(strlen($text) / 3), $bytes);
        self::assertSame($context, $budget->forSynthesis($document)['source_context']); // Deterministic.
        // Every context reduction halves the source allowance.
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'synthesis_reductions' => 1]])->save();
        self::assertSame(500, $budget->sourceBudgetTokens($document));
        config(['document_intelligence.synthesis_source_max_tokens' => 0]);
        self::assertSame(['coverage' => 'omitted', 'excerpts' => []], $budget->forSynthesis($document)['source_context']);
    }

    public function test_synthesis_reservation_holds_budget_for_source_text_but_repair_excludes_it(): void
    {
        $document = $this->document($this->report(40000));
        $client = app(AnthropicClient::class);
        $withSource = $client->synthesisReservation($document);
        config(['document_intelligence.synthesis_source_max_tokens' => 0]);
        $withoutSource = $client->synthesisReservation($document);
        // Full JSON-encoded source (~40KB) is held instead of the 4KB omitted-source envelope.
        self::assertGreaterThan($withoutSource['synthesis_input_bound'] + 35000, $withSource['synthesis_input_bound']);
        self::assertSame($withoutSource['repair_reserved_usd'], $withSource['repair_reserved_usd']);
    }

    // DEPLOYMENT HARDENING

    private function withEnv(array $values, callable $callback): mixed
    {
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $_SERVER[$name] = (string) $value;
                putenv($name.'='.$value);
            }
        }
        try {
            return $callback();
        } finally {
            foreach ($original as $name => [$env, $server, $put]) {
                unset($_ENV[$name], $_SERVER[$name]);
                $env === null ? null : $_ENV[$name] = $env;
                $server === null ? null : $_SERVER[$name] = $server;
                $put === false ? putenv($name) : putenv($name.'='.$put);
            }
        }
    }

    public function test_incremental_extraction_output_cap_has_its_own_env_and_legacy_cap_is_unchanged(): void
    {
        $extraction = fn () => (require config_path('document_intelligence.php'))['extraction_max_tokens'];
        $legacy = fn () => (require config_path('services.php'))['anthropic']['max_tokens'];
        self::assertSame(16000, $this->withEnv(['ANTHROPIC_EXTRACTION_MAX_TOKENS' => null, 'ANTHROPIC_MAX_TOKENS' => '1234'], $extraction));
        self::assertSame(9000, $this->withEnv(['ANTHROPIC_EXTRACTION_MAX_TOKENS' => '9000'], $extraction));
        // The legacy variable keeps its name and meaning, and never moves the incremental cap.
        self::assertSame(1234, $this->withEnv(['ANTHROPIC_MAX_TOKENS' => '1234', 'ANTHROPIC_EXTRACTION_MAX_TOKENS' => '9000'], $legacy));
        self::assertSame(9000, $this->withEnv(['ANTHROPIC_MAX_TOKENS' => '1234', 'ANTHROPIC_EXTRACTION_MAX_TOKENS' => '9000'], $extraction));

        // Runtime: incremental requests use the dedicated cap (clamped to the model maximum).
        config(['document_intelligence.extraction_max_tokens' => 9000, 'services.anthropic.max_tokens' => 1234]);
        self::assertSame(9000, app(ExtractionCapacity::class)->outputTokens());
        config(['document_intelligence.extraction_max_tokens' => 999999]);
        self::assertSame(64000, app(ExtractionCapacity::class)->outputTokens());
        config(['document_intelligence.extraction_max_tokens' => 9000]);
        $document = $this->document($this->report(62000));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385]),
            '*/messages' => Http::response(['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20], 'content' => [['type' => 'text', 'text' => json_encode(['records' => [], 'entities' => []])]]])]);
        app(IncrementalPipeline::class)->route($document);
        $chunk = DocumentChunk::where('stage', 'extraction')->firstOrFail();
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['max_tokens'] === 9000);
        // The legacy four-job path still sends ANTHROPIC_MAX_TOKENS.
        $this->seed(DocumentEntitiesPromptSeeder::class);
        try {
            app(AnthropicClient::class)->extractDocumentEntities('Short report.', 'Legacy.pdf', $document->fresh());
        } catch (\Throwable) {
            // Only the request shape matters here.
        }
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['max_tokens'] === 1234);
    }

    public function test_routing_and_planning_diagnostics_are_metadata_only(): void
    {
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        config(['services.anthropic.api_key' => 'fake-private-key']);
        $text = str_replace('Programme delivery', 'CONFIDENTIALMARKER delivery', $this->report(62000));
        $document = $this->document($text);
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385])]);
        app(IncrementalPipeline::class)->route($document);
        $records = collect($handler->getRecords());
        $route = $records->first(fn ($r) => $r['message'] === 'Document intelligence route selected')['context'];
        foreach (['mode' => 'direct', 'document_tokens' => 15385, 'planned_partitions' => 1] as $key => $value) {
            self::assertSame($value, $route[$key]);
        }
        self::assertSame($route['input_capacity_tokens'] - 15385, $route['input_headroom_tokens']);
        self::assertSame($route['output_capacity_tokens'] - $route['expected_output_tokens'], $route['output_headroom_tokens']);
        foreach ($route as $key => $value) {
            self::assertTrue(is_int($value) || is_bool($value) || is_float($value) || in_array($key, ['document_id', 'mode', 'model'], true), $key);
        }
        $plan = $records->first(fn ($r) => $r['message'] === 'Document intelligence partitions planned')['context'];
        self::assertSame(1, $plan['root_chunks']);
        $logs = json_encode($handler->getRecords());
        self::assertStringNotContainsString('CONFIDENTIALMARKER', $logs);
        self::assertStringNotContainsString('fake-private-key', $logs);
    }

    public function test_split_and_cost_diagnostics_are_metadata_only(): void
    {
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        $document = $this->document($this->report(62000));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385]),
            '*/messages' => Http::response(['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 15000, 'output_tokens' => 16000], 'content' => [['type' => 'text', 'text' => '{"records": [']]])]);
        app(IncrementalPipeline::class)->route($document);
        $chunk = DocumentChunk::where('stage', 'extraction')->sole();
        (new ProcessDocumentChunkJob($chunk->id))->handle(app(AnthropicClient::class), app(IncrementalPipeline::class));
        $records = collect($handler->getRecords());
        $split = $records->first(fn ($r) => $r['message'] === 'Document intelligence chunk split decision')['context'];
        self::assertSame(['outcome' => 'split', 'failure_class' => 'max_tokens', 'depth' => 0],
            array_intersect_key($split, array_flip(['outcome', 'failure_class', 'depth'])));
        self::assertGreaterThanOrEqual(2, $split['children']);
        $cost = $records->first(fn ($r) => $r['message'] === 'Document AI cost settled')['context'];
        self::assertSame('extraction', $cost['stage']);
        self::assertSame(15000, $cost['input_tokens']);
        self::assertSame(16000, $cost['output_tokens']);
        self::assertTrue($cost['actual_known']);
        self::assertSame((float) DocumentAiRun::sole()->estimated_cost_usd, (float) $cost['settled_cost_usd']);
        self::assertSame(round($cost['document_budget_usd'] - $cost['document_committed_usd'], 6), $cost['document_budget_remaining_usd']);
        self::assertStringNotContainsString('Programme delivery', json_encode($handler->getRecords()));
    }

    public function test_synthesis_diagnostics_report_source_mode_tokens_and_cost_without_source_text(): void
    {
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        $text = 'Revenue increased to USD 10 in 2024. CONFIDENTIALMARKER regional narrative continues here.';
        $document = $this->mergedForSynthesis($text, [$this->record(['quote' => 'Revenue increased to USD 10 in 2024.'])]);
        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 100], 'content' => [['type' => 'text', 'text' => json_encode([
                'executive_summary' => 'Revenue increased.', 'key_findings' => ['Revenue increased.'], 'critical_risks' => [],
                'upcoming_deadlines' => [], 'important_entities' => [], 'recommended_attention' => []])]]])]);
        (new GenerateDocumentSummaryJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        self::assertSame('Ready', $document->fresh()->status);
        $synthesis = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'Document synthesis attempt finished')['context'];
        foreach (['status' => 'completed', 'source_context' => 'full', 'configured_model' => 'claude-sonnet-5-5',
            'input_tokens' => 500, 'output_tokens' => 100, 'provider_requests' => 1, 'repair_requests' => 0, 'synthesis_reductions' => 0] as $key => $value) {
            self::assertSame($value, $synthesis[$key], $key);
        }
        self::assertGreaterThan(0, $synthesis['settled_cost_usd']);
        self::assertGreaterThanOrEqual($synthesis['settled_cost_usd'], $synthesis['estimated_cost_usd']);
        self::assertArrayHasKey('latency_ms', $synthesis);
        $logs = json_encode($handler->getRecords());
        self::assertStringNotContainsString('CONFIDENTIALMARKER', $logs);
        self::assertStringNotContainsString('Revenue increased to USD 10', $logs);
    }

    public function test_duplicate_merge_delivery_is_blocked_and_redelivery_stays_idempotent(): void
    {
        [$document] = $this->evidenceDocument(60);
        app(IncrementalPipeline::class)->pump($document->id);
        $merge = DocumentChunk::where('stage', 'merge')->sole();
        $job = new MergeDocumentEvidenceJob($merge->id);
        [$middleware] = $job->middleware();
        self::assertInstanceOf(WithoutOverlapping::class, $middleware);
        // Scoped to this document's merge unit; a blocked duplicate is dropped, not re-queued.
        self::assertSame('document-merge:'.$merge->id, $middleware->key);
        self::assertNull($middleware->releaseAfter);
        // Lock outlives the job; the queue never re-delivers a job a worker may still run.
        self::assertGreaterThan($job->timeout, $middleware->expiresAfter);
        // Effective production pools; merge and summary run on synthesis, chunks on extraction.
        $pools = array_replace_recursive(config('horizon.defaults'), config('horizon.environments.production'));
        foreach ([[$job->timeout, 'supervisor-synthesis'], [(new GenerateDocumentSummaryJob('x'))->timeout, 'supervisor-synthesis'],
            [(new ProcessDocumentChunkJob('x'))->timeout, 'supervisor-extraction']] as [$timeout, $pool]) {
            self::assertLessThan($pools[$pool]['timeout'], $timeout);
            self::assertLessThan(config('queue.connections.redis.retry_after'), $pools[$pool]['timeout']);
        }

        $lock = Cache::lock($middleware->getLockKey($job), $middleware->expiresAfter);
        self::assertTrue($lock->get());
        $ran = false;
        $middleware->handle($job, function () use (&$ran) {
            $ran = true;
        });
        self::assertFalse($ran);
        self::assertSame(0, DocumentEvidence::count());
        $lock->release();

        $middleware->handle($job, fn ($job) => $job->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class)));
        self::assertSame('completed', $merge->fresh()->status);
        $snapshot = fn () => [DocumentEvidence::count(), $document->kpis()->count(), $document->entities()->count(),
            DocumentEvidence::all()->sum(fn ($e) => count($e->sources)),
            DB::table('credit_ledger')->where('related_id', $document->id)->where('direction', 'debit')->count()];
        $before = $snapshot();
        self::assertSame(1, $before[4]);
        // Late duplicate deliveries finalize only; nothing is merged, created or billed again.
        (new MergeDocumentEvidenceJob($merge->id))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        (new MergeDocumentEvidenceJob($merge->id))->handle(app(EvidenceMerger::class), app(PipelineStageRecorder::class));
        self::assertSame($before, $snapshot());
    }

    public function test_derived_rows_for_two_hundred_fifty_records_use_a_bounded_number_of_statements(): void
    {
        // ~245 evidence rows (production sector_report.pdf merged 233). Every statement is a database
        // round trip, which is what dominated production merge time (~48s of ~50s), not CPU.
        [$document, $records] = $this->evidenceDocument(180);
        $merger = app(EvidenceMerger::class);
        $statements = [];
        $this->countQueries(function () use ($merger, $document, &$stats) {
            $stats = $merger->merge($document);
        }, $statements);
        self::assertSame(count($records) - 1, $stats['derived_created']);
        $derived = array_filter($statements, fn ($sql) => preg_match('/insert into "document_(kpis|entities|risks|deadlines)"|nextval|UPDATE document_evidence SET source_id/i', $sql));
        // One multi-row insert and one id allocation per derived table, one link update per batch.
        self::assertLessThanOrEqual(9, count($derived));
        self::assertSame(180, $document->kpis()->count());
        self::assertSame(0, DocumentEvidence::whereNull('source_id')->count());
        foreach (DocumentEvidence::all() as $evidence) {
            [$kind, $id] = explode(':', $evidence->source_id);
            $table = ['kpi' => 'document_kpis', 'entity' => 'document_entities', 'risk' => 'document_risks', 'deadline' => 'document_deadlines', 'fact' => null][$kind];
            if ($table) {
                self::assertTrue(DB::table($table)->where('id', $id)->where('document_id', $document->id)->exists(), $evidence->source_id);
            }
        }
        // The KPI observation row carries the same fields the per-row Eloquent create wrote.
        $kpi = $document->kpis()->orderBy('id')->first();
        self::assertSame('Region', $kpi->identity_metadata['scope']);
        self::assertNotNull($kpi->created_at);
        // Retry: nothing new is created and links stay stable.
        $links = DocumentEvidence::orderBy('id')->pluck('source_id')->all();
        $again = $merger->merge($document);
        self::assertSame(0, $again['derived_created']);
        self::assertSame($links, DocumentEvidence::orderBy('id')->pluck('source_id')->all());
        self::assertSame(180, $document->kpis()->count());
    }

    public function test_bulk_derived_rows_reject_foreign_workspace_kpi_identity(): void
    {
        [$document] = $this->evidenceDocument(3);
        $foreign = KpiDefinition::create(['workspace_id' => $this->document('x')->workspace_id, 'canonical_name' => 'Indicator 0',
            'normalized_name' => 'indicator 0', 'matching_metadata' => [], 'identity_key' => 'foreign']);
        $resolver = \Mockery::mock(KpiIdentityResolver::class);
        $resolver->shouldReceive('existingAlias')->andReturn(['definition_id' => $foreign->id, 'profile' => [], 'method' => 'alias']);
        $this->app->instance(KpiIdentityResolver::class, $resolver);
        $this->expectException(\LogicException::class);
        app(EvidenceMerger::class)->merge($document);
    }
}
