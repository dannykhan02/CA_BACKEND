<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentIntelligenceSummary;
use App\Models\OcrResult;
use App\Models\OperationQuote;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AI\AiPricing;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\DocumentCostLogger;
use App\Services\AiCredits\QuoteService;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\EntitlementService;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Workload credits v1 (docs/AI_CREDITS.md): five bands, scans priced like any document, per-type token estimates,
 * no charge for partial work, no charge for reprocessing a paid document, and a cost line for every publication.
 * Legacy mode (flag off) is asserted unchanged at the end.
 */
class WorkloadCreditsV1Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private static int $hashes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.dense_shift.enabled' => true, 'document_intelligence.revision_v1' => true, 'document_intelligence.incremental' => false]);
    }

    private function workspace(int $savedAi = 100, int $legacyDocs = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $legacyDocs, 'ai_credits_remaining' => $savedAi]);

        return $workspace;
    }

    /** 84,100 bytes of DOCX text: 15,018 tokens at 5.6 bytes each, 17,271 with the 1.15 local-estimate margin: the regular band, 10 credits. */
    private const REGULAR_TEXT = 2900;

    private function document(Workspace $workspace, int $repeats = 1, string $type = 'DOCX', ?int $pages = null): Document
    {
        return Document::create(['name' => 'Report.docx', 'type' => $type, 'size_kb' => 1, 'status' => 'Processing',
            'classification' => 'Public', 'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id,
            'file_hash' => hash('sha256', 'v1fixture'.self::$hashes++), 'pages' => $pages ?? 0,
            'extracted_text' => str_repeat('Revenue grew in the quarter. ', $repeats)]);
    }

    /** A document whose token count the provider has already given (count_method = anthropic). */
    private function counted(Workspace $workspace, int $providerTokens, string $type = 'PDF'): Document
    {
        $document = $this->document($workspace, 1, $type);
        $document->forceFill(['ai_pipeline' => ['count_method' => 'anthropic', 'tokens' => $providerTokens]])->save();

        return $document->fresh();
    }

    private function confirm(Document $document): Document
    {
        $document->forceFill(['ai_pipeline' => [...($document->fresh()->ai_pipeline ?? []), 'credit_quote_confirmed' => true]])->save();

        return $document->fresh();
    }

    private function admit(Document $document, bool $confirmed = true): bool
    {
        return app(AiCreditAdmission::class)->admitDocument($confirmed ? $this->confirm($document) : $document->fresh());
    }

    private function op(Document $document, string $kind = 'document'): ?object
    {
        return DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $document->id)->first();
    }

    private function ledger(string $workspaceId, string $direction): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('direction', $direction)
            ->whereIn('unit', ['subscription_ai_credit', 'saved_ai_credit'])->sum('amount');
    }

    private function remaining(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining');
    }

    private function coverage(Document $document, int $failedChunks): Document
    {
        $document->forceFill(['ai_pipeline' => [...($document->fresh()->ai_pipeline ?? []),
            'coverage' => ['failed_chunks' => $failedChunks, 'total_chunks' => 4, 'comprehensive' => $failedChunks === 0]]])->save();

        return $document->fresh();
    }

    // ============================================================ Change 1: bands and confirmation

    public function test_the_bands_are_chosen_by_token_edges_with_the_agreed_credits(): void
    {
        $bands = config('ai_credits.bands');
        $this->assertSame(['small', 'medium', 'regular', 'large', 'xlarge', 'very_large'], array_keys($bands));
        $this->assertSame([4000, 8000, 18000, 35000, 90000, 250000], array_column($bands, 'max_tokens'));
        $this->assertSame([2, 6, 10, 20, 35, 80], array_column($bands, 'credits'));
        $this->assertSame(['v3', 30, 100], [config('ai_credits.quote_version'), config('ai_credits.confirm_from_credits'), config('ai_credits.confirm_over_pages')]);
        $this->assertNull(config('ai_credits.min_completion_cost_usd'), 'no linear completion gate chooses a band any more');
        $this->assertArrayNotHasKey('tiny', $bands);
    }

    public function test_the_per_credit_cap_the_dense_shift_and_the_plan_allowances_are_unchanged(): void
    {
        $this->assertSame(0.027, config('ai_credits.provider_cost_usd_per_credit'));
        $this->assertSame(1, config('ai_credits.dense_band_shift'));
        $this->assertSame([100, 100, 300, 300], [config('ai_credits.plans.starter.monthly'), config('ai_credits.plans.starter.annual'),
            config('ai_credits.plans.professional.monthly'), config('ai_credits.plans.professional.annual')]);
    }

    public function test_each_band_edge_is_inclusive_and_the_spend_cap_is_credits_times_0_027(): void
    {
        $q = app(QuoteService::class);
        $edges = [[1, 'small', 2], [4000, 'small', 2], [4001, 'medium', 6], [8000, 'medium', 6], [8001, 'regular', 10], [18000, 'regular', 10],
            [18001, 'large', 20], [35000, 'large', 20], [35001, 'xlarge', 35], [90000, 'xlarge', 35], [90001, 'very_large', 80], [250000, 'very_large', 80]];
        foreach ($edges as [$tokens, $band, $credits]) {
            $c = $q->classify($tokens, false);
            $this->assertSame([$band, $credits, false], [$c['band'], $c['credits'], $c['declined']], $tokens.' tokens');
            $this->assertEqualsWithDelta($credits * 0.027, $c['cap'], 0.0000001);
        }
        $over = $q->classify(250001, false);
        $this->assertSame([true, 'document_too_large'], [$over['declined'], $over['reason']]);
    }

    public function test_a_dense_document_moves_up_one_band_and_stops_at_the_top(): void
    {
        $q = app(QuoteService::class);
        $this->assertSame(['medium', true], [$q->classify(4000, true)['band'], $q->classify(4000, true)['shifted']]);
        $this->assertSame('xlarge', $q->classify(35000, true)['band']);
        $this->assertSame('very_large', $q->classify(250000, true)['band']);
    }

    public function test_no_band_cap_is_below_the_measured_cost_of_a_document_of_that_size(): void
    {
        // Provider token count and the highest USD cost measured at that size (docintel:cost-vs-size-report, 24 Ready documents).
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);
        $measured = [[300, 0.036], [1000, 0.036], [4000, 0.051], [12000, 0.226], [18000, 0.228], [27000, 0.355], [40000, 0.657], [54000, 0.657], [77000, 0.677]];
        foreach ($measured as [$tokens, $cost]) {
            $quote = $q->quoteDocument($this->counted($ws, $tokens))['quote'];
            $this->assertGreaterThanOrEqual($cost, $quote->provider_cost_cap_usd, $tokens.' tokens costs '.$cost.' but quotes '.$quote->band.' with cap '.$quote->provider_cost_cap_usd);
        }
        // The 39k-token spreadsheet (0.824) is multiplied by 1.6 and moved up by the dense rule: very_large.
        $sheet = $q->quoteDocument($this->counted($ws, 39000, 'XLSX'))['quote'];
        $this->assertSame(['very_large', 62400], [$sheet->band, $sheet->preflight['tokens']]);
        $this->assertGreaterThanOrEqual(0.824, $sheet->provider_cost_cap_usd);
    }

    public function test_the_quote_for_documents_of_these_provider_token_sizes(): void
    {
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);
        foreach ([[300, 'small', 2], [3000, 'small', 2], [7000, 'medium', 6], [12000, 'regular', 10], [27000, 'large', 20], [54000, 'xlarge', 35], [77000, 'xlarge', 35]] as [$tokens, $band, $credits]) {
            $quote = $q->quoteDocument($this->counted($ws, $tokens))['quote'];
            $this->assertSame([$band, $credits], [$quote->band, $quote->credits], $tokens.' tokens');
        }
    }

    public function test_a_27k_token_document_lands_in_the_20_credit_band_by_count_and_by_estimate(): void
    {
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);
        $byCount = $q->quoteDocument($this->counted($ws, 27000))['quote'];
        $this->assertSame(['large', 20, 'provider_count'], [$byCount->band, $byCount->credits, $byCount->preflight['token_source']]);

        // 72,000 PDF bytes = 24,000 tokens locally, x1.15 = 27,600.
        $byEstimate = $q->quoteDocument($this->document($ws, (int) ceil(72000 / 29), 'PDF'))['quote'];
        $this->assertSame(['large', 20, 'local_estimate'], [$byEstimate->band, $byEstimate->credits, $byEstimate->preflight['token_source']]);
        $this->assertEqualsWithDelta(0.54, $byEstimate->provider_cost_cap_usd, 0.0000001);
    }

    public function test_a_quote_of_thirty_credits_or_more_requires_confirmation_and_says_so(): void
    {
        $ws = $this->workspace(100);
        $document = $this->counted($ws, 54000); // xlarge, 35 credits
        $this->assertFalse($this->admit($document, false));

        $pipeline = $document->fresh()->ai_pipeline;
        $this->assertTrue($pipeline['awaiting_credit_confirmation']);
        $this->assertSame([35, 'xlarge'], [$pipeline['credit_quote']['credits'], $pipeline['credit_quote']['band']]);
        $this->assertTrue($pipeline['credit_quote']['requires_confirmation']);
        $this->assertNull($this->op($document), 'nothing is reserved until the customer accepts');
        $pending = app(EntitlementService::class)->summary($ws->id)['ai_credits']['pendingConfirmations'];
        $this->assertTrue($pending[0]['requiresConfirmation']);
        $this->assertSame(35, $pending[0]['credits']);
    }

    public function test_a_quote_under_thirty_credits_on_a_short_document_needs_no_confirmation(): void
    {
        $ws = $this->workspace(100);
        $large = $this->counted($ws, 27000); // 20 credits: does not ask
        $this->assertTrue($this->admit($large, false));
        $this->assertSame(20, (int) $this->op($large)->amount_reserved);
        $this->assertFalse($large->fresh()->ai_pipeline['awaiting_credit_confirmation'] ?? false);

        $small = $this->document($ws, 1, 'PDF', 100); // 100 pages is not over the page limit
        $this->assertTrue($this->admit($small, false));
        $this->assertSame(2, (int) $this->op($small)->amount_reserved);
    }

    public function test_more_than_a_hundred_pages_requires_confirmation_whatever_it_costs(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1, 'PDF', 101);
        $this->assertFalse($this->admit($document, false));
        $this->assertSame(2, $document->fresh()->ai_pipeline['credit_quote']['credits']);
        $this->assertSame(101, $document->fresh()->ai_pipeline['credit_quote']['pages']);
        $this->assertTrue($document->fresh()->ai_pipeline['credit_quote']['requires_confirmation']);
    }

    public function test_the_credit_threshold_is_a_config_value(): void
    {
        $q = app(QuoteService::class);
        $this->assertFalse($q->requiresConfirmation(29, 0));
        $this->assertTrue($q->requiresConfirmation(30, 0));
        $this->assertTrue($q->requiresConfirmation(2, 101));
        $this->assertFalse($q->requiresConfirmation(29, 100));
        config(['ai_credits.confirm_from_credits' => 10]);
        $this->assertTrue($q->requiresConfirmation(10, 0));
        config(['ai_credits.confirm_over_pages' => 50]);
        $this->assertTrue($q->requiresConfirmation(2, 51));
    }

    // ============================================================ Change 2: token source

    public function test_the_provider_count_is_used_as_is_and_beats_the_local_estimate(): void
    {
        $ws = $this->workspace(1000);
        $document = $this->document($ws, 5000, 'PDF'); // 145,000 bytes: the local estimate would be ~48,000 x1.15
        $document->forceFill(['ai_pipeline' => ['count_method' => 'anthropic', 'tokens' => 3000]])->save();

        $quote = app(QuoteService::class)->quoteDocument($document->fresh())['quote'];

        $this->assertSame(['small', 3000, 3000, 'provider_count', 1], [$quote->band, $quote->preflight['tokens'], $quote->preflight['raw_tokens'],
            $quote->preflight['token_source'], $quote->preflight['estimate_multiplier']]);
    }

    public function test_a_local_estimate_is_multiplied_by_1_15_and_the_quote_says_so(): void
    {
        $ws = $this->workspace(1000);
        $document = $this->document($ws, 1, 'PDF');
        $document->update(['extracted_text' => str_repeat('x', 6000)]); // 2,000 tokens at 3.0 bytes per token
        $q = app(QuoteService::class);

        $quote = $q->quoteDocument($document->fresh())['quote'];
        $this->assertSame([2300, 2000, 'local_estimate', 1.15, 1], [$quote->preflight['tokens'], $quote->preflight['raw_tokens'], $quote->preflight['token_source'],
            $quote->preflight['estimate_multiplier'], $quote->preflight['spreadsheet_multiplier']]);

        // It can move a document over an edge: 3,600 raw tokens are small, 4,140 are medium.
        $edge = $this->document($ws, 1, 'PDF');
        $edge->update(['extracted_text' => str_repeat('x', 10800)]);
        $this->assertSame(['medium', 4140], [$q->quoteDocument($edge->fresh())['quote']->band, $q->quoteDocument($edge->fresh())['quote']->preflight['tokens']]);

        config(['ai_credits.local_estimate_multiplier' => 1.0]);
        $off = $this->document($ws, 1, 'PDF');
        $off->update(['extracted_text' => str_repeat('x', 10800)]);
        $this->assertSame(['small', 3600], [$q->quoteDocument($off->fresh())['quote']->band, $q->quoteDocument($off->fresh())['quote']->preflight['tokens']]);
    }

    public function test_a_spreadsheet_is_multiplied_by_1_6_whether_the_count_is_the_providers_or_an_estimate(): void
    {
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);

        $counted = $q->quoteDocument($this->counted($ws, 10000, 'XLSX'))['quote'];
        $this->assertSame([16000, 10000, 'provider_count', 1.6], [$counted->preflight['tokens'], $counted->preflight['raw_tokens'], $counted->preflight['token_source'], $counted->preflight['spreadsheet_multiplier']]);
        // 16,000 tokens is the regular band (18,000), and a spreadsheet is dense: one band up, large.
        $this->assertSame(['large', 20], [$counted->band, $counted->credits]);

        $estimated = $this->document($ws, 1, 'XLSX');
        $estimated->update(['extracted_text' => str_repeat('x', 3000)]); // 1,000 tokens x1.15 x1.6
        $quote = $q->quoteDocument($estimated->fresh())['quote'];
        $this->assertSame([1840, 1000], [$quote->preflight['tokens'], $quote->preflight['raw_tokens']]);

        config(['ai_credits.spreadsheet_token_multiplier' => 1.0]);
        $this->assertSame(10000, $q->quoteDocument($this->counted($ws, 10000, 'XLSX'))['quote']->preflight['tokens']);
    }

    public function test_tokens_are_estimated_per_file_type_before_the_margins(): void
    {
        config(['ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.spreadsheet_token_multiplier' => 1.0]);
        $q = app(QuoteService::class);
        $estimate = fn (string $type) => $q->estimateTokens(new Document(['type' => $type, 'extracted_text' => str_repeat('x', 17000)]));

        $this->assertSame(5667, $estimate('PDF'));   // 17000 / 3.0
        $this->assertSame(3036, $estimate('DOCX'));  // 17000 / 5.6: Word text is sparser
        $this->assertSame(5667, $estimate('XLSX'));  // no entry of its own: the spreadsheet multiplier covers it
        $this->assertSame(5667, $estimate('TXT'));   // anything unlisted uses the default
        $this->assertSame(5667, $estimate('pdf'));   // case does not matter
        config(['ai_credits.local_estimate_multiplier' => 1.15, 'ai_credits.spreadsheet_token_multiplier' => 1.6]);
        $this->assertSame([6518, 3492, 10428], [$estimate('PDF'), $estimate('DOCX'), $estimate('XLSX')]);
    }

    public function test_the_same_text_prices_higher_as_a_spreadsheet_and_lower_as_a_word_file(): void
    {
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);
        $repeats = (int) ceil(60000 / 29); // ~60,000 bytes
        $word = $q->quoteDocument($this->document($ws, $repeats, 'DOCX'))['quote'];  // 10,715 tokens x1.15 = 12,322
        $pdf = $q->quoteDocument($this->document($ws, $repeats, 'PDF'))['quote'];    // 20,001 x1.15 = 23,002
        $sheet = $q->quoteDocument($this->document($ws, $repeats, 'XLSX'))['quote']; // 20,001 x1.15 x1.6 = 36,802, and a spreadsheet is dense
        $this->assertSame([10, 20, 80], [$word->credits, $pdf->credits, $sheet->credits]);
        $this->assertGreaterThan($word->preflight['tokens'] * 1.5, $pdf->preflight['tokens']);
        $this->assertGreaterThan($pdf->preflight['tokens'], $sheet->preflight['tokens']);
    }

    // ============================================================ scans (kept from the first workload PR)

    public function test_a_scan_is_priced_by_page_tokens_in_the_same_bands(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1, 'PNG');
        $this->assertTrue(app(AiCreditAdmission::class)->admitOcr($document, 1));
        $this->assertSame(1, app(QuoteService::class)->ocrPages($document));
        OcrResult::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'page_number' => 1,
            'engine' => 'vision', 'raw_text' => 'x', 'confidence' => 0.9]);
        $this->assertTrue($this->admit($document));

        // Two words of text, but one page of image is 600 tokens: small, 2 credits. The OCR is a spend bound, not a charge.
        $quote = OperationQuote::where('kind', 'document')->firstOrFail();
        $this->assertSame(['small', 2, 600, 'ocr_pages'], [$quote->band, (int) $this->op($document)->amount_reserved, $quote->preflight['tokens'], $quote->preflight['token_source']]);
        $this->assertSame(0, (int) $this->op($document, 'ocr')->amount_reserved, 'reading pages is not a separate charge');
    }

    public function test_the_ocr_bound_default_is_twice_the_highest_observed_call_and_gives_these_scan_quotes(): void
    {
        $q = app(QuoteService::class);
        $this->assertSame(0.006, config('ai_credits.ocr.provider_cost_usd_per_page'));
        $this->assertSame(600, config('ai_credits.ocr.tokens_per_page'));

        // pages => [band, credits, the OCR operation's own spend cap = max(pages x 0.006, 0.05)]
        $expected = [1 => ['small', 2, 0.05], 2 => ['small', 2, 0.05], 10 => ['medium', 6, 0.06], 60 => ['xlarge', 35, 0.36]];
        foreach ($expected as $pages => [$band, $credits, $cap]) {
            $scan = $q->classifyScan($pages);
            $this->assertSame([$band, $credits], [$scan['band'], $scan['credits']], $pages.' pages');
            $this->assertEqualsWithDelta($cap, $q->classifyOcr($pages)['cap'], 0.0000001, $pages.' pages: OCR spend cap');
        }
    }

    public function test_the_tokens_of_a_scan_come_from_its_page_count_when_that_is_larger_than_the_text(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1, 'PDF');
        $q = app(QuoteService::class);
        $this->assertSame(12, $q->estimateTokens($document)); // 29 bytes = 10 tokens x1.15
        $this->assertSame(6000, $q->estimateTokens($document, 10)); // 10 pages x 600
        config(['ai_credits.ocr.tokens_per_page' => 1000]);
        $this->assertSame(10000, $q->estimateTokens($document, 10));
    }

    public function test_the_price_of_a_scan_always_covers_its_ocr_provider_bound(): void
    {
        $q = app(QuoteService::class);
        foreach ([1, 2, 5, 10, 20, 40, 60] as $pages) {
            $c = $q->classifyScan($pages);
            $this->assertFalse($c['declined'], $pages.' pages');
            $this->assertGreaterThanOrEqual($pages * (float) config('ai_credits.ocr.provider_cost_usd_per_page'), $c['cap'], $pages.' pages: cap covers the OCR bound');
        }
    }

    // ============================================================ Change 4: failed and partial work

    public function test_complete_work_settles_exactly_the_quote_and_never_more(): void
    {
        $ws = $this->workspace(100);
        $document = $this->coverage($this->document($ws, self::REGULAR_TEXT), 0);
        $this->assertTrue($this->admit($document));
        $quote = OperationQuote::where('kind', 'document')->firstOrFail();

        // Provider spend far above the quote changes nothing the customer pays.
        DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic',
            'model' => 'm', 'estimated_cost_usd' => 4.5, 'operation_quote_id' => $quote->id, 'status' => 'success', 'created_at' => now()]);
        $credits = app(WorkspaceCreditService::class);
        $credits->accountForReadyDocument($document->fresh());
        $credits->accountForReadyDocument($document->fresh()); // duplicate delivery

        $this->assertSame(['completed', 10], [$this->op($document)->status, (int) $this->op($document)->amount_settled]);
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));
        $this->assertSame(90, $this->remaining($ws));
        $this->assertSame('settled', $quote->fresh()->status);
        $this->assertNotNull($document->fresh()->credit_accounted_at);
    }

    public function test_a_result_with_failed_chunks_releases_the_whole_reservation_and_settles_nothing(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, self::REGULAR_TEXT);
        $this->assertTrue($this->admit($document));
        $this->coverage($document, 2);

        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        $this->assertSame(['released', 'partial_result'], [$this->op($document)->status, $this->op($document)->release_reason]);
        $this->assertSame('released', OperationQuote::where('kind', 'document')->value('status'));
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));
        $this->assertSame(10, $this->ledger($ws->id, 'release'));
        $this->assertSame(100, $this->remaining($ws));
        $this->assertNull($document->fresh()->credit_accounted_at);
        $this->assertSame(100, app(CreditAccountant::class)->available($ws->id));
    }

    public function test_a_retry_after_a_partial_result_is_the_first_charge_not_a_second_one(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, self::REGULAR_TEXT);
        $this->assertTrue($this->admit($document));
        $this->coverage($document, 1);
        $credits = app(WorkspaceCreditService::class);
        $credits->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));

        // The customer re-runs the analysis: it is admitted as a first analysis and priced once.
        app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), false, $this->user->id, 10);
        $this->assertNull($this->op($document, 'reanalysis'), 'not a paid re-analysis: the first run delivered nothing billable');
        $this->assertSame([2, 'reserved', 10], [$this->op($document)->attempt_number, $this->op($document)->status, (int) $this->op($document)->amount_reserved]);

        $this->coverage($document, 0);
        $credits->accountForReadyDocument($document->fresh());
        $credits->accountForReadyDocument($document->fresh());

        $this->assertSame(10, $this->ledger($ws->id, 'debit'), 'exactly one charge across both runs');
        $this->assertSame(90, $this->remaining($ws));
        $this->assertNotNull($document->fresh()->credit_accounted_at);
    }

    public function test_a_failed_document_releases_everything_it_held_including_the_ocr_bound(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1, 'PNG');
        $this->assertTrue(app(AiCreditAdmission::class)->admitOcr($document, 1));
        OcrResult::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'page_number' => 1,
            'engine' => 'vision', 'raw_text' => 'x', 'confidence' => 0.9]);
        $this->assertTrue($this->admit($document));
        $document->update(['status' => 'Failed']);

        app(EntitlementService::class)->summary($ws->id);

        $this->assertSame(['released', 'released'], [$this->op($document)->status, $this->op($document, 'ocr')->status]);
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));
        $this->assertSame(100, $this->remaining($ws));
    }

    // ============================================================ Change 5: reprocess

    public function test_reprocessing_a_document_that_was_already_paid_for_charges_nothing_again(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, self::REGULAR_TEXT);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        $this->assertSame([90, 10], [$this->remaining($ws), $this->ledger($ws->id, 'debit')]);
        $operations = DB::table('billing_operations')->count();
        $quotes = OperationQuote::count();

        // What a normal-route reprocess does: reserve again as a new request, run, and publish again.
        app(EntitlementService::class)->reserveDocument($document->fresh(), true);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        $this->assertSame([90, 10], [$this->remaining($ws), $this->ledger($ws->id, 'debit')]);
        $this->assertSame([$operations, $quotes], [DB::table('billing_operations')->count(), OperationQuote::count()]);
        $this->assertSame(0, $this->ledger($ws->id, 'release'));
    }

    // ============================================================ publication: cost line and end-to-end

    private function deliver(Document $document, string $revisionId, array $pipeline = []): void
    {
        $document->forceFill(['ai_pipeline' => [...($document->fresh()->ai_pipeline ?? []), 'route' => 'normal'] + $pipeline, 'insights' => ['ok']])->save();
        DocumentIntelligenceSummary::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revisionId, 'executive_summary' => 'Summary', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revisionId, 'stage' => $stage, 'status' => 'completed']);
        }
    }

    private function publishable(Document $document, array $pipeline = []): string
    {
        $revision = app(DocumentRevisionPublisher::class)->begin($document->fresh());
        $this->deliver($document->fresh(), $revision->id, $pipeline);

        return $revision->id;
    }

    public function test_publishing_charges_the_quote_and_writes_one_cost_line(): void
    {
        Log::spy();
        $ws = $this->workspace(100);
        $document = $this->document($ws, self::REGULAR_TEXT);
        $this->assertTrue($this->admit($document));
        $quote = OperationQuote::where('kind', 'document')->firstOrFail();
        DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic',
            'model' => 'm', 'estimated_cost_usd' => 0.12, 'input_tokens' => 9000, 'output_tokens' => 800, 'operation_quote_id' => $quote->id, 'status' => 'success', 'created_at' => now()]);
        DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'document_summary', 'provider' => 'anthropic',
            'model' => 'm', 'estimated_cost_usd' => 0.05, 'input_tokens' => 3000, 'output_tokens' => 400, 'operation_quote_id' => $quote->id, 'status' => 'success', 'created_at' => now()]);
        $revisionId = $this->publishable($document);

        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId);
        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId); // already published: no second charge, no second line

        $this->assertSame([90, 10], [$this->remaining($ws), $this->ledger($ws->id, 'debit')]);
        $this->assertSame('Ready', $document->fresh()->status);
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($document, $ws) {
            return $message === 'Document cost summary'
                && $context['document_id'] === $document->id && $context['workspace_id'] === $ws->id
                && $context['file_type'] === 'DOCX' && $context['pages'] === 0
                && $context['estimated_tokens'] === 17271 && $context['band'] === 'regular'
                && $context['quoted_credits'] === 10 && $context['settled_credits'] === 10
                && $context['provider_cost_usd'] === 0.17 && $context['provider_runs'] === 2 && $context['runs_with_unknown_cost'] === 0
                && $context['input_tokens'] === 12000 && $context['output_tokens'] === 1200
                && $context['ai_credits_enabled'] === true && $context['failed_chunks'] === 0
                && ! str_contains(json_encode($context), 'Revenue grew');
        })->once();
    }

    public function test_publishing_a_partial_result_is_not_charged_and_the_line_says_so(): void
    {
        Log::spy();
        $ws = $this->workspace(100);
        $document = $this->document($ws, self::REGULAR_TEXT);
        $this->assertTrue($this->admit($document));
        $revisionId = $this->publishable($document, ['coverage' => ['failed_chunks' => 3, 'total_chunks' => 8, 'comprehensive' => false]]);

        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId);

        $this->assertSame('Ready', $document->fresh()->status, 'the customer keeps what was delivered');
        $this->assertSame([100, 0], [$this->remaining($ws), $this->ledger($ws->id, 'debit')]);
        $this->assertNull($document->fresh()->credit_accounted_at);
        $this->assertSame('released', $this->op($document)->status);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Document cost summary'
            && $context['quoted_credits'] === 10 && $context['settled_credits'] === 0 && $context['failed_chunks'] === 3)->once();
    }

    public function test_the_cost_logger_never_throws_or_writes_billing_state(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws);
        $before = [DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(), OperationQuote::count()];
        Log::spy();

        app(DocumentCostLogger::class)->log($document);

        $this->assertSame($before, [DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(), OperationQuote::count()]);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Document cost summary'
            && $context['quoted_credits'] === null && $context['settled_credits'] === null && $context['provider_cost_usd'] === 0.0)->once();
    }

    // ============================================================ usage logging: Voyage and the Haiku 5.5 tier

    public function test_voyage_usage_is_logged_and_never_billed(): void
    {
        Log::spy();
        config(['services.voyage.api_key' => 'test-key', 'services.voyage.model' => 'voyage-3']);
        Http::fake(['api.voyageai.com/*' => Http::response(['model' => 'voyage-3', 'data' => [['embedding' => [0.1, 0.2]]], 'usage' => ['total_tokens' => 321]])]);
        $ws = $this->workspace(100);

        app(VoyageEmbeddingClient::class)->embed(['some text']);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Voyage embeddings usage'
            && $context['total_tokens'] === 321 && $context['inputs'] === 1 && ! str_contains(json_encode($context), 'some text'))->once();
        $this->assertSame(100, $this->remaining($ws));
        $this->assertSame(0, DB::table('credit_ledger')->where('direction', 'debit')->count());
    }

    public function test_the_estimator_prices_haiku_5_5_prompts_above_100k_tokens_at_the_long_context_tier(): void
    {
        $pricing = app(AiPricing::class);
        $this->assertSame([0.10, 0.50], array_slice(config('document_intelligence.pricing')['claude-haiku-5-5'], 0, 2));
        $this->assertSame([0.50, 2.50], array_slice(config('document_intelligence.pricing_long_context')['claude-haiku-5-5']['rates'], 0, 2));

        // At the threshold the base rate applies; one token over, the whole request is 5x.
        $this->assertEqualsWithDelta(0.01, $pricing->estimate('claude-haiku-5-5', ['input_tokens' => 100000]), 0.0000001);
        $this->assertEqualsWithDelta(0.050001, $pricing->estimate('claude-haiku-5-5', ['input_tokens' => 100001]), 0.0000011);
        $this->assertEqualsWithDelta(0.0525, $pricing->estimate('claude-haiku-5-5', ['input_tokens' => 100001, 'output_tokens' => 1000]), 0.0001);
        // Cached tokens count towards the threshold, and the whole request moves to the higher tier.
        $this->assertEqualsWithDelta(60000 * 0.50 / 1e6 + 50000 * 0.05 / 1e6, $pricing->estimate('claude-haiku-5-5',
            ['input_tokens' => 60000, 'cache_read_input_tokens' => 50000]), 0.0000011);
        // The hard request bound the spend gate uses follows the same tier.
        $this->assertEqualsWithDelta(ceil(100001 * 0.50 + 1000 * 2.50) / 1e6, $pricing->maximum('claude-haiku-5-5', 100001, 1000), 0.0000001);
        $this->assertEqualsWithDelta(ceil(100000 * 0.10 + 1000 * 0.50) / 1e6, $pricing->maximum('claude-haiku-5-5', 100000, 1000), 0.0000001);
        // A model with no long-context tier is unaffected.
        $this->assertEqualsWithDelta(0.6, $pricing->estimate('claude-haiku-4-5-20251001', ['input_tokens' => 600000]), 0.0000001);
    }

    // ============================================================ legacy mode (flag off) is unchanged

    public function test_flag_off_charges_one_unit_per_document_whatever_its_size_type_or_coverage(): void
    {
        config(['ai_credits.enabled' => false]);
        $ws = $this->workspace(0, 5);
        $admission = app(AiCreditAdmission::class);
        $credits = app(WorkspaceCreditService::class);

        $big = $this->document($ws, self::REGULAR_TEXT * 3, 'PDF', 400);       // far above 100 pages and 10 credits
        $scan = $this->document($ws, 1, 'PNG');
        $partial = $this->coverage($this->document($ws, 5, 'XLSX'), 4);          // failed chunks do not change legacy accounting

        foreach ([$big, $scan, $partial] as $document) {
            $this->assertTrue($admission->admitDocument($document->fresh()), 'no quote, no confirmation');
            $this->assertTrue($admission->admitOcr($document, 80), 'no OCR page cap or reservation in legacy mode');
            app(EntitlementService::class)->reserveDocument($document->fresh());
            $this->assertNull($this->op($document)->amount_reserved);
            $credits->accountForReadyDocument($document->fresh());
            $this->assertNotNull($document->fresh()->credit_accounted_at);
        }

        $this->assertSame(2, (int) $ws->credits()->value('documents_remaining'));
        $this->assertSame(3, DB::table('credit_ledger')->where('direction', 'debit')->where('unit', 'saved_document')->sum('amount') + 0);
        $this->assertSame(0, OperationQuote::count());
        $this->assertNull(DB::table('billing_operations')->where('kind', 'ocr')->first());
        $this->assertFalse(app(EntitlementService::class)->summary($ws->id)['ai_credits']['enabled']);
    }

    public function test_flag_off_still_logs_the_cost_line_with_no_credit_fields(): void
    {
        config(['ai_credits.enabled' => false]);
        Log::spy();
        $ws = $this->workspace(0, 5);
        $document = $this->document($ws, 3);
        app(EntitlementService::class)->reserveDocument($document);
        $revisionId = $this->publishable($document);

        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId);

        $this->assertSame(4, (int) $ws->credits()->value('documents_remaining'));
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Document cost summary'
            && $context['ai_credits_enabled'] === false && $context['quoted_credits'] === null && $context['settled_credits'] === null)->once();
    }
}
