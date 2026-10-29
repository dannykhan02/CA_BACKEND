<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Professional is 300 credits a month; periods created after the change get it, and the command raises existing current/future periods. */
class PlanAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0, 0, 0));
        config(['services.paystack.secret_key' => 'sk_test_allowance', 'ai_credits.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/transaction/initialize' => fn ($r) => Http::response(['status' => true, 'data' => ['reference' => $r['reference'], 'authorization_url' => 'https://checkout.paystack.com/test']])]);
    }

    private function buy(string $plan, string $interval): CreditPurchase
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/workspace/credits/purchases', ['plan' => $plan, 'interval' => $interval, 'renewal' => 'manual'])->assertCreated();
        $purchase = CreditPurchase::latest('id')->first();
        $raw = json_encode(['event' => 'charge.success', 'data' => ['id' => $purchase->id + 1000, 'reference' => $purchase->paystack_reference, 'status' => 'success',
            'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency, 'paid_at' => now()->toIso8601String(), 'customer' => ['customer_code' => 'CUS_allow']]]);
        $this->call('POST', '/api/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_allowance')], $raw)->assertOk();

        return $purchase->fresh();
    }

    private function subscription(string $plan, array $periods, string $status = 'active'): Subscription
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'plan_key' => $plan, 'billing_interval' => 'monthly',
            'status' => $status, 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonths(3)]);
        foreach ($periods as [$start, $end, $allowed]) {
            $sub->periods()->create(['period_start' => $start, 'period_end' => $end, 'documents_allowed' => 100, 'comparisons_allowed' => 30,
                'storage_bytes' => 1073741824, 'ai_credits_allowed' => $allowed]);
        }

        return $sub;
    }

    public function test_professional_is_300_monthly_and_annual_and_starter_is_unchanged(): void
    {
        $this->assertSame([300, 300, 100, 100], [config('ai_credits.plans.professional.monthly'), config('ai_credits.plans.professional.annual'),
            config('ai_credits.plans.starter.monthly'), config('ai_credits.plans.starter.annual')]);
    }

    public function test_a_new_monthly_professional_period_gets_300(): void
    {
        $this->buy('professional', 'monthly');

        $this->assertSame([300], SubscriptionUsagePeriod::pluck('ai_credits_allowed')->all());
    }

    public function test_an_annual_professional_purchase_creates_twelve_periods_of_300(): void
    {
        $this->buy('professional', 'annual');

        $this->assertSame(12, SubscriptionUsagePeriod::count());
        $this->assertSame([300], SubscriptionUsagePeriod::pluck('ai_credits_allowed')->unique()->values()->all());
    }

    /** An automatic renewal: Paystack charges the stored subscription; the stored snapshot says $snapshot credits. */
    private function renewWithSnapshot(int $snapshot): SubscriptionUsagePeriod
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $start = now()->subMonth();
        $allowance = ['documents' => 100, 'comparisons' => 30, 'storage_bytes' => 1073741824, 'ai_credits' => $snapshot];
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'plan_key' => 'professional', 'billing_interval' => 'monthly',
            'status' => 'active', 'current_period_start' => $start, 'current_period_end' => now(), 'provider' => 'paystack', 'auto_renews' => true,
            'provider_customer_code' => 'CUS_renew', 'provider_plan_code' => 'PLN_renew', 'provider_subscription_code' => 'SUB_renew',
            'metadata' => ['last_paid_at' => $start->toIso8601String(), 'amount' => 350000, 'currency' => 'KES', 'renewal_requested' => 'automatic', 'allowance' => $allowance]]);
        $sub->periods()->create(['period_start' => $start, 'period_end' => now(), 'documents_allowed' => 100, 'comparisons_allowed' => 30,
            'storage_bytes' => 1073741824, 'ai_credits_allowed' => $snapshot]);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['id' => 777001, 'reference' => 'renewal-'.$snapshot, 'status' => 'success', 'amount' => 350000,
            'currency' => 'KES', 'paid_at' => now()->toIso8601String(), 'plan' => 'PLN_renew', 'customer' => ['customer_code' => 'CUS_renew'],
            'subscription' => ['subscription_code' => 'SUB_renew']]]);
        $this->call('POST', '/api/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_allowance')], $raw)->assertOk();

        $renewed = $sub->periods()->where('period_start', '>=', now()->subMinute())->get();
        $this->assertCount(1, $renewed);
        $this->assertSame($snapshot, $sub->fresh()->metadata['allowance']['ai_credits']);

        return $renewed->first();
    }

    public function test_a_renewal_with_an_old_snapshot_gets_the_higher_current_allowance(): void
    {
        config(['ai_credits.plans.professional.monthly' => 300]);

        $this->assertSame(300, $this->renewWithSnapshot(250)->ai_credits_allowed);
    }

    public function test_a_renewal_never_lowers_a_higher_snapshot(): void
    {
        config(['ai_credits.plans.professional.monthly' => 250]);

        $this->assertSame(300, $this->renewWithSnapshot(300)->ai_credits_allowed);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $this->subscription('professional', [[now()->subDay(), now()->addMonth(), 250]]);

        $this->artisan('credits:apply-plan-allowance', ['plan' => 'professional'])
            ->expectsOutputToContain('Nothing was changed.')->assertExitCode(0);

        $this->assertSame([250], SubscriptionUsagePeriod::pluck('ai_credits_allowed')->all());
    }

    public function test_apply_raises_current_and_future_periods_only_and_never_lowers_or_touches_legacy(): void
    {
        $sub = $this->subscription('professional', [
            [now()->subMonths(2), now()->subMonth(), 250],   // past
            [now()->subDay(), now()->addMonth(), 250],        // current
            [now()->addMonth(), now()->addMonths(2), 250],    // future
            [now()->addMonths(2), now()->addMonths(3), 400],  // higher than configured
            [now()->addMonths(3), now()->addMonths(4), null], // legacy
        ]);
        $this->subscription('starter', [[now()->subDay(), now()->addMonth(), 50]]);

        $this->artisan('credits:apply-plan-allowance', ['plan' => 'professional', '--apply' => true])->assertExitCode(0);

        $this->assertSame([250, 300, 300, 400, null], $sub->periods()->orderBy('period_start')->pluck('ai_credits_allowed')->all());
        $this->assertSame([50], SubscriptionUsagePeriod::whereKeyNot($sub->periods()->pluck('id'))->pluck('ai_credits_allowed')->all());
    }

    public function test_a_second_apply_changes_nothing(): void
    {
        $sub = $this->subscription('professional', [[now()->subDay(), now()->addMonth(), 250]]);
        $this->artisan('credits:apply-plan-allowance', ['plan' => 'professional', '--apply' => true])->assertExitCode(0);

        $this->artisan('credits:apply-plan-allowance', ['plan' => 'professional', '--apply' => true])
            ->expectsOutputToContain('0 period(s) raised')->assertExitCode(0);

        $this->assertSame([300], $sub->periods()->pluck('ai_credits_allowed')->all());
    }

    public function test_an_unknown_plan_exits_1(): void
    {
        $this->artisan('credits:apply-plan-allowance', ['plan' => 'enterprise'])->assertExitCode(1);
    }
}
