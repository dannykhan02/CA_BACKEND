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
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** "Minimum to start" admission: a hold may be smaller than the quote (settlement mode 'actual' and admission_min_ratio < 1). */
class CreditsAdmissionMinimumTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.settlement_mode' => 'actual', 'ai_credits.admission_min_ratio' => 0.4,
            'ai_credits.saved_fallback_during_subscription' => false, 'ai_credits.settlement_buffer' => 1.25, 'ai_credits.settlement_min' => 1,
            'ai_credits.provider_cost_usd_per_credit' => 0.027]);
    }

    private function workspace(int $savedAi = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    /** A subscription period with exactly $remaining monthly credits left. */
    private function monthly(Workspace $workspace, int $remaining): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $remaining]);
    }

    private function document(Workspace $workspace): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    private function quote(Workspace $workspace, Document $document, int $credits = 6): OperationQuote
    {
        return app(QuoteService::class)->issue($workspace->id, 'document', $document->id,
            ['band' => 'regular', 'credits' => $credits, 'cap' => $credits * 0.027, 'preflight' => ['pages' => 3]]);
    }

    private function refused(OperationQuote $quote): HttpException
    {
        try {
            app(CreditAccountant::class)->reserve($quote, $this->user->id);
        } catch (HttpException $e) {
            self::assertSame(402, $e->getStatusCode());
            self::assertNull(DB::table('billing_operations')->where('quote_id', $quote->id)->first(), 'a refusal holds nothing');
            self::assertSame('quoted', $quote->fresh()->status);

            return $e;
        }
        self::fail('Expected the hold to be refused.');
    }

    private function hold(OperationQuote $quote): object
    {
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        return DB::table('billing_operations')->where('quote_id', $quote->id)->firstOrFail();
    }

    private function cost(Workspace $workspace, Document $document, OperationQuote $quote, string $cost): void
    {
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash,
            'purpose' => 'entities', 'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1,
            'status' => 'success', 'estimated_cost_usd' => $cost, 'operation_quote_id' => $quote->id, 'created_at' => now()]);
    }

    public function test_ratio_one_is_todays_behaviour(): void
    {
        config(['ai_credits.admission_min_ratio' => 1.0]);
        $workspace = $this->workspace();
        $this->monthly($workspace, 5);
        $quote = $this->quote($workspace, $this->document($workspace));

        $this->refused($quote);

        $workspace2 = $this->workspace();
        $this->monthly($workspace2, 6);
        $quote2 = $this->quote($workspace2, $this->document($workspace2));
        $op = $this->hold($quote2);
        self::assertSame(6, (int) $op->amount_reserved);
        self::assertSame(CreditAccountant::MONTHLY, $op->funding_bucket);
    }

    public function test_two_monthly_credits_do_not_start_a_six_credit_quote(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 2);
        $quote = $this->quote($workspace, $this->document($workspace));

        $e = $this->refused($quote);

        self::assertSame('Insufficient credits: 2 available, 3 required.', $e->getMessage());
    }

    public function test_three_monthly_credits_start_a_six_credit_quote_with_a_hold_of_three(): void
    {
        $workspace = $this->workspace();
        $period = $this->monthly($workspace, 3);
        $quote = $this->quote($workspace, $this->document($workspace));

        $op = $this->hold($quote);

        self::assertSame(3, (int) $op->amount_reserved);
        self::assertSame(CreditAccountant::MONTHLY, $op->funding_bucket);
        self::assertSame(6, (int) $quote->fresh()->credits, 'the quote itself does not change');
        self::assertSame(3, $quote->fresh()->held_credits);
        self::assertSame(0, app(CreditAccountant::class)->balances($workspace->id)['monthly']['remaining']);
        self::assertSame(0, (int) $period->fresh()->ai_credits_used, 'a hold moves no balance');
    }

    public function test_the_hold_takes_everything_the_bucket_has_up_to_the_quote(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 5);
        $op = $this->hold($this->quote($workspace, $this->document($workspace)));

        self::assertSame(5, (int) $op->amount_reserved);
    }

    public function test_settling_below_the_hold_charges_actual_cost(): void
    {
        $workspace = $this->workspace();
        $period = $this->monthly($workspace, 3);
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document);
        $this->hold($quote);
        $this->cost($workspace, $document, $quote, '0.035500'); // 0.0355 x 1.25 / 0.027 = 1.64 -> 2

        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);

        $quote = $quote->fresh();
        self::assertSame(2, $quote->charged_credits);
        self::assertSame(1, $quote->released_credits);
        self::assertNull($quote->absorbed_credits);
        self::assertSame(2, (int) $period->fresh()->ai_credits_used);
    }

    public function test_cost_above_the_hold_charges_the_hold_and_records_the_absorbed_credits(): void
    {
        Log::spy();
        $workspace = $this->workspace();
        $period = $this->monthly($workspace, 3);
        $document = $this->document($workspace);
        $quote = $this->quote($workspace, $document);
        $this->hold($quote);
        $this->cost($workspace, $document, $quote, '0.200000'); // 0.2 x 1.25 / 0.027 = 9.26 -> 10; hold 3

        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);

        $quote = $quote->fresh();
        self::assertSame(3, $quote->charged_credits);
        self::assertSame(0, $quote->released_credits);
        self::assertSame(7, $quote->absorbed_credits);
        self::assertSame(3, (int) $period->fresh()->ai_credits_used);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'AI credit settlement absorbed credits'
            && ($context['document_id'] ?? null) === $document->id && ($context['quote_id'] ?? null) === $quote->id
            && ($context['hold'] ?? null) === 3 && ($context['formula_result'] ?? null) === 10)->once();
    }

    public function test_quoted_mode_ignores_the_ratio(): void
    {
        config(['ai_credits.settlement_mode' => 'quoted']);
        $workspace = $this->workspace();
        $this->monthly($workspace, 3);
        $quote = $this->quote($workspace, $this->document($workspace));

        $e = $this->refused($quote);

        self::assertSame('Insufficient credits: 3 available, 6 required.', $e->getMessage());
    }

    public function test_saved_credits_fund_the_hold_when_monthly_is_too_low_and_the_fallback_is_on(): void
    {
        config(['ai_credits.saved_fallback_during_subscription' => true]);
        $workspace = $this->workspace(150);
        $this->monthly($workspace, 2);
        $op = $this->hold($this->quote($workspace, $this->document($workspace)));

        self::assertSame(CreditAccountant::SAVED, $op->funding_bucket);
        self::assertNull($op->usage_period_id);
        self::assertSame(6, (int) $op->amount_reserved);
    }

    public function test_saved_credits_are_not_used_during_a_subscription_when_the_fallback_is_off(): void
    {
        $workspace = $this->workspace(150);
        $this->monthly($workspace, 2);
        $quote = $this->quote($workspace, $this->document($workspace));

        $e = $this->refused($quote);

        self::assertSame('Insufficient credits: 2 available, 3 required.', $e->getMessage());
        self::assertSame(150, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining'));
    }

    public function test_a_hold_is_never_split_across_buckets(): void
    {
        config(['ai_credits.saved_fallback_during_subscription' => true]);
        $workspace = $this->workspace(2);
        $this->monthly($workspace, 2); // 2 + 2 = 4 would pass the minimum of 3 if the buckets were added together
        $quote = $this->quote($workspace, $this->document($workspace));

        $this->refused($quote);
    }

    public function test_saved_credits_alone_can_start_a_partial_hold(): void
    {
        $workspace = $this->workspace(4);
        $op = $this->hold($this->quote($workspace, $this->document($workspace)));

        self::assertSame(CreditAccountant::SAVED, $op->funding_bucket);
        self::assertSame(4, (int) $op->amount_reserved);
    }
}
