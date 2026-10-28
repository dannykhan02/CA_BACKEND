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
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Edge cases of the credit engine found in the billing audit: one test per bug fixed, plus the cases that were already handled. */
class CreditsEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.settlement_mode' => 'actual', 'ai_credits.admission_min_ratio' => 0.4,
            'ai_credits.saved_fallback_during_subscription' => true, 'ai_credits.settlement_buffer' => 1.25, 'ai_credits.settlement_min' => 1,
            'ai_credits.provider_cost_usd_per_credit' => 0.027, 'document_intelligence.incremental' => false,
            'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false]);
    }

    private function workspace(int $savedAi = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function monthly(Workspace $workspace, int $allowed, int $used = 0): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $allowed, 'ai_credits_used' => $used]);
    }

    private function document(Workspace $workspace, string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    private function quote(Workspace $workspace, Document $document, int $credits = 6): OperationQuote
    {
        return app(QuoteService::class)->issue($workspace->id, 'document', $document->id,
            ['band' => 'regular', 'credits' => $credits, 'cap' => $credits * 0.027, 'preflight' => ['pages' => 3]]);
    }

    private function cost(Workspace $workspace, Document $document, OperationQuote $quote, string $cost): void
    {
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash,
            'purpose' => 'entities', 'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1,
            'status' => 'success', 'estimated_cost_usd' => $cost, 'operation_quote_id' => $quote->id, 'created_at' => now()]);
    }

    private function saved(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining');
    }

    private function trialRow(Workspace $workspace, int $remaining, $expiresAt): void
    {
        WorkspaceCredit::where('workspace_id', $workspace->id)->update(['trial_credits_remaining' => $remaining, 'trial_credits_expires_at' => $expiresAt]);
    }

    // ------------------------------------------------------------------ A21

    public function test_a21_a_fixed_price_operation_never_starts_on_a_partial_hold(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 5); // 40% of a 12-credit comparison is 5
        $comparison = app(QuoteService::class)->issue($workspace->id, 'comparison', (string) Str::uuid(),
            ['band' => 'comparison', 'credits' => 12, 'cap' => 0.324, 'preflight' => ['kind' => 'ai_terms']]);

        try {
            app(CreditAccountant::class)->reserve($comparison, $this->user->id);
            self::fail('A 12-credit comparison must not be held at 5 credits and then charged 5.');
        } catch (HttpException $e) {
            self::assertSame(402, $e->getStatusCode());
            self::assertSame('Insufficient credits: 5 available, 12 required.', $e->getMessage());
        }
        self::assertSame(0, DB::table('billing_operations')->where('quote_id', $comparison->id)->count());

        // A document in the same position may still start on the partial hold.
        $document = $this->document($workspace);
        $documentQuote = $this->quote($workspace, $document, 12);
        app(CreditAccountant::class)->reserve($documentQuote, $this->user->id);
        self::assertSame(5, (int) DB::table('billing_operations')->where('quote_id', $documentQuote->id)->value('amount_reserved'));
    }

    // ------------------------------------------------------------------ A7 and A18: expiry

    public function test_a7_expiry_keeps_the_credits_an_open_hold_needs_and_settlement_never_goes_negative(): void
    {
        $workspace = $this->workspace();
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document, 6);
        $this->trialRow($workspace, 15, now()->addDay());
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        self::assertSame('trial_ai_credit', DB::table('billing_operations')->where('quote_id', $quote->id)->value('funding_bucket'));
        $this->trialRow($workspace, 15, now()->subMinute()); // expires while the hold is open

        self::assertSame(0, app(CreditAccountant::class)->trialAvailable($workspace->id));
        self::assertSame(9, app(CreditAccountant::class)->expireTrial($workspace->id), 'the 6 held credits stay');
        self::assertSame(6, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));

        $this->cost($workspace, $document, $quote, '0.100000'); // 0.1 x 1.25 / 0.027 = 4.63 -> 5 credits
        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);

        self::assertSame(1, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        self::assertSame(5, $quote->fresh()->charged_credits);
    }

    public function test_a18_a_second_expiry_of_a_released_hold_writes_its_own_ledger_row(): void
    {
        $workspace = $this->workspace();
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document, 6);
        $this->trialRow($workspace, 15, now()->addDay());
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->trialRow($workspace, 15, now()->subMinute());
        $accountant = app(CreditAccountant::class);
        self::assertSame(9, $accountant->expireTrial($workspace->id));
        $this->cost($workspace, $document, $quote, '0.030000'); // 2 credits charged, 4 released back into the (expired) pocket
        $accountant->settleDocument($document->id, $this->user->id);
        self::assertSame(4, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));

        self::assertSame(4, $accountant->expireTrial($workspace->id));

        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        $rows = DB::table('credit_ledger')->where('workspace_id', $workspace->id)->where('reason', 'trial_expired')->orderBy('id')->pluck('amount')->all();
        self::assertSame([9, 4], array_map('intval', $rows), 'every expiry that moved credits has its own row');
        self::assertSame(0, $accountant->expireTrial($workspace->id));
    }

    public function test_a18_absorbed_credits_write_exactly_one_info_ledger_row(): void
    {
        $workspace = $this->workspace(100);
        $this->monthly($workspace, 3);
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document, 6);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->cost($workspace, $document, $quote, '0.200000'); // formula 10 credits, hold 3

        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);
        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id); // a duplicate delivery

        $rows = DB::table('credit_ledger')->where('reference', 'ai_absorbed:'.$quote->id)->get();
        self::assertCount(1, $rows);
        self::assertSame('info', $rows[0]->direction);
        self::assertSame(7, (int) $rows[0]->amount);
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_debit:'.$quote->id)->count());
        self::assertSame(0, DB::table('credit_ledger')->where('reference', 'ai_release_unused:'.$quote->id)->count());
    }

    // ------------------------------------------------------------------ A20

    public function test_a20_unusable_settings_fall_back_to_todays_behaviour_and_are_remembered_once(): void
    {
        \App\Support\ConfigEnv::reset();
        $number = fn ($raw) => \App\Support\ConfigEnv::number('AI_CREDITS_ADMISSION_MIN_RATIO', $raw, 1.0, 0.0, 1.0, true);
        self::assertSame(1.0, $number(null));
        self::assertSame(1.0, $number(''));
        self::assertSame(0.4, $number('0.4'));
        self::assertSame(1.0, $number('1'));
        self::assertSame([], \App\Support\ConfigEnv::invalid());
        foreach (['0', '-0.5', '1.5', 'abc', 'NaN'] as $bad) {
            self::assertSame(1.0, $number($bad), $bad);
        }
        self::assertSame(['AI_CREDITS_ADMISSION_MIN_RATIO' => 'not a number'], \App\Support\ConfigEnv::invalid());

        \App\Support\ConfigEnv::reset();
        $integer = fn (string $name, $raw, ?int $default, int $min) => \App\Support\ConfigEnv::integer($name, $raw, $default, $min);
        self::assertSame(15, $integer('AI_CREDITS_FREE_TRIAL_CREDITS', '15', 20, 0));
        self::assertSame(0, $integer('AI_CREDITS_FREE_TRIAL_CREDITS', '0', 20, 0));
        self::assertSame(20, $integer('AI_CREDITS_FREE_TRIAL_CREDITS', '-5', 20, 0), 'negative trial credits');
        self::assertNull($integer('AI_CREDITS_FREE_TRIAL_DAYS', '0', null, 1), 'zero trial days means no expiry, not a one-day trial');
        self::assertNull($integer('AI_CREDITS_FREE_TRIAL_DAYS', '', null, 1));
        self::assertNull($integer('AI_CREDITS_FREE_MAX_QUOTE_TOKENS', 'abc', null, 1), 'a typo must not become a limit of one token');
        self::assertNull($integer('AI_CREDITS_FREE_MAX_QUOTE_TOKENS', '18k', null, 1));
        self::assertNull($integer('AI_CREDITS_FREE_MAX_QUOTE_TOKENS', '1.5', null, 1));
        self::assertSame(18000, $integer('AI_CREDITS_FREE_MAX_QUOTE_TOKENS', '18000', null, 1));
        self::assertSame(['AI_CREDITS_FREE_TRIAL_CREDITS', 'AI_CREDITS_FREE_TRIAL_DAYS', 'AI_CREDITS_FREE_MAX_QUOTE_TOKENS'], array_keys(\App\Support\ConfigEnv::invalid()));
        \App\Support\ConfigEnv::reset();
    }

    public function test_a20_a_ratio_outside_the_range_is_never_applied_even_if_it_reaches_the_engine(): void
    {
        foreach ([0.0, -1.0, 7.0] as $ratio) {
            config(['ai_credits.admission_min_ratio' => $ratio]);
            self::assertSame(6, app(CreditAccountant::class)->requiredToStart(6), (string) $ratio);
        }
        config(['ai_credits.admission_min_ratio' => 0.4]);
        self::assertSame(3, app(CreditAccountant::class)->requiredToStart(6));
    }

    // ------------------------------------------------------------------ A11

    public function test_a11_a_reanalysis_is_held_to_the_plan_size_limit(): void
    {
        config(['ai_credits.plan_limits.free' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => 18000],
            'ai_credits.plan_limits.starter' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => 90000]]);
        $workspace = $this->workspace(500);
        $document = Document::create(['name' => 'Big.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => str_repeat('abc', 20000), 'credit_accounted_at' => now()]);

        try {
            app(\App\Services\AiCredits\AiCreditAdmission::class)->admitReanalysis($document, false, $this->user->id);
            self::fail('A free workspace must not re-analyse a 20,000-token document.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            self::assertSame(403, $e->getResponse()->getStatusCode());
            $body = json_decode($e->getResponse()->getContent(), true);
            self::assertSame('plan_limit', $body['code']);
            self::assertSame('document_size', $body['reason']);
            self::assertSame(20000, $body['quote_tokens']);
            self::assertSame('starter', $body['required_plan']);
        }
        self::assertSame(0, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        self::assertSame(500, $this->saved($workspace));
    }

    // ------------------------------------------------------------------ A19

    public function test_a19_the_status_reconciliation_counts_trial_holds_and_shows_absorbed_credits(): void
    {
        $workspace = $this->workspace();
        $this->trialRow($workspace, 15, now()->addDay());
        $open = $this->document($workspace);
        app(CreditAccountant::class)->reserve($this->quote($workspace, $open, 6), $this->user->id); // stays open
        $done = $this->document($workspace);
        $quote = $this->quote($workspace, $done, 4);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->cost($workspace, $done, $quote, '0.500000'); // formula above the hold: absorbed
        app(CreditAccountant::class)->settleDocument($done->id, $this->user->id);
        $this->trialRow($workspace, 11, now()->subMinute()); // 15 - 4 charged; 6 are still held
        app(CreditAccountant::class)->expireTrial($workspace->id); // expiry rows are not hold lifecycle

        // One capture: 'trial left' and 'absorbed' sit in the same table header write, which expectsOutputToContain cannot match twice.
        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('docintel:ai-credits-status', ['--workspace' => $workspace->id]));
        $output = \Illuminate\Support\Facades\Artisan::output();
        self::assertStringNotContainsString('MISMATCH', $output);
        self::assertStringContainsString('trial left', $output);
        self::assertStringContainsString('absorbed', $output);
        // reserved 10 = debited 4 + released 0 + open 6, then check ok, then the absorbed credits (4 held, formula above it).
        self::assertMatchesRegularExpression('/\|\s*10\s*\|\s*4\s*\|\s*0\s*\|\s*6\s*\|\s*ok\s*\|\s*\d+\s*\|/', $output);
    }

    // ------------------------------------------------------------------ handled cases that must stay handled

    public function test_a1_two_partial_holds_cannot_take_the_same_credits(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 4);
        $accountant = app(CreditAccountant::class);
        $first = $this->quote($workspace, $this->document($workspace), 6);
        $second = $this->quote($workspace, $this->document($workspace), 6);

        $accountant->reserve($first, $this->user->id); // holds all 4 available
        self::assertSame(0, $accountant->balances($workspace->id)['monthly']['remaining'], 'open holds are subtracted from what is available');

        try {
            $accountant->reserve($second, $this->user->id);
            self::fail('The second hold would have taken credits the first already holds.');
        } catch (HttpException $e) {
            self::assertSame('Insufficient credits: 0 available, 3 required.', $e->getMessage());
        }
        self::assertSame(4, (int) DB::table('billing_operations')->where('status', 'reserved')->sum('amount_reserved'));
        self::assertSame(1, DB::table('billing_operations')->count());
    }

    public function test_a1_a_hold_is_reserved_once_when_the_same_quote_is_reserved_twice(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 10);
        $quote = $this->quote($workspace, $this->document($workspace), 6);

        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        app(CreditAccountant::class)->reserve($quote->fresh(), $this->user->id);

        self::assertSame(1, DB::table('billing_operations')->count());
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_reserve:'.$quote->id)->count());
        self::assertSame(4, app(CreditAccountant::class)->balances($workspace->id)['monthly']['remaining']);
    }

    public function test_a6_an_upgrade_does_not_move_held_trial_credits_and_settlement_stays_non_negative(): void
    {
        $workspace = $this->workspace();
        $this->trialRow($workspace, 15, now()->addDays(10));
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document, 6);
        $accountant = app(CreditAccountant::class);
        $accountant->reserve($quote, $this->user->id);
        $this->monthly($workspace, 100); // the first paid subscription starts while the hold is open

        self::assertSame(9, $accountant->carryOverTrial($workspace->id, $this->user->id));
        self::assertSame(0, $accountant->carryOverTrial($workspace->id, $this->user->id));
        $row = WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail();
        self::assertSame(6, (int) $row->trial_credits_remaining, 'the held 6 stay in the trial pocket');
        self::assertSame(9, (int) $row->ai_credits_remaining);

        $this->cost($workspace, $document, $quote, '0.030000'); // 2 credits
        $accountant->settleDocument($document->id, $this->user->id);

        $row = $row->fresh();
        self::assertSame(4, (int) $row->trial_credits_remaining);
        self::assertSame(9, (int) $row->ai_credits_remaining);
        self::assertSame(2, $quote->fresh()->charged_credits);
    }

    public function test_a18_a_pocket_that_is_short_at_settlement_never_goes_negative(): void
    {
        Log::spy();
        $workspace = $this->workspace();
        $this->trialRow($workspace, 15, now()->addDays(10));
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document, 6);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);
        $this->trialRow($workspace, 1, now()->addDays(10)); // a corrupted balance: less than the hold
        $this->cost($workspace, $document, $quote, '0.100000'); // 5 credits

        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);

        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'AI credit settlement exceeded the trial balance.')->once();
    }

    public function test_a18_monthly_usage_never_exceeds_the_allowance(): void
    {
        $workspace = $this->workspace();
        $period = $this->monthly($workspace, 5);
        $accountant = app(CreditAccountant::class);
        $documents = [];
        foreach ([6, 6, 6] as $credits) {
            $document = $this->document($workspace);
            $quote = $this->quote($workspace, $document, $credits);
            try {
                $accountant->reserve($quote, $this->user->id);
                $this->cost($workspace, $document, $quote, '0.500000');
                $accountant->settleDocument($document->id, $this->user->id);
            } catch (HttpException) {
                // refused: nothing to settle
            }
            $documents[] = $document;
        }

        self::assertLessThanOrEqual(5, (int) $period->fresh()->ai_credits_used);
        self::assertGreaterThanOrEqual(0, $accountant->balances($workspace->id)['monthly']['remaining']);
    }
}
