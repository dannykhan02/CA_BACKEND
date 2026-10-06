<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QaGuard;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\CreditLedger;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Variable-price AI credits: quotes, funding buckets, settlement, release, caps and compatibility. */
class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
    }

    private function workspace(int $savedAi = 0, int $legacyDocs = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $legacyDocs, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function subscribe(Workspace $workspace, ?int $aiCredits, int $docs = 20): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => $docs,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $aiCredits]);
    }

    /** $tokens is the local estimate: strlen / 3. */
    private function document(Workspace $workspace, int $tokens = 1000, string $type = 'PDF', string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => $type, 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id,
            'extracted_text' => str_repeat('Revenue grew in the quarter. ', (int) ceil($tokens * 3 / 30))]);
    }

    private function admit(Document $document): bool
    {
        return app(AiCreditAdmission::class)->admitDocument($document->fresh());
    }

    private function op(Document $document, string $kind = 'document'): ?object
    {
        return DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $document->id)->first();
    }

    private function ledger(string $workspaceId, string $direction, ?string $unit = null): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('direction', $direction)
            ->when($unit, fn ($q) => $q->where('unit', $unit))->sum('amount');
    }

    private function recordRun(OperationQuote $quote, ?float $cost, string $purpose = 'entities'): void
    {
        $document = Document::findOrFail($quote->resource_id);
        DocumentAiRun::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'purpose' => $purpose,
            'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'estimated_cost_usd' => $cost, 'status' => 'success',
            'operation_quote_id' => $quote->id, 'created_at' => now()]);
    }

    // ---------------------------------------------------------------- quotes (1-9, 37)

    public function test_document_bands_quote_the_configured_credits(): void
    {
        $ws = $this->workspace(1000);
        $q = app(QuoteService::class);
        $expect = ['simple' => [1000, 4], 'standard' => [20000, 10], 'large' => [50000, 30], 'very_large' => [150000, 80]];
        foreach ($expect as $band => [$tokens, $credits]) {
            $quote = $q->quoteDocument($this->document($ws, $tokens))['quote'];
            $this->assertSame([$band, $credits], [$quote->band, $quote->credits], $band);
            $this->assertEqualsWithDelta($credits * 0.027, $quote->provider_cost_cap_usd, 0.000001);
        }
    }

    public function test_a_document_above_the_top_band_is_declined_not_priced(): void
    {
        $ws = $this->workspace(1000);
        $result = app(QuoteService::class)->quoteDocument($this->document($ws, 300000));
        $this->assertTrue($result['declined']);
        $this->assertSame('document_too_large', $result['reason']);
        $this->assertSame(0, OperationQuote::count());
    }

    public function test_dense_spreadsheet_moves_up_a_band(): void
    {
        $ws = $this->workspace(1000);
        $quote = app(QuoteService::class)->quoteDocument($this->document($ws, 1000, 'XLSX'))['quote'];
        $this->assertSame('standard', $quote->band);
        $this->assertTrue($quote->preflight['spreadsheet']);
    }

    public function test_a_band_whose_provider_cap_cannot_finish_the_work_moves_up_or_declines(): void
    {
        $q = app(QuoteService::class);
        // The pipeline's own synthesis reservation (0.30 USD) does not fit the 4- or 10-credit ceiling.
        $this->assertSame('large', $q->classify(1000, false, 0.30)['band']);
        $this->assertTrue($q->classify(1000, false, 5.0)['declined']);
        $this->assertSame('provider_cost_exceeds_top_band', $q->classify(1000, false, 5.0)['reason']);
    }

    public function test_ocr_is_priced_from_the_page_count_and_hard_capped(): void
    {
        $q = app(QuoteService::class);
        $this->assertSame(20, $q->classifyOcr(5)['credits']);
        $this->assertSame(25, $q->classifyOcr(25)['credits']); // 20 + (25-20) extra pages
        $this->assertEqualsWithDelta(0.5, $q->classifyOcr(25)['cap'], 0.000001);
        $this->assertTrue($q->classifyOcr(61)['declined']);
        $this->assertSame('ocr_page_limit', $q->classifyOcr(61)['reason']);
    }

    public function test_ocr_above_the_page_cap_fails_the_document_before_any_vision_call(): void
    {
        Http::fake();
        $ws = $this->workspace(1000);
        $document = $this->document($ws);
        $this->assertFalse(app(AiCreditAdmission::class)->admitOcr($document, 61));
        $this->assertSame('Failed', $document->fresh()->status);
        $this->assertStringContainsString('OCR limit', $document->fresh()->error_message);
        Http::assertNothingSent();
        $this->assertNull($this->op($document, 'ocr'));
    }

    public function test_ocr_reserves_its_own_operation_and_settles_with_the_document(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws);
        $this->assertTrue(app(AiCreditAdmission::class)->admitOcr($document, 5));
        $this->assertTrue($this->admit($document));
        $this->assertSame([20, 4], [$this->op($document, 'ocr')->amount_reserved, $this->op($document)->amount_reserved]);
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(76, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    public function test_ocr_needs_room_for_the_analysis_too(): void
    {
        $ws = $this->workspace(22); // 20 for OCR but only 2 left for the cheapest analysis (4)
        $document = $this->document($ws);
        $this->assertFalse(app(AiCreditAdmission::class)->admitOcr($document, 5));
        $this->assertStringContainsString('Insufficient credits: 22 available, 24 required.', $document->fresh()->error_message);
    }

    public function test_comparison_quote_is_twelve_credits(): void
    {
        $ws = $this->workspace(100);
        $quote = app(QuoteService::class)->quoteComparison($ws->id, (string) Str::uuid());
        $this->assertSame([12, 'comparison'], [$quote->credits, $quote->band]);
    }

    public function test_quote_is_immutable_and_survives_config_changes_and_provider_variance(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $quote = OperationQuote::firstOrFail();
        config(['ai_credits.bands.standard.credits' => 99, 'ai_credits.quote_version' => 'v2']);
        $this->recordRun($quote, 0.20); // actual provider cost differs from the cap
        $this->assertTrue($this->admit($document)); // retry never re-prices
        $this->assertSame(1, OperationQuote::count());
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(10, (int) $this->op($document)->amount_settled);
        $this->assertSame('v1', $quote->fresh()->quote_version);
        $this->expectException(\LogicException::class);
        $quote->update(['credits' => 1]);
    }

    // ----------------------------------------------- reservation and funding (13-17, 31)

    public function test_multi_credit_reservation_holds_the_exact_amount_without_debiting(): void
    {
        $ws = $this->workspace(25);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $summary = app(EntitlementService::class)->summary($ws->id);
        $this->assertSame(10, $summary['ai_credits']['savedCreditsReserved']);
        $this->assertSame(15, $summary['ai_credits']['savedCreditsAvailable']);
        $this->assertSame(25, (int) $ws->credits()->value('ai_credits_remaining'));
        $this->assertSame(10, $this->ledger($ws->id, 'reserve', 'saved_ai_credit'));
    }

    public function test_monthly_credits_pay_first_and_saved_credits_are_preserved(): void
    {
        $ws = $this->workspace(50);
        $period = $this->subscribe($ws, 100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $this->assertSame('subscription_ai_credit', $this->op($document)->funding_bucket);
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(10, (int) $period->fresh()->ai_credits_used);
        $this->assertSame(50, (int) $ws->credits()->value('ai_credits_remaining'));
        $this->assertSame(10, $this->ledger($ws->id, 'debit', 'subscription_ai_credit'));
        $this->assertSame(0, $this->ledger($ws->id, 'debit', 'saved_ai_credit'));
    }

    public function test_saved_credits_do_not_pay_during_a_subscription_unless_allowed(): void
    {
        $ws = $this->workspace(50);
        $this->subscribe($ws, 5);
        $document = $this->document($ws, 20000);
        $this->assertFalse($this->admit($document)); // 5 monthly < 10; today's rule: saved credits stay untouched
        $this->assertStringContainsString('Insufficient credits: 5 available, 10 required.', $document->fresh()->error_message);

        config(['ai_credits.saved_fallback_during_subscription' => true]);
        $retry = $this->document($ws, 20000);
        $this->assertTrue($this->admit($retry));
        $this->assertSame('saved_ai_credit', $this->op($retry)->funding_bucket);
    }

    public function test_insufficient_credits_block_before_any_paid_call(): void
    {
        Http::fake();
        $ws = $this->workspace(7);
        $document = $this->document($ws, 20000);
        $this->assertFalse($this->admit($document));
        $this->assertSame('Failed', $document->fresh()->status);
        $this->assertSame('Insufficient credits: 7 available, 10 required.', $document->fresh()->error_message);
        $this->assertNull($this->op($document));
        Http::assertNothingSent();
        // And the provider boundary refuses a call that skipped admission entirely.
        $this->expectException(AiProcessingException::class);
        app(OperationSpend::class)->assertCallAllowed($document->fresh(), 'entities');
    }

    public function test_balance_never_goes_negative_when_two_operations_compete(): void
    {
        $ws = $this->workspace(10);
        $a = $this->document($ws, 20000);
        $b = $this->document($ws, 20000);
        $this->assertTrue($this->admit($a));
        $this->assertFalse($this->admit($b));
        app(WorkspaceCreditService::class)->accountForReadyDocument($a->fresh());
        $this->assertSame(0, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    // ------------------------------------------------------- settlement and release (18-26, 45-46)

    public function test_failed_document_releases_its_credits_once(): void
    {
        $ws = $this->workspace(25);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $document->update(['status' => 'Failed']);
        $entitlements = app(EntitlementService::class);
        $entitlements->summary($ws->id);
        $summary = $entitlements->summary($ws->id); // a second summary must not release again
        $this->assertSame('released', $this->op($document)->status);
        $this->assertSame('document_failed', $this->op($document)->release_reason);
        $this->assertSame(25, $summary['ai_credits']['availableCredits']);
        $this->assertSame(10, $this->ledger($ws->id, 'release'));
        $this->assertSame('released', OperationQuote::firstOrFail()->status);
    }

    public function test_needs_review_releases_by_default_and_settles_only_under_an_explicit_policy(): void
    {
        $ws = $this->workspace(40);
        $released = $this->document($ws, 20000);
        $this->assertTrue($this->admit($released));
        $released->update(['status' => 'Needs Review']);
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame('released', $this->op($released)->status);
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));

        config(['ai_credits.needs_review_policy' => 'settle']);
        $paid = $this->document($ws, 20000);
        $this->assertTrue($this->admit($paid));
        $paid->update(['status' => 'Needs Review']);
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame('completed', $this->op($paid)->status);
        $this->assertSame(10, $this->ledger($ws->id, 'debit', 'saved_ai_credit'));
    }

    public function test_merged_evidence_alone_never_debits(): void
    {
        $ws = $this->workspace(40);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh(), false);
        $this->assertSame('reserved', $this->op($document)->status);
        $this->assertNull($document->fresh()->credit_accounted_at);
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));
    }

    public function test_expired_reservation_releases_unless_the_work_was_recovered_into_ready(): void
    {
        $ws = $this->workspace(40);
        $stalled = $this->document($ws, 20000);
        $this->assertTrue($this->admit($stalled));
        DB::table('billing_operations')->where('resource_id', $stalled->id)->update(['updated_at' => now()->subHours(25)]);
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame('reservation_expired', $this->op($stalled)->release_reason);

        $recovered = $this->document($ws, 20000);
        $this->assertTrue($this->admit($recovered));
        app(WorkspaceCreditService::class)->accountForReadyDocument($recovered->fresh());
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame('completed', $this->op($recovered)->status);
    }

    public function test_retries_split_and_synthesis_fallback_never_add_credits(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->admit($document)); // queue retry / restart / duplicate delivery
        }
        $quote = OperationQuote::firstOrFail();
        foreach ([0.01, 0.02, 0.03, 0.04, null] as $cost) { // retries, splits, fallback synthesis, a timeout
            $this->recordRun($quote, $cost);
        }
        $this->assertSame(10, $this->ledger($ws->id, 'reserve'));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    public function test_duplicate_settlement_and_release_each_happen_once(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $accountant = app(CreditAccountant::class);
        $this->assertTrue($accountant->settle('document', $document->id));
        $this->assertFalse($accountant->settle('document', $document->id));
        $this->assertFalse($accountant->release('document', $document->id, 'late_failure')); // a settled charge is never released
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));

        $other = $this->document($ws, 20000);
        $this->assertTrue($this->admit($other));
        $this->assertTrue($accountant->release('document', $other->id, 'x'));
        $this->assertFalse($accountant->release('document', $other->id, 'x'));
        $this->assertFalse($accountant->settle('document', $other->id)); // a released charge is never settled
        $this->assertSame(10, $this->ledger($ws->id, 'release'));
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));
    }

    public function test_explicit_reanalysis_is_charged_once_and_internal_recovery_is_free(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));

        // Recovery / optional retries after Ready: admission is a no-op, nothing new is reserved.
        $this->assertTrue($this->admit($document));
        $this->assertSame(1, DB::table('billing_operations')->count());

        $admission = app(AiCreditAdmission::class);
        $admission->admitReanalysis($document->fresh(), false, $this->user->id);
        $admission->admitReanalysis($document->fresh(), false, $this->user->id); // double click
        $this->assertSame(10, $this->op($document, 'reanalysis')->amount_reserved);
        $this->assertSame(2, OperationQuote::count());
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(80, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    public function test_summary_only_refresh_costs_no_credits(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), true, $this->user->id);
        $this->assertNull($this->op($document, 'reanalysis'));
    }

    public function test_reanalysis_of_a_never_delivered_document_is_a_normal_analysis_not_a_second_purchase(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $document->update(['status' => 'Needs Review']);
        app(EntitlementService::class)->summary($ws->id); // releases
        app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), false, $this->user->id);
        $this->assertNull($this->op($document, 'reanalysis'));
        $this->assertSame(2, $this->op($document)->attempt_number);
        $this->assertSame('reserved', $this->op($document)->status);
    }

    public function test_insufficient_credits_abort_a_reanalysis_without_touching_the_document(): void
    {
        $ws = $this->workspace(10);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        try {
            app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), false, $this->user->id);
            $this->fail('Expected a 402.');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->getStatusCode());
        }
        $this->assertSame('Ready', $document->fresh()->status);
    }

    public function test_large_bands_wait_for_explicit_confirmation_before_any_credit_is_reserved(): void
    {
        Http::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000); // large = 30 credits, confirmation from 'large'
        $this->assertFalse($this->admit($document));
        $this->assertTrue($document->fresh()->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertSame(30, $document->fresh()->ai_pipeline['credit_quote']['credits']);
        $this->assertSame('Processing', $document->fresh()->status);
        $this->assertNull($this->op($document));
        app(AiCreditAdmission::class)->confirmDocument($document->fresh());
        $this->assertTrue($this->admit($document));
        $this->assertSame(30, $this->op($document)->amount_reserved);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------- caps and spend (32-36)

    public function test_provider_cap_blocks_the_next_call_once_spent_and_unknown_usage_counts_conservatively(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1000); // simple = 4 credits = 0.108 USD
        $this->assertTrue($this->admit($document));
        $quote = OperationQuote::firstOrFail();
        $spend = app(OperationSpend::class);
        $this->recordRun($quote, 0.05);
        $spend->assertCallAllowed($document->fresh(), 'entities'); // still inside the cap
        $this->recordRun($quote, null); // a timeout with unknown usage is NOT zero
        $this->assertEqualsWithDelta(0.10, $spend->spentUsd($quote->id), 0.000001);
        $spend->assertCallAllowed($document->fresh(), 'entities');
        $this->recordRun($quote, null);
        $this->expectException(AiProcessingException::class);
        $spend->assertCallAllowed($document->fresh(), 'entities');
    }

    public function test_quote_cap_lowers_but_never_raises_the_incremental_document_budget(): void
    {
        Queue::fake();
        config(['document_intelligence.incremental' => true, 'document_intelligence.large_tokens' => 100]);
        Http::fake();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $ws = $this->workspace(100);
        $capped = $this->document($ws, 1000);
        $quote = app(QuoteService::class)->issue($ws->id, 'document', $capped->id, ['band' => 'simple', 'credits' => 4, 'cap' => 0.04, 'preflight' => []]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->assertTrue(app(IncrementalPipeline::class)->route($capped->fresh()));
        $this->assertEqualsWithDelta(0.04, $capped->fresh()->ai_pipeline['budget_usd'], 0.000001);

        $roomy = $this->document($ws, 1000);
        $quote = app(QuoteService::class)->issue($ws->id, 'document', $roomy->id, ['band' => 'very_large', 'credits' => 4, 'cap' => 50.0, 'preflight' => []]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->assertTrue(app(IncrementalPipeline::class)->route($roomy->fresh()));
        $this->assertLessThan(1.0, $roomy->fresh()->ai_pipeline['budget_usd']); // the existing size formula still governs
    }

    public function test_the_incremental_pipelines_real_synthesis_reservation_is_part_of_the_band_floor(): void
    {
        config(['document_intelligence.incremental' => true, 'document_intelligence.large_tokens' => 100]);
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $ws = $this->workspace(1000);
        $quote = app(QuoteService::class)->quoteDocument($this->document($ws, 1000))['quote'];
        $floor = $quote->preflight['synthesis_floor_usd'];
        $this->assertTrue($quote->preflight['incremental_route']);
        $this->assertGreaterThan(0.1, $floor, 'Even a tiny document reserves primary + fallback + repair synthesis.');
        // At 0.027 USD per credit, 4 credits (0.108) cannot fund that reservation, so the band is not "simple".
        $this->assertNotSame('simple', $quote->band, 'floor='.$floor.' band='.$quote->band);
        $this->assertTrue($quote->preflight['band_shifted_for_cost']);
        $this->assertGreaterThanOrEqual($floor, $quote->provider_cost_cap_usd);
    }

    public function test_legacy_route_documents_are_not_charged_the_incremental_floor(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $ws = $this->workspace(1000);
        $quote = app(QuoteService::class)->quoteDocument($this->document($ws, 1000))['quote'];
        $this->assertFalse($quote->preflight['incremental_route']);
        $this->assertEquals(0.0, $quote->preflight['synthesis_floor_usd']);
        $this->assertSame('simple', $quote->band);
    }

    public function test_visual_analysis_after_ready_is_included_in_the_document_cap(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1000);
        $this->assertTrue($this->admit($document));
        $quote = OperationQuote::firstOrFail();
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->recordRun($quote, 0.11, 'chart_vision'); // spent past the 0.108 cap
        $this->expectException(AiProcessingException::class);
        app(OperationSpend::class)->assertCallAllowed($document->fresh(), 'chart_vision');
    }

    public function test_ocr_and_comparison_and_analysis_spend_are_attributed_to_their_own_operation(): void
    {
        config(['ai_credits.provider_cost_usd_per_credit' => 1.0]);
        $ws = $this->workspace(500);
        $document = $this->document($ws, 1000);
        $admission = app(AiCreditAdmission::class);
        $this->assertTrue($admission->admitOcr($document, 3));
        $this->assertTrue($this->admit($document));
        $comparison = app(QuoteService::class)->quoteComparison($ws->id, $document->id);
        app(CreditAccountant::class)->reserve($comparison, $this->user->id);

        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg', 'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['text' => 'page one', 'confidence' => 0.9])]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]])]);
        $client = app(AnthropicClient::class);
        $client->extractTextFromImage(base64_encode('png'), 'image/png', $document->fresh());
        $client->forOperation($comparison->id, $this->user->id, $document->id)->extractTextFromImage(base64_encode('png'), 'image/png', $document->fresh());

        $runs = DocumentAiRun::orderBy('created_at')->get();
        $this->assertSame($this->op($document, 'ocr')->quote_id, $runs[0]->operation_quote_id);
        $this->assertSame($comparison->id, $runs[1]->operation_quote_id);
        $this->assertSame([$this->user->id, $document->id], [$runs[1]->user_id, $runs[1]->comparison_id]);
        $this->assertEqualsWithDelta(0.0015, app(OperationSpend::class)->spentUsd($comparison->id), 0.000001);
    }

    public function test_provider_boundary_refuses_paid_calls_without_a_reservation_but_not_for_legacy_work(): void
    {
        Http::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1000);
        try {
            app(AnthropicClient::class)->extractTextFromImage(base64_encode('png'), 'image/png', $document->fresh());
            $this->fail('A paid call without credits must be refused.');
        } catch (AiProcessingException $e) {
            $this->assertSame('credits_not_reserved', $e->classification);
        }
        Http::assertNothingSent();
        // Work reserved under the pre-rollout 1-unit rules keeps flowing.
        DB::table('billing_operations')->insert(['workspace_id' => $ws->id, 'kind' => 'document', 'resource_id' => $document->id,
            'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        app(OperationSpend::class)->assertCallAllowed($document->fresh(), 'entities');
        $this->assertTrue(true);
    }

    // ----------------------------------------------------- periods, snapshots and legacy (27-30, 38-42)

    public function test_each_period_has_its_own_monthly_allowance_and_nothing_rolls_over(): void
    {
        $ws = $this->workspace();
        $first = $this->subscribe($ws, 100);
        $first->update(['ai_credits_used' => 30]);
        $second = $first->subscription_id ? SubscriptionUsagePeriod::create(['subscription_id' => $first->subscription_id,
            'period_start' => now()->addMonth(), 'period_end' => now()->addMonths(2), 'documents_allowed' => 20, 'comparisons_allowed' => 5,
            'storage_bytes' => 1, 'ai_credits_allowed' => 100]) : null;
        $this->assertSame(70, app(CreditAccountant::class)->balances($ws->id)['monthly']['remaining']);
        $this->travel(32)->days();
        Subscription::query()->update(['current_period_end' => now()->addMonth()]);
        $balances = app(CreditAccountant::class)->balances($ws->id);
        $this->assertSame($second->id, $balances['monthly']['period_id']);
        $this->assertSame(100, $balances['monthly']['remaining']); // the unused 70 did not carry over
    }

    public function test_annual_purchase_creates_twelve_periods_each_with_the_snapshotted_credits_and_old_contracts_stay_legacy(): void
    {
        $ws = $this->workspace();
        $purchase = $ws->purchases()->create(['user_id' => $this->user->id, 'paystack_reference' => 'r-'.Str::uuid(), 'documents_purchased' => 0,
            'amount_kobo_or_cents' => 1500000, 'currency' => 'KES', 'status' => 'pending', 'plan_key' => 'starter', 'billing_interval' => 'annual',
            'renewal_type' => 'manual', 'paystack_response' => ['data' => ['paid_at' => now()->toIso8601String(), 'id' => 99]], 'billing_metadata' => ['documents' => 20, 'comparisons' => 5, 'storage_bytes' => 1073741824, 'ai_credits' => 90]]);
        app(SubscriptionService::class)->completePurchase($purchase->forceFill(['status' => 'pending']));
        $periods = SubscriptionUsagePeriod::orderBy('period_start')->get();
        $this->assertCount(12, $periods);
        $this->assertSame([90], $periods->pluck('ai_credits_allowed')->unique()->values()->all());
        $this->assertSame(12, DB::table('credit_ledger')->where('unit', 'subscription_ai_credit')->where('direction', 'credit')->count());

        // A changed price list never rewrites a snapshot already taken.
        config(['ai_credits.plans.starter.annual' => 500]);
        $this->assertSame([90], SubscriptionUsagePeriod::pluck('ai_credits_allowed')->unique()->values()->all());
    }

    public function test_period_created_before_rollout_keeps_legacy_one_unit_accounting(): void
    {
        $ws = $this->workspace(100);
        $period = $this->subscribe($ws, null, 20); // purchase-time snapshot without AI credits
        $document = $this->document($ws, 50000);
        $this->assertSame('legacy', app(CreditAccountant::class)->mode($ws->id));
        $this->assertTrue($this->admit($document)); // no quote, no confirmation, no AI credits
        $this->assertSame(0, OperationQuote::count());
        app(EntitlementService::class)->reserveDocument($document->fresh());
        $this->assertNull($this->op($document)->amount_reserved);
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(1, (int) $period->fresh()->documents_used);
        $this->assertSame(0, (int) $period->fresh()->ai_credits_used);
        $this->assertSame(100, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    public function test_legacy_saved_document_units_convert_lazily_without_loss(): void
    {
        $ws = $this->workspace(0, 3); // 3 legacy document units = 30 AI credits
        $summary = app(EntitlementService::class)->summary($ws->id);
        $this->assertSame(30, $summary['ai_credits']['savedCredits']);

        $document = $this->document($ws, 1000); // simple = 4 credits
        $this->assertTrue($this->admit($document));
        $this->assertSame(3, (int) $ws->credits()->value('documents_remaining')); // reserving converts nothing
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $credits = $ws->credits()->first();
        $this->assertSame([2, 6], [(int) $credits->documents_remaining, (int) $credits->ai_credits_remaining]); // one unit became 10, 4 spent
        $this->assertSame(26, app(EntitlementService::class)->summary($ws->id)['ai_credits']['savedCredits']);
        $this->assertSame(1, $this->ledger($ws->id, 'debit', 'saved_document'));
        $this->assertSame(10, $this->ledger($ws->id, 'credit', 'saved_ai_credit'));
    }

    public function test_free_trial_grants_twenty_non_renewing_credits_once_per_identity(): void
    {
        $service = app(WorkspaceCreditService::class);
        $first = User::factory()->create(['email' => 'a@example.com']);
        $w1 = app(WorkspaceService::class)->createPersonalWorkspaceFor($first);
        $this->assertTrue($service->grantTrial($w1, $first, '10.0.0.1', 'fp-1'));
        $this->assertSame(20, (int) $w1->credits()->value('ai_credits_remaining'));
        $this->assertSame(0, (int) $w1->credits()->value('documents_remaining'));
        $this->assertSame(20, (int) DB::table('trial_grants')->value('initial_credits'));

        $second = User::factory()->create(['email' => 'b@example.com']);
        $w2 = app(WorkspaceService::class)->createPersonalWorkspaceFor($second);
        $this->assertFalse($service->grantTrial($w2, $second, '10.0.0.1', 'fp-2')); // same IP
        $this->assertSame(0, (int) $w2->credits()->value('ai_credits_remaining'));
    }

    public function test_ledger_reconciles_with_the_counters(): void
    {
        $ws = $this->workspace(0, 2);
        $period = $this->subscribe($ws, 100);
        app(CreditLedger::class)->record($ws->id, 'period_ai_credits:'.$period->id, 'subscription_ai_credit', 'credit', 100, 'monthly_allowance_created');
        $a = $this->document($ws, 20000);
        $this->assertTrue($this->admit($a));
        app(WorkspaceCreditService::class)->accountForReadyDocument($a->fresh());
        $b = $this->document($ws, 20000);
        $this->assertTrue($this->admit($b));
        $b->update(['status' => 'Failed']);
        app(EntitlementService::class)->summary($ws->id);

        $this->assertSame((int) $period->fresh()->ai_credits_allowed - (int) $period->fresh()->ai_credits_used,
            $this->ledger($ws->id, 'credit', 'subscription_ai_credit') - $this->ledger($ws->id, 'debit', 'subscription_ai_credit'));
        $this->assertSame($this->ledger($ws->id, 'reserve'), $this->ledger($ws->id, 'debit') + $this->ledger($ws->id, 'release'));
    }

    public function test_billing_operation_and_ledger_writes_are_idempotent(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        $accountant = app(CreditAccountant::class);
        $accountant->reserve($quote, $this->user->id);
        $accountant->reserve($quote, $this->user->id);
        $this->assertSame(1, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        $this->assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_reserve:'.$quote->id)->count());
        $ledger = app(CreditLedger::class);
        $ledger->record($ws->id, 'dup:1', 'saved_ai_credit', 'credit', 5, 'x');
        $ledger->record($ws->id, 'dup:1', 'saved_ai_credit', 'credit', 5, 'x');
        $this->assertSame(1, DB::table('credit_ledger')->where('reference', 'dup:1')->count());
    }

    public function test_referral_reward_converts_ten_documents_to_one_hundred_credits(): void
    {
        $this->assertSame(100, config('ai_credits.grants.referral_reward_credits'));
        $this->assertSame(10, config('credits.referral_reward_documents'));
        $this->assertSame(config('credits.referral_reward_documents') * config('ai_credits.legacy_saved_credits_per_document'),
            config('ai_credits.grants.referral_reward_credits'));
    }

    // ---------------------------------------------------------------------- API and Q&A (47-50)

    public function test_credits_api_exposes_ai_credit_fields_alongside_unchanged_legacy_fields(): void
    {
        $ws = $this->workspace(15);
        $this->subscribe($ws, 100)->update(['ai_credits_used' => 28]);
        $data = $this->getJson('/api/workspace/credits')->assertOk()->json('data');
        $this->assertSame([100, 28, 72, 15], [$data['ai_credits']['monthlyCredits'], $data['ai_credits']['monthlyCreditsUsed'],
            $data['ai_credits']['monthlyCreditsRemaining'], $data['ai_credits']['savedCredits']]);
        $this->assertSame(4, $data['ai_credits']['minimumRequiredCredits']);
        $this->assertArrayHasKey('documents_remaining', $data);
        $this->assertArrayHasKey('saved_credits', $data);
    }

    public function test_feature_flag_off_keeps_legacy_behaviour_and_hides_ai_credits(): void
    {
        config(['ai_credits.enabled' => false]);
        $ws = $this->workspace(0, 1);
        $document = $this->document($ws, 50000);
        $this->assertTrue($this->admit($document));
        $this->assertSame(0, OperationQuote::count());
        $this->assertFalse($this->getJson('/api/workspace/credits')->json('data.ai_credits.enabled'));
        app(AnthropicClient::class); // no provider-boundary change
        app(OperationSpend::class)->assertCallAllowed($document, 'entities');
        app(EntitlementService::class)->reserveDocument($document);
        $this->assertNull($this->op($document)->amount_reserved);
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(0, (int) $ws->credits()->value('documents_remaining'));
    }

    public function test_qa_hard_caps_apply_and_every_call_is_attributable(): void
    {
        config(['ai_credits.qa.max_per_user_per_hour' => 2]);
        $ws = $this->workspace(100);
        $user = $this->user->fresh();
        $guard = app(QaGuard::class);
        $this->assertNull($guard->admit($user)); // Q&A is not debited until a price is configured
        $this->assertNull($guard->admit($user));
        try {
            $guard->admit($user);
            $this->fail('The third question in the hour must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }

        // Attribution: the run is recorded against workspace, user and document.
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg', 'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['answer' => 'Revenue grew.', 'confidence' => 'high', 'cited_document_ids' => []])]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 50]])]);
        $document = $this->document($ws, 1000);
        AiPrompt::create(['name' => 'document_qa', 'version' => 99, 'provider' => 'anthropic', 'model' => 'test-model', 'active' => true,
            'template' => 'Question: {{question}} Context: {{document_text}}']);
        $error = null;
        try {
            app(AnthropicClient::class)->forOperation(null, $user->id)->answerDocumentQuestion('What grew?', '[]', [$document->id], $document);
        } catch (\Throwable $e) {
            $error = get_class($e).': '.$e->getMessage(); // the answer shape is irrelevant; the paid call and its run record are the subject
        }
        $run = DocumentAiRun::where('purpose', 'document_qa')->first();
        $this->assertNotNull($run, (string) $error);
        $this->assertSame([$ws->id, $document->id, $user->id], [$run->workspace_id, $run->document_id, $run->user_id]);
    }

    public function test_metered_qa_debits_per_answer_and_releases_failures(): void
    {
        config(['ai_credits.qa.credits' => 2]);
        $ws = $this->workspace(10);
        $guard = app(QaGuard::class);
        $answered = $guard->admit($this->user->fresh());
        $guard->finish($answered, true, $this->user->id);
        $failed = $guard->admit($this->user->fresh());
        $guard->finish($failed, false, $this->user->id);
        $this->assertSame(8, (int) $ws->credits()->value('ai_credits_remaining'));
        $this->assertSame(['settled', 'released'], OperationQuote::orderBy('created_at')->pluck('status')->all());
    }

    public function test_confirmation_endpoint_resumes_a_waiting_large_analysis(): void
    {
        Queue::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));
        $pending = $this->getJson('/api/workspace/credits')->json('data.ai_credits.pendingConfirmations');
        $this->assertSame([['documentId' => $document->id, 'credits' => 30, 'band' => 'large']], $pending);

        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202)->assertJsonPath('data.credits', 30);
        Queue::assertPushedOn('default', ResumeAfterCreditConfirmationJob::class);
        $this->assertTrue($document->fresh()->ai_pipeline['credit_quote_confirmed']);
        $this->assertNull($this->op($document)); // credits are reserved when the resumed job runs, never by the click itself

        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202);
        $other = $this->document($ws, 1000);
        $this->postJson("/api/documents/{$other->id}/confirm-credits")->assertStatus(409);
    }

    public function test_operation_logs_carry_ids_amounts_and_costs_but_never_content(): void
    {
        Log::spy();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $this->recordRun(OperationQuote::firstOrFail(), 0.05);
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($ws) {
            return $message === 'AI credit operation settled'
                && $context['workspace_id'] === $ws->id && $context['quoted_credits'] === 10 && $context['settled_credits'] === 10
                && $context['funding_bucket'] === 'saved_ai_credit' && $context['provider_cost_usd'] === 0.05 && $context['actual_known'] === true
                && ! str_contains(json_encode($context), 'Revenue grew');
        })->once();
    }
}
