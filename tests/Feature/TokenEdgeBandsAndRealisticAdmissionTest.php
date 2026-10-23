<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AI\ProviderRequestAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\EntitlementService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bands follow token edges (the dense rule is off by default), and a small document's requests are admitted on their
 * realistic cost so the document can finish inside the small band's cap. The incremental route is ON throughout.
 */
class TokenEdgeBandsAndRealisticAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class); // without it the synthesis floor silently becomes 0
        config(['ai_credits.enabled' => true, 'document_intelligence.incremental' => true,
            'services.anthropic.extraction_model' => 'claude-haiku-5-5', 'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
    }

    private function workspace(): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 100]);

        return $workspace;
    }

    /** Tab-delimited rows, so the text counts as tabular (dense) whatever the file type. */
    private function table(int $bytes): string
    {
        return substr(str_repeat("Revenue\t1200\t3400\n", intdiv($bytes, 18) + 1), 0, $bytes);
    }

    private function document(Workspace $ws, string $type, int $bytes, ?int $providerTokens, int $pages = 1): Document
    {
        $document = Document::create(['name' => 'Report.'.strtolower($type), 'type' => $type, 'size_kb' => 1, 'status' => 'Processing',
            'classification' => 'Public', 'year' => 2026, 'pages' => $pages, 'workspace_id' => $ws->id, 'uploaded_by' => $this->user->id,
            'extracted_text' => $this->table($bytes), 'file_hash' => hash('sha256', 'doc'.Str::uuid())]);
        if ($providerTokens !== null) {
            $document->forceFill(['ai_pipeline' => ['count_method' => 'anthropic', 'tokens' => $providerTokens]])->save();
        }

        return $document->fresh();
    }

    public function test_the_six_measured_documents_quote_their_token_edge_band_and_the_dense_rule_only_lifts_them_when_switched_on(): void
    {
        $this->assertFalse(config('ai_credits.dense_shift.enabled'), 'off by default');
        $ws = $this->workspace();
        $quotes = app(QuoteService::class);
        // label => [type, text bytes, provider tokens, pages, quoted tokens, band, credits, band with the dense rule on]
        $cases = [
            'PDF 7 pages, local estimate' => ['PDF', 8760, null, 7, [3300, 3400], 'small', 2, 'medium'],
            'DOCX 3,362 provider tokens' => ['DOCX', 18827, 3362, 1, [3362, 3362], 'small', 2, 'medium'],
            'PDF 46 pages' => ['PDF', 81975, 27325, 46, [27325, 27325], 'large', 20, 'xlarge'],
            'PDF 40 pages' => ['PDF', 162360, 54120, 40, [54120, 54120], 'xlarge', 35, 'very_large'],
            'PDF 81 pages' => ['PDF', 231528, 77176, 81, [77176, 77176], 'xlarge', 35, 'very_large'],
            'XLSX 39,453 x1.6' => ['XLSX', 118359, 39453, 1, [63125, 63125], 'xlarge', 35, 'very_large'],
        ];

        foreach ($cases as $label => [$type, $bytes, $provider, $pages, [$lo, $hi], $band, $credits, $denseBand]) {
            $document = $this->document($ws, $type, $bytes, $provider, $pages);
            config(['ai_credits.dense_shift.enabled' => false]);
            $preview = $quotes->previewDocument($document);
            $c = $preview['classification'];
            $this->assertTrue($preview['signals']['dense'], $label.' is dense, which is what used to lift it');
            $this->assertGreaterThanOrEqual($lo, $preview['signals']['tokens'], $label);
            $this->assertLessThanOrEqual($hi, $preview['signals']['tokens'], $label);
            $this->assertSame([$band, $credits, false], [$c['band'], $c['credits'], $c['shifted']], $label.' tokens='.$preview['signals']['tokens']);
            $this->assertLessThanOrEqual($c['cap'], $preview['signals']['synthesis_floor_usd'], $label.' floor fits the band');

            config(['ai_credits.dense_shift.enabled' => true]);
            $this->assertSame($denseBand, $quotes->previewDocument($document)['classification']['band'], $label.' with the dense rule on');
        }
    }

    // ------------------------------------------------------------------ realistic admission

    /** A reserved small-band quote for a document of $tokens tokens (3 bytes each, PDF): 2 credits, cap 0.054. */
    private function smallQuote(Workspace $ws, int $tokens): array
    {
        $document = $this->document($ws, 'PDF', $tokens * 3, $tokens);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        $this->assertSame(['small', 2], [$quote->band, $quote->credits]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        return [$document, $quote];
    }

    /** One provider request through the same admission and spend ledger the client uses; returns what it was admitted at. */
    private function request(Document $document, OperationQuote $quote, string $task, int $bytes, int $maxTokens, int $outputTokens): array
    {
        $model = app(AiModels::class)->forTask($task);
        $assessment = app(ProviderRequestAdmission::class)->assess($document, $task, $model,
            [['role' => 'user', 'content' => str_repeat('x', $bytes)]], ['max_tokens' => $maxTokens]);
        $spend = app(OperationSpend::class);
        $attempt = (string) Str::uuid();
        $spend->reserveForRequest($quote->id, $attempt, $task, $assessment);
        $spend->markDispatched($attempt);
        $spend->finishAttempt($attempt, app(AiPricing::class)->estimate($model, ['input_tokens' => intdiv($bytes, 3), 'output_tokens' => $outputTokens]));

        return $assessment;
    }

    /** The normal route's stages for a document of $tokens tokens, with each stage's configured max_tokens. */
    private function stages(Document $document, OperationQuote $quote, int $tokens): void
    {
        $text = $tokens * 3 + 1500;
        $this->request($document, $quote, 'document_type', $text, 4096, 400);
        $this->request($document, $quote, 'entities', $text, 6144, 1000);
        $this->request($document, $quote, 'risks', $text, 4096, 800);
        $this->request($document, $quote, 'deadlines', $text, 4096, 600);
        $this->request($document, $quote, 'insights', $text, 14000, 1200);
        // The summary sends the prompt and a trimmed evidence record, not the document.
        $this->request($document, $quote, 'document_summary', 9500, 4096, 1800);
    }

    public function test_small_band_documents_finish_every_stage_and_settle_two_credits(): void
    {
        foreach ([322, 3362] as $tokens) {
            $ws = $this->workspace();
            [$document, $quote] = $this->smallQuote($ws, $tokens);

            $this->stages($document, $quote, $tokens);

            $this->assertLessThanOrEqual($quote->provider_cost_cap_usd, app(OperationSpend::class)->spentUsd($quote->id), (string) $tokens);
            app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
            $operation = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
            $this->assertSame('completed', $operation->status, (string) $tokens);
            $this->assertSame(2, (int) $operation->amount_settled, (string) $tokens);
        }
    }

    public function test_a_summary_figure_realistic_below_the_small_cap_and_worst_case_above_it(): void
    {
        $ws = $this->workspace();
        [$document] = $this->smallQuote($ws, 3362);
        $a = app(ProviderRequestAdmission::class)->assess($document, 'document_summary', 'claude-sonnet-5-5',
            [['role' => 'user', 'content' => str_repeat('x', 9500)]], ['max_tokens' => 4096]);
        $this->assertLessThanOrEqual(0.054, $a['realistic']);
        $this->assertGreaterThan(0.054, $a['maximum']);
        $this->assertGreaterThan($a['realistic'], $a['maximum']);
    }

    public function test_a_request_beyond_the_overrun_guard_is_refused_and_the_hold_is_released(): void
    {
        $ws = $this->workspace();
        [$document, $quote] = $this->smallQuote($ws, 3362);

        // 16,000 output tokens on Sonnet 5.5 is a worst case of about 0.18, above 3 x 0.054, though its realistic cost fits.
        try {
            $this->request($document, $quote, 'document_summary', 9500, 16000, 1800);
            $this->fail('The hard guard must refuse a request whose worst case is beyond overrun_factor x the cap.');
        } catch (AiProcessingException $e) {
            $this->assertSame('budget_exceeded', $e->classification);
        }
        $this->assertSame(0, DB::table('provider_call_attempts')->where('operation_quote_id', $quote->id)->count());

        // The same request at 8,192 output tokens (worst case about 0.10) is inside the guard and admitted.
        $this->assertGreaterThan(0.0, $this->request($document, $quote, 'document_summary', 9500, 8192, 1800)['maximum']);

        $document->update(['status' => 'Needs Review']);
        app(EntitlementService::class)->summary($ws->id);
        $operation = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
        $this->assertSame('released', $operation->status);
        $this->assertSame(0, (int) DB::table('credit_ledger')->where('workspace_id', $ws->id)->where('direction', 'debit')->sum('amount'));
    }

    public function test_strict_mode_restores_the_worst_case_refusal(): void
    {
        config(['ai_credits.admission_mode' => 'strict']);
        $ws = $this->workspace();
        [$document, $quote] = $this->smallQuote($ws, 3362);

        $this->expectException(AiProcessingException::class);
        $this->expectExceptionMessage('budget_exceeded');
        $this->request($document, $quote, 'document_summary', 9500, 4096, 1800); // worst case about 0.061 > 0.054
    }
}
