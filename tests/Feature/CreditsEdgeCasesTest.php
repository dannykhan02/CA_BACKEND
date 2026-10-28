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
}
