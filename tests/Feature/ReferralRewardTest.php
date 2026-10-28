<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Referral;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\ReferralService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The referral reward: today's trigger by default, and the first_payment trigger (first paid subscription) when configured. */
class ReferralRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0, 0, 0));
        config(['services.paystack.secret_key' => 'sk_test_referral_rewards', 'ai_credits.enabled' => true]);
        foreach (['starter', 'professional'] as $plan) {
            foreach (['monthly', 'annual'] as $interval) {
                config(["billing.plans.$plan.plan_codes.$interval" => "PLN_{$plan}_{$interval}"]);
            }
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/transaction/initialize' => fn ($r) => Http::response(['status' => true, 'data' => ['reference' => $r['reference'], 'authorization_url' => 'https://checkout.paystack.com/test']])]);
    }

    private function user(): array
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 0]);

        return [$user->fresh(), $workspace];
    }

    /** A pending, eligible referral of $referred by $referrer. */
    private function refer(User $referrer, User $referred, bool $eligible = true): Referral
    {
        return Referral::create(['referral_code_id' => app(ReferralService::class)->codeFor($referrer)->id, 'referred_user_id' => $referred->id,
            'status' => 'pending', 'reward_eligible' => $eligible, 'ineligible_reason' => $eligible ? null : 'trial_abuse_signal_match']);
    }

    /** The referred user pays for a subscription through the real checkout and webhook. */
    private function subscribe(User $buyer): void
    {
        Sanctum::actingAs($buyer);
        $this->postJson('/api/workspace/credits/purchases', ['plan' => 'starter', 'interval' => 'monthly', 'renewal' => 'manual'])->assertCreated();
        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $raw = json_encode(['event' => 'charge.success', 'data' => ['id' => $purchase->id + 1000, 'reference' => $purchase->paystack_reference,
            'status' => 'success', 'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency, 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_test'], 'plan' => $purchase->provider_plan_code]]);
        $this->call('POST', '/api/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_referral_rewards')], $raw)->assertOk();
    }

    private function saved(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining');
    }

    public function test_the_defaults_are_todays_trigger_and_amount(): void
    {
        self::assertSame('first_purchase', config('ai_credits.grants.referral_reward_trigger'));
        self::assertSame(100, config('ai_credits.grants.referral_reward_credits'));
    }

    public function test_by_default_the_first_payment_rewards_the_referrer_100_saved_credits_once(): void
    {
        [$referrer, $referrerWorkspace] = $this->user();
        [$referred] = $this->user();
        $referral = $this->refer($referrer, $referred);

        $this->subscribe($referred);

        self::assertSame(100, $this->saved($referrerWorkspace));
        self::assertSame('rewarded', $referral->fresh()->status);
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'referral:'.$referral->id)->count());
    }

    public function test_the_first_payment_trigger_rewards_the_configured_amount_once_per_referral(): void
    {
        config(['ai_credits.grants.referral_reward_trigger' => 'first_payment', 'ai_credits.grants.referral_reward_credits' => 30]);
        [$referrer, $referrerWorkspace] = $this->user();
        [$referred] = $this->user();
        $referral = $this->refer($referrer, $referred);

        $this->subscribe($referred);
        self::assertSame(30, $this->saved($referrerWorkspace));
        self::assertSame('rewarded', $referral->fresh()->status);
        self::assertSame($referrerWorkspace->id, $referral->fresh()->rewarded_workspace_id);

        $this->subscribe($referred); // a renewal or another payment
        self::assertSame(30, $this->saved($referrerWorkspace));
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'referral:'.$referral->id)->count());
    }

    public function test_the_first_payment_trigger_does_not_reward_an_ineligible_referral(): void
    {
        config(['ai_credits.grants.referral_reward_trigger' => 'first_payment', 'ai_credits.grants.referral_reward_credits' => 30]);
        [$referrer, $referrerWorkspace] = $this->user();
        [$referred] = $this->user();
        $referral = $this->refer($referrer, $referred, eligible: false); // same network or device as an earlier trial at signup

        $this->subscribe($referred);

        self::assertSame(0, $this->saved($referrerWorkspace));
        self::assertSame('pending', $referral->fresh()->status);
    }

    public function test_the_first_payment_trigger_rejects_self_referral(): void
    {
        config(['ai_credits.grants.referral_reward_trigger' => 'first_payment', 'ai_credits.grants.referral_reward_credits' => 30]);
        [$user, $workspace] = $this->user();
        $referral = $this->refer($user, $user); // the code owner is the payer

        $this->subscribe($user);

        self::assertSame(0, $this->saved($workspace));
        $referral = $referral->fresh();
        self::assertSame('pending', $referral->status);
        self::assertFalse($referral->reward_eligible);
        self::assertSame('self_referral', $referral->ineligible_reason);
    }

    public function test_the_first_payment_trigger_skips_the_old_first_purchase_path_when_no_subscription_is_active_in_credit_mode(): void
    {
        config(['ai_credits.grants.referral_reward_trigger' => 'first_payment', 'ai_credits.grants.referral_reward_credits' => 30, 'ai_credits.enabled' => false]);
        [$referrer, $referrerWorkspace] = $this->user();
        [$referred] = $this->user();
        $referral = $this->refer($referrer, $referred);

        $this->subscribe($referred); // credits off: legacy period, so no credit-mode subscription became active

        self::assertSame(0, $this->saved($referrerWorkspace));
        self::assertSame('pending', $referral->fresh()->status);
    }
}
