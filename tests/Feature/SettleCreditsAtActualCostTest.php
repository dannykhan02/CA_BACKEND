<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\DocumentCredits;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ai_credits.settlement_mode: 'quoted' charges the quote; 'actual' charges the recorded provider cost, capped by the quote. */
class SettleCreditsAtActualCostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.settlement_mode' => 'actual', 'ai_credits.settlement_buffer' => 1.25,
            'ai_credits.settlement_min' => 1, 'ai_credits.provider_cost_usd_per_credit' => 0.027]);
    }

    private function workspace(int $savedAi = 500): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function subscribe(Workspace $workspace, int $aiCredits): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $aiCredits]);
    }

    private function document(Workspace $workspace, string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    /** A reserved quote of $credits for the document; the cap is the quote's credits x 0.027. */
    private function reserved(Workspace $workspace, Document $document, int $credits): OperationQuote
    {
        $quote = app(QuoteService::class)->issue($workspace->id, 'document', $document->id,
            ['band' => 'regular', 'credits' => $credits, 'cap' => $credits * 0.027, 'preflight' => ['pages' => 3]]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        return $quote->fresh();
    }

    private function cost(Workspace $workspace, Document $document, OperationQuote $quote, ?string $cost, string $purpose = 'entities'): void
    {
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash,
            'purpose' => $purpose, 'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1,
            'status' => 'success', 'estimated_cost_usd' => $cost, 'operation_quote_id' => $quote->id, 'created_at' => now()]);
    }

    private function saved(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining');
    }

    private function settle(Document $document): bool
    {
        return app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);
    }

    public function test_quoted_mode_charges_the_quote(): void
    {
        config(['ai_credits.settlement_mode' => 'quoted']);
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');

        $this->settle($document);

        self::assertSame(420, $this->saved($workspace));
        $quote = $quote->fresh();
        self::assertSame('settled', $quote->status);
        self::assertSame(80, $quote->charged_credits);
        self::assertSame(0, $quote->released_credits);
        self::assertSame('quoted', $quote->settlement_basis);
        self::assertSame(80, (int) DB::table('billing_operations')->where('quote_id', $quote->id)->value('amount_settled'));
    }

    public function test_actual_mode_charges_recorded_cost_and_releases_the_rest(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.200000', 'entities');
        $this->cost($workspace, $document, $quote, '0.221200', 'document_summary');

        $this->settle($document);

        $quote = $quote->fresh();
        self::assertSame(20, $quote->charged_credits);
        self::assertSame(60, $quote->released_credits);
        self::assertSame('actual', $quote->settlement_basis);
        self::assertSame('0.421200', $quote->actual_cost_usd);
        self::assertSame(480, $this->saved($workspace));
        self::assertSame(20, (int) DB::table('billing_operations')->where('quote_id', $quote->id)->value('amount_settled'));
    }

    public function test_actual_mode_rounds_up_to_whole_credits(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 10);
        $this->cost($workspace, $document, $quote, '0.061400');

        $this->settle($document);

        self::assertSame(3, $quote->fresh()->charged_credits);
        self::assertSame(7, $quote->fresh()->released_credits);
    }

    public function test_a_tiny_cost_still_charges_the_minimum(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 10);
        $this->cost($workspace, $document, $quote, '0.000100');

        $this->settle($document);

        self::assertSame(1, $quote->fresh()->charged_credits);
        self::assertSame(9, $quote->fresh()->released_credits);
        self::assertSame(499, $this->saved($workspace));
    }

    public function test_cost_above_the_quote_is_capped_at_the_quote(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 10);
        $this->cost($workspace, $document, $quote, '0.900000');

        $this->settle($document);

        self::assertSame(10, $quote->fresh()->charged_credits);
        self::assertSame(0, $quote->fresh()->released_credits);
        self::assertSame(490, $this->saved($workspace));
    }

    public function test_no_recorded_cost_falls_back_to_the_quote_and_warns(): void
    {
        Log::spy();
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 10);

        $this->settle($document);

        $quote = $quote->fresh();
        self::assertSame(10, $quote->charged_credits);
        self::assertSame(0, $quote->released_credits);
        self::assertSame('quoted_fallback', $quote->settlement_basis);
        self::assertSame(490, $this->saved($workspace));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'fell back')
            && ($context['document_id'] ?? null) === $document->id && ($context['reason'] ?? null) === 'no_recorded_cost')->once();
    }

    public function test_a_run_with_unknown_cost_falls_back_to_the_quote(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 10);
        $this->cost($workspace, $document, $quote, '0.010000');
        $this->cost($workspace, $document, $quote, null, 'risks');

        $this->settle($document);

        self::assertSame('quoted_fallback', $quote->fresh()->settlement_basis);
        self::assertSame(10, $quote->fresh()->charged_credits);
    }

    public function test_settling_twice_charges_and_releases_once(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');

        self::assertTrue($this->settle($document));
        self::assertFalse($this->settle($document));

        self::assertSame(480, $this->saved($workspace));
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_debit:'.$quote->id)->count());
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_release_unused:'.$quote->id)->count());
        self::assertSame(60, (int) DB::table('credit_ledger')->where('reference', 'ai_release_unused:'.$quote->id)->value('amount'));
    }

    public function test_the_unused_credits_stay_in_the_pool_that_funded_the_hold(): void
    {
        // Monthly credits fund the hold: only the monthly counter moves, the saved balance is untouched.
        $workspace = $this->workspace(500);
        $period = $this->subscribe($workspace, 100);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');
        self::assertSame(CreditAccountant::MONTHLY, $quote->funding_bucket);

        $this->settle($document);

        self::assertSame(20, (int) $period->fresh()->ai_credits_used);
        self::assertSame(500, $this->saved($workspace));
        self::assertSame(CreditAccountant::MONTHLY, DB::table('credit_ledger')->where('reference', 'ai_release_unused:'.$quote->id)->value('unit'));
        self::assertSame(80, app(CreditAccountant::class)->balances($workspace->id)['monthly']['remaining']);

        // Saved credits fund it when there is no subscription period.
        $other = $this->workspace(300);
        $second = $this->document($other);
        $savedQuote = $this->reserved($other, $second, 40);
        $this->cost($other, $second, $savedQuote, '0.270000');
        self::assertSame(CreditAccountant::SAVED, $savedQuote->funding_bucket);

        $this->settle($second);

        self::assertSame(288, $this->saved($other)); // 0.27 x 1.25 / 0.027 = 12.5 -> 13 credits
        self::assertSame(CreditAccountant::SAVED, DB::table('credit_ledger')->where('reference', 'ai_release_unused:'.$savedQuote->id)->value('unit'));
    }

    public function test_a_partial_document_is_not_charged_and_its_hold_is_released_whole(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'coverage' => ['failed_chunks' => 2]]])->save();

        DB::transaction(fn () => app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh()));

        $quote = $quote->fresh();
        self::assertSame('released', $quote->status);
        self::assertNull($quote->charged_credits);
        self::assertSame(500, $this->saved($workspace));
    }

    public function test_a_failed_document_release_is_unchanged(): void
    {
        $workspace = $this->workspace(500);
        $document = $this->document($workspace);
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');

        app(CreditAccountant::class)->releaseDocument($document->id, 'analysis_failed');

        self::assertSame('released', $quote->fresh()->status);
        self::assertSame(500, $this->saved($workspace));
    }

    public function test_monthly_usage_counts_charged_credits_not_quoted_credits(): void
    {
        $workspace = $this->workspace(0);
        $period = $this->subscribe($workspace, 100);
        $document = $this->document($workspace, 'Ready');
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.061400');

        $this->settle($document);

        self::assertSame(3, (int) $period->fresh()->ai_credits_used);
        app()->forgetScopedInstances();
        $credits = app(DocumentCredits::class)->for($document->fresh());
        self::assertSame(80, $credits['quotedCredits']);
        self::assertSame(3, $credits['chargedCredits']);
        self::assertSame(77, $credits['releasedCredits']);
        self::assertSame('actual', $credits['settlementBasis']);
        self::assertSame(3, $credits['charged']);
        self::assertSame(80, $credits['quoted']);
    }

    public function test_the_preview_command_writes_nothing(): void
    {
        config(['ai_credits.settlement_mode' => 'quoted']);
        $workspace = $this->workspace(500);
        $document = $this->document($workspace, 'Ready');
        $quote = $this->reserved($workspace, $document, 80);
        $this->cost($workspace, $document, $quote, '0.421200');
        $before = [OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(), $this->saved($workspace)];
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|with)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $exit = Artisan::call('credits:settlement-preview', ['--days' => 7]);
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertSame([], $writes);
        self::assertSame($before, [OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(), $this->saved($workspace)]);
        self::assertSame('reserved', $quote->fresh()->status);
        self::assertStringContainsString($document->id, $output);
        self::assertStringContainsString('Nothing was changed.', $output);
    }
}
