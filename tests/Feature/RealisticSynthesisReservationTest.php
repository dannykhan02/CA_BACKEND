<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\AiCredits\SynthesisReservation;
use App\Services\AnthropicClient;
use App\Services\EntitlementService;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The incremental route reserves the primary synthesis request only (config ai_credits.synthesis_reservation); a fallback or a
 * repair is paid from the cap that remains when it is sent. Every test here runs with the incremental route switched ON.
 */
class RealisticSynthesisReservationTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Revenue increased to USD 10 in 2024.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        // Without the seeded prompt the floor silently falls back to 0, which would make every band below look cheap.
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['ai_credits.enabled' => true, 'document_intelligence.incremental' => true, 'document_intelligence.revision_v1' => false,
            'services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001', 'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
    }

    private function workspace(int $savedAi = 100): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);

        return $workspace;
    }

    /** A document whose provider token count is $tokens, with text of 3 bytes per token (PDF density). */
    private function sized(Workspace $workspace, int $tokens): Document
    {
        $sentence = 'Revenue grew in the quarter. ';
        $text = substr(str_repeat($sentence, intdiv($tokens * 3, strlen($sentence)) + 1), 0, $tokens * 3);
        $document = Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'extracted_text' => $text,
            'file_hash' => hash('sha256', 'sized'.Str::uuid())]);
        $document->forceFill(['ai_pipeline' => ['count_method' => 'anthropic', 'tokens' => $tokens]])->save();

        return $document->fresh();
    }

    // ------------------------------------------------------------------ the quote (Change 3)

    public function test_six_provider_token_sizes_quote_their_token_edge_band_and_the_cap_covers_the_measured_cost(): void
    {
        $ws = $this->workspace();
        $quotes = app(QuoteService::class);
        // tokens => [expected band, measured production cost of a document of that size in USD, incremental route expected]
        $expect = [300 => ['small', 0.036, false], 3400 => ['small', 0.051, false], 15000 => ['regular', 0.228, true],
            27000 => ['large', 0.355, true], 54000 => ['xlarge', 0.657, true], 77000 => ['xlarge', 0.677, true]];

        foreach ($expect as $tokens => [$band, $measured, $incremental]) {
            $preview = $quotes->previewDocument($this->sized($ws, $tokens));
            $c = $preview['classification'];
            $signals = $preview['signals'];
            $note = "tokens={$tokens} band={$c['band']} cap={$c['cap']} floor={$signals['synthesis_floor_usd']}";
            $this->assertSame($band, $c['band'], $note);
            $this->assertFalse($c['shifted'], $note);
            $this->assertSame($incremental, $signals['incremental_route'], $note);
            $this->assertGreaterThanOrEqual($measured, $c['cap'], $note);
            if ($incremental) {
                $this->assertGreaterThan(0.0, $signals['synthesis_floor_usd'], $note);
                $this->assertLessThanOrEqual($c['cap'], $signals['synthesis_floor_usd'], $note);
            } else {
                $this->assertSame(0.0, $signals['synthesis_floor_usd'], $note);
            }
        }
    }

    public function test_mode_full_restores_the_primary_fallback_and_repair_floor(): void
    {
        $ws = $this->workspace();
        $document = $this->sized($ws, 15000);
        $quotes = app(QuoteService::class);
        $r = app(AnthropicClient::class)->synthesisReservation($document, null, 0);

        $primaryOnly = $quotes->previewDocument($document);
        $this->assertEqualsWithDelta($r['synthesis_reserved_usd'], $primaryOnly['signals']['synthesis_floor_usd'], 1e-6);
        $this->assertSame('regular', $primaryOnly['classification']['band']);

        config(['ai_credits.synthesis_reservation.mode' => 'full']);
        $full = $quotes->previewDocument($document);
        $this->assertEqualsWithDelta($r['synthesis_reserved_usd'] + $r['synthesis_degraded_reserved_usd'] + $r['repair_reserved_usd'],
            $full['signals']['synthesis_floor_usd'], 1e-6);
        $this->assertGreaterThan($primaryOnly['signals']['synthesis_floor_usd'] * 1.5, $full['signals']['synthesis_floor_usd']);
        $this->assertSame('large', $full['classification']['band'], 'the three-request hold lifts a 15k-token document one band');
    }

    public function test_the_default_is_primary_only_and_headroom_scales_the_floor_but_never_below_one(): void
    {
        $this->assertSame('primary_only', config('ai_credits.synthesis_reservation.mode'));
        $policy = app(SynthesisReservation::class);
        $r = ['synthesis_reserved_usd' => 0.2, 'synthesis_degraded_reserved_usd' => 0.15, 'repair_reserved_usd' => 0.05];

        config(['ai_credits.synthesis_reservation.headroom' => 1.25]);
        $this->assertEqualsWithDelta(0.25, $policy->floor($r), 1e-9);
        config(['ai_credits.synthesis_reservation.headroom' => 0.5]);
        $this->assertEqualsWithDelta(0.2, $policy->floor($r), 1e-9);
        config(['ai_credits.synthesis_reservation.mode' => 'anything-else']);
        $this->assertEqualsWithDelta(0.4, $policy->floor($r), 1e-9, 'an unknown mode behaves as full');
    }

    // ------------------------------------------------------------------ the pipeline

    private function summary(): array
    {
        return ['executive_summary' => 'Revenue increased.', 'key_findings' => ['Revenue increased.'],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [], 'recommended_attention' => []];
    }

    private function response(array $data, int $input = 100, int $output = 20): array
    {
        return ['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
            'content' => [['type' => 'text', 'text' => json_encode($data)]]];
    }

    /** Extraction finished and merged under a reserved quote with the given provider cap: the document waits for synthesis. */
    private function merged(Workspace $ws, float $cap): Document
    {
        config(['document_intelligence.large_tokens' => 100, 'document_intelligence.budget_base_usd' => 2]);
        $text = str_repeat("Programme delivery continued across partner countries with measured results.\n\n", 60)
            .self::QUOTE."\n\n".str_repeat("Operating costs were stable and staffing levels remained unchanged.\n\n", 60);
        $document = Document::create(['workspace_id' => $ws->id, 'uploaded_by' => $this->user->id, 'name' => 'Sector report.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026, 'pages' => 3,
            'file_hash' => hash('sha256', 'merged'.Str::uuid()), 'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['tokens' => 1500, 'route' => 'incremental']])->save();
        $quote = app(QuoteService::class)->issue($ws->id, 'document', $document->id, ['band' => 'regular', 'credits' => 3, 'cap' => $cap, 'preflight' => []]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        app(IncrementalPipeline::class)->start($document);
        $document->refresh();
        foreach (DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->get() as $chunk) {
            $slice = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
            $chunk->update(['status' => 'completed', 'attempts' => 1, 'result' => ['records' => str_contains($slice, self::QUOTE) ? [[
                'kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company', 'quote' => self::QUOTE, 'reference' => '',
                'entity_type' => null, 'unit' => 'USD', 'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
                'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.95, 'aliases' => []]] : []]]);
        }
        app(EvidenceMerger::class)->merge($document->fresh());

        return $document->fresh();
    }

    private function synthesize(Document $document, bool $redelivery = false): void
    {
        (new GenerateDocumentSummaryJob($document->id, $redelivery))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
    }

    private function synthesisUnits(Document $document)
    {
        return DocumentChunk::where('document_id', $document->id)->where('stage', 'synthesis')->orderBy('created_at')->orderBy('id')->get();
    }

    /** Extraction has spent all but $room of the document budget. */
    private function leaveRoom(Document $document, float $room): Document
    {
        $budget = (float) $document->fresh()->ai_pipeline['budget_usd'];
        $chunk = DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')->firstOrFail();
        $others = (float) DocumentChunk::where('document_id', $document->id)->sum('reserved_cost') - (float) $chunk->reserved_cost;
        $chunk->update(['reserved_cost' => round($budget - $room - $others, 6)]);

        return $document->fresh();
    }

    private function op(Document $document): object
    {
        return DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
    }

    public function test_extraction_leaves_room_for_the_primary_synthesis_only_under_primary_only(): void
    {
        $ws = $this->workspace();
        $document = $this->merged($ws, 5.0);
        $primary = (float) $document->ai_pipeline['synthesis_reserved_usd'];
        $pipeline = app(IncrementalPipeline::class);

        $tight = $this->leaveRoom($document, $primary + 0.002);
        $this->assertTrue($pipeline->canReserve($tight, 0.001), 'one more extraction request fits beside the primary synthesis');
        $this->assertFalse($pipeline->canReserve($tight, 0.003), 'but nothing may take the primary synthesis room');

        config(['ai_credits.synthesis_reservation.mode' => 'full']);
        $this->assertFalse($pipeline->canReserve($tight->fresh(), 0.001), 'under full the fallback and the repair are held as well');
    }

    public function test_a_fallback_is_allowed_when_the_cap_remains(): void
    {
        $ws = $this->workspace();
        $document = $this->merged($ws, 5.0);
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary(), input: 2000, output: 500))]);

        $this->synthesize($document);
        $this->synthesize($document, true);

        $document->refresh();
        $this->assertSame('Ready', $document->status);
        $this->assertSame([['from' => 0, 'to' => 1, 'reason' => 'timeout']], $document->ai_pipeline['synthesis_degradations']);
        $this->assertSame(2, $this->synthesisUnits($document)->count());
        $this->assertSame('completed', $this->synthesisUnits($document)->last()->status);
        Http::assertSentCount(2);
        $this->assertSame('completed', $this->op($document)->status, 'delivered, so the credits are charged');
    }

    public function test_a_fallback_that_does_not_fit_is_refused_and_the_document_ends_partial_with_the_hold_released(): void
    {
        $ws = $this->workspace();
        $document = $this->merged($ws, 5.0);
        // A cap that pays for the primary request with a half of it to spare, but not for a second synthesis after it.
        $primary = (float) app(AnthropicClient::class)->synthesisReservation($document, app(EvidenceBudget::class)->forSynthesis($document), 0)['synthesis_reserved_usd'];
        $cap = round($primary * 1.5, 6);
        OperationQuote::where('resource_id', $document->id)->update(['provider_cost_cap_usd' => $cap]);
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => $cap]])->save();
        Http::fake(['*/messages' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->response($this->summary()))]);

        $this->synthesize($document);
        $this->synthesize($document->fresh(), true);

        $document->refresh();
        $this->assertSame('Needs Review', $document->status);
        $this->assertTrue($document->ai_pipeline['partial']);
        $this->assertSame('budget_exceeded', $document->ai_pipeline['synthesis']);
        $this->assertSame('timeout_then_budget', $document->ai_pipeline['synthesis_failure_reason']);
        Http::assertSentCount(1); // the fallback was never sent
        $this->assertNull($document->credit_accounted_at);

        app(EntitlementService::class)->summary($ws->id); // releases a Needs Review hold
        $this->assertSame('released', $this->op($document)->status);
        $this->assertSame('needs_review', $this->op($document)->release_reason);
        $this->assertSame(0, (int) DB::table('credit_ledger')->where('workspace_id', $ws->id)->where('direction', 'debit')->sum('amount'));
        $this->assertSame(3, (int) DB::table('credit_ledger')->where('workspace_id', $ws->id)->where('direction', 'release')->sum('amount'));
        $this->assertSame('released', OperationQuote::where('resource_id', $document->id)->firstOrFail()->status);
    }

    public function test_a_request_beyond_the_remaining_cap_is_refused_by_the_operation_spend_check(): void
    {
        $ws = $this->workspace();
        $document = $this->merged($ws, 0.20);
        $quote = OperationQuote::where('resource_id', $document->id)->firstOrFail();
        $spend = app(OperationSpend::class);

        $first = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $first, 'document_summary', 0.12);
        $spend->markDispatched($first);
        $spend->finishAttempt($first, 0.10); // 0.10 of the 0.20 cap is now spent

        try {
            $spend->reserveAttempt($quote->id, (string) Str::uuid(), 'document_summary', 0.12);
            $this->fail('A request whose worst case does not fit the remaining cap must be refused.');
        } catch (AiProcessingException $e) {
            $this->assertSame('budget_exceeded', $e->classification);
        }
        $spend->reserveAttempt($quote->id, (string) Str::uuid(), 'document_summary', 0.09); // fits
        $this->assertSame(2, DB::table('provider_call_attempts')->where('operation_quote_id', $quote->id)->count());
    }
}
