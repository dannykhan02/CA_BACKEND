<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CostGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
    }

    private function document(string $text): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'guard'.$user->id), 'extracted_text' => $text]);
    }

    private function failCapacity(DocumentChunk $chunk): DocumentChunk
    {
        $chunk->update(['status' => 'running', 'failure_class' => 'max_tokens', 'token_count' => max(1, (int) ceil(($chunk->end_offset - $chunk->start_offset) / 4))]);

        return $chunk->fresh();
    }

    public function test_split_budget_bounds_the_whole_tree_and_keeps_partial_coverage_visible(): void
    {
        config(['document_intelligence.max_split_depth' => 6, 'document_intelligence.minimum_split_chars' => 50,
            'document_intelligence.max_split_parents_per_root' => 2]);
        $text = str_repeat("A paragraph about programme delivery and measured results.\n\n", 200);
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['key' => 'guard-key', 'route' => 'incremental', 'tokens' => 3000]])->save();
        $root = DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => 'guard-key', 'identity' => 'chunk:0', 'input_hash' => 'h', 'pipeline_version' => '1',
            'prompt_version' => '1', 'start_offset' => 0, 'end_offset' => mb_strlen($text)]);
        $pipeline = app(IncrementalPipeline::class);

        $pipeline->split($this->failCapacity($root), $document);
        $child = DocumentChunk::where('parent_id', $root->id)->orderBy('start_offset')->firstOrFail();
        $pipeline->split($this->failCapacity($child), $document);
        self::assertSame(2, DocumentChunk::where('status', 'split')->count());

        // Third capacity failure on one root: budget (2 per root) is spent, so no more fan-out.
        $grandchild = DocumentChunk::where('parent_id', $child->id)->orderBy('start_offset')->firstOrFail();
        $before = DocumentChunk::count();
        $pipeline->split($this->failCapacity($grandchild), $document);
        $grandchild->refresh();
        self::assertSame('failed', $grandchild->status);
        self::assertSame('split_limit', $grandchild->failure_class);
        self::assertSame($before, DocumentChunk::count());
        self::assertSame(2, DocumentChunk::where('status', 'split')->count());

        // The unextracted region is disclosed as incomplete coverage, never hidden.
        DocumentChunk::whereNotIn('status', ['split'])->where('id', '!=', $grandchild->id)
            ->update(['status' => 'completed', 'result' => json_encode(['records' => []])]);
        $coverage = app(EvidenceBudget::class)->forDocument($document->fresh())['coverage'];
        self::assertSame(1, $coverage['failed_chunks']);
        self::assertFalse($coverage['comprehensive']);

        // Non-capacity failures still never split, budget or not.
        $other = DocumentChunk::where('parent_id', $child->id)->where('id', '!=', $grandchild->id)->firstOrFail();
        $other->update(['status' => 'running', 'failure_class' => 'invalid_evidence']);
        $pipeline->split($other->fresh(), $document);
        self::assertSame('invalid_evidence', $other->fresh()->failure_class);
        self::assertSame(0, DocumentChunk::where('parent_id', $other->id)->count());
    }

    public function test_split_budget_scales_with_root_partitions(): void
    {
        config(['document_intelligence.max_split_depth' => 6, 'document_intelligence.minimum_split_chars' => 50,
            'document_intelligence.max_split_parents_per_root' => 1]);
        $text = str_repeat("Programme delivery continued with measured results.\n\n", 200);
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['key' => 'scale-key', 'route' => 'incremental', 'tokens' => 3000]])->save();
        $half = intdiv(mb_strlen($text), 2);
        $roots = collect([[0, $half], [$half, mb_strlen($text)]])->map(fn ($range, $i) => DocumentChunk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'pipeline_key' => 'scale-key',
            'identity' => 'chunk:'.$i, 'input_hash' => 'h', 'pipeline_version' => '1', 'prompt_version' => '1',
            'start_offset' => $range[0], 'end_offset' => $range[1]]));
        $pipeline = app(IncrementalPipeline::class);
        $pipeline->split($this->failCapacity($roots[0]), $document);
        $pipeline->split($this->failCapacity($roots[1]), $document);
        self::assertSame(2, DocumentChunk::where('status', 'split')->count());
        $leaf = DocumentChunk::where('parent_id', $roots[0]->id)->firstOrFail();
        $pipeline->split($this->failCapacity($leaf), $document);
        self::assertSame('split_limit', $leaf->fresh()->failure_class);
    }

    public function test_duplicate_dispatch_of_the_same_document_is_a_no_op(): void
    {
        $document = $this->document(str_repeat('Revenue increased to USD 10 in 2024. ', 1700));
        Http::fake(['*/count_tokens' => Http::response(['input_tokens' => 15385])]);
        $pipeline = app(IncrementalPipeline::class);
        self::assertTrue($pipeline->route($document));
        $chunks = DocumentChunk::orderBy('identity')->pluck('id')->all();
        self::assertTrue($pipeline->route($document->fresh()));
        self::assertTrue($pipeline->route($document->fresh()));
        self::assertSame($chunks, DocumentChunk::orderBy('identity')->pluck('id')->all());
        Bus::assertDispatchedTimes(ProcessDocumentChunkJob::class, count($chunks));
        // Unchanged text and model: token counting is not repeated either.
        Http::assertSentCount(1);
    }
}
