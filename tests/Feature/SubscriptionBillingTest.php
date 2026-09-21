<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0, 0, 0));
        config(['services.paystack.secret_key' => 'sk_test_subscriptions']);
        foreach (['starter', 'professional'] as $plan) {
            foreach (['monthly', 'annual'] as $interval) {
                config(["billing.plans.$plan.plan_codes.$interval" => "PLN_{$plan}_{$interval}"]);
            }
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/transaction/initialize' => fn ($r) => Http::response(['status' => true, 'data' => ['reference' => $r['reference'], 'authorization_url' => 'https://checkout.paystack.com/test']])]);
    }

    private function workspace(int $credits = 5)
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => $credits]);
        Sanctum::actingAs($user->fresh());

        return $workspace;
    }

    private function checkout(string $plan = 'starter', string $interval = 'monthly', string $renewal = 'manual'): CreditPurchase
    {
        $this->postJson('/api/workspace/credits/purchases', compact('plan', 'interval', 'renewal'))->assertCreated();

        return CreditPurchase::latest('id')->first();
    }

    private function event(string $event, array $data)
    {
        $raw = json_encode(compact('event', 'data'));

        return $this->call('POST', '/api/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_subscriptions')], $raw);
    }

    private function charge(CreditPurchase $p, array $overrides = [])
    {
        return $this->event('charge.success', [...['id' => $p->id + 1000, 'reference' => $p->paystack_reference, 'status' => 'success',
            'amount' => $p->amount_kobo_or_cents, 'currency' => $p->currency, 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_test'], 'plan' => $p->provider_plan_code], ...$overrides]);
    }

    public static function plans(): array
    {
        return [['starter', 'monthly', 150000, 20, 5, 1], ['starter', 'annual', 1500000, 20, 5, 12], ['professional', 'monthly', 350000, 100, 30, 1], ['professional', 'annual', 3500000, 100, 30, 12]];
    }

    #[DataProvider('plans')]
    public function test_four_plans_use_server_prices_and_monthly_allowances(string $plan, string $interval, int $amount, int $documents, int $comparisons, int $periods): void
    {
        $workspace = $this->workspace();
        $purchase = $this->checkout($plan, $interval, 'automatic');
        $this->assertSame($amount, $purchase->amount_kobo_or_cents);
        Http::assertSent(fn ($r) => $r['amount'] === (string) $amount && $r['plan'] === "PLN_{$plan}_{$interval}" && $r['channels'] === ['card']);
        $this->charge($purchase)->assertOk();
        $this->assertDatabaseCount('subscription_usage_periods', $periods);
        $state = app(EntitlementService::class)->summary($workspace->id);
        $this->assertSame($documents, $state['usage']['documents_allowed']);
        $this->assertSame($comparisons, $state['usage']['comparisons_allowed']);
        $this->assertSame(5, $state['saved_credits']);
        $this->assertFalse($state['subscription']['auto_renews']);
        $this->getJson('/api/billing/plans')->assertOk()->assertJsonMissing(['plan_codes' => []]);
    }

    public function test_untrusted_prices_and_plan_codes_and_old_pack_checkout_are_rejected(): void
    {
        $this->workspace();
        foreach (['amount' => 1, 'price' => 1, 'plan_code' => 'PLN_evil'] as $key => $value) {
            $this->postJson('/api/workspace/credits/purchases', ['plan' => 'starter', 'interval' => 'monthly', 'renewal' => 'automatic', $key => $value])->assertUnprocessable();
        }
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_manual_payments_extend_once_and_preserve_period_history(): void
    {
        $workspace = $this->workspace();
        $purchase = $this->checkout();
        Http::assertSent(fn ($r) => ! isset($r['plan']));
        $this->charge($purchase)->assertOk();
        $end = Subscription::sole()->current_period_end;
        $this->charge($purchase)->assertOk();
        $this->assertTrue(Subscription::sole()->current_period_end->equalTo($end));
        $this->assertSame(now()->addMonthNoOverflow()->toIso8601String(), $end->toIso8601String());
        $this->travel(1)->days();
        $second = $this->checkout();
        $this->charge($second)->assertOk();
        $this->assertTrue(Subscription::sole()->current_period_end->equalTo($end->addMonthNoOverflow()));
        $this->assertDatabaseCount('subscription_usage_periods', 2);
        $this->assertSame(20, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
    }

    public function test_annual_manual_payment_is_one_year_and_no_duplicate_periods(): void
    {
        $this->workspace();
        $purchase = $this->checkout('professional', 'annual');
        $this->charge($purchase)->assertOk();
        $this->charge($purchase)->assertOk();
        $this->assertSame(now()->addYearNoOverflow()->toIso8601String(), Subscription::sole()->current_period_end->toIso8601String());
        $this->assertFalse(Subscription::sole()->auto_renews);
        $this->assertDatabaseCount('subscription_usage_periods', 12);
    }

    private function providerCreated(): array
    {
        return ['subscription_code' => 'SUB_test', 'email_token' => 'secret-token', 'customer' => ['customer_code' => 'CUS_test'],
            'plan' => ['plan_code' => 'PLN_starter_monthly'], 'next_payment_date' => now()->addMonth()->toIso8601String()];
    }

    public function test_out_of_order_create_first_payment_recurring_charge_and_duplicates(): void
    {
        $workspace = $this->workspace();
        $purchase = $this->checkout('starter', 'monthly', 'automatic');
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->charge($purchase)->assertOk();
        $this->assertTrue(Subscription::sole()->auto_renews);
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->travel(1)->months();
        $renewal = ['id' => 9000, 'reference' => 'recurring-1', 'status' => 'success', 'amount' => 150000, 'currency' => 'KES',
            'paid_at' => now()->toIso8601String(), 'customer' => ['customer_code' => 'CUS_test'], 'plan' => ['plan_code' => 'PLN_starter_monthly']];
        $this->event('charge.success', $renewal)->assertOk();
        $this->event('charge.success', $renewal)->assertOk();
        $this->assertDatabaseCount('credit_purchases', 2);
        $this->assertDatabaseCount('subscription_usage_periods', 2);
        $this->assertSame(20, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->getJson('/api/workspace/billing')->assertOk()->assertDontSee('secret-token')->assertDontSee('sk_test');
    }

    public function test_failure_nonrenewal_disable_and_expiry_keep_paid_access_until_end(): void
    {
        $workspace = $this->workspace(0);
        $purchase = $this->checkout('starter', 'monthly', 'automatic');
        $this->charge($purchase)->assertOk();
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        $data = ['subscription' => ['subscription_code' => 'SUB_test'], 'period_start' => now()->toIso8601String()];
        $this->event('invoice.create', $data)->assertOk();
        $this->assertSame('active', Subscription::sole()->status);
        $this->event('invoice.payment_failed', $data)->assertOk();
        $this->assertSame('past_due', Subscription::sole()->status);
        $this->event('subscription.not_renew', ['subscription_code' => 'SUB_test'])->assertOk();
        $this->assertSame('non_renewing', Subscription::sole()->status);
        $this->event('subscription.disable', ['subscription_code' => 'SUB_test'])->assertOk();
        $this->assertTrue(app(EntitlementService::class)->summary($workspace->id)['paid_access']);
        $this->travel(32)->days();
        $this->assertSame('cancelled', app(EntitlementService::class)->summary($workspace->id)['subscription']['status']);
        $this->postJson('/api/documents/query', ['question' => 'What changed?'])->assertStatus(402);
        $this->getJson('/api/documents')->assertOk();
    }

    public function test_cancellation_is_owner_scoped_and_preserves_end(): void
    {
        $workspace = $this->workspace();
        $purchase = $this->checkout();
        $this->charge($purchase)->assertOk();
        $end = Subscription::sole()->current_period_end;
        $owner = $workspace->users()->first();
        $stranger = User::factory()->create(['current_workspace_id' => $workspace->id]);
        Sanctum::actingAs($stranger);
        $this->getJson('/api/workspace/billing')->assertForbidden();
        $this->postJson('/api/workspace/billing/cancel')->assertForbidden();
        $workspace->members()->create(['user_id' => $stranger->id, 'joined_at' => now()]);
        $this->postJson('/api/workspace/billing/cancel')->assertForbidden();
        Sanctum::actingAs($owner);
        $this->postJson('/api/workspace/billing/cancel')->assertOk();
        $this->postJson('/api/workspace/billing/cancel')->assertOk();
        $this->assertTrue(Subscription::sole()->current_period_end->equalTo($end));
        $this->assertSame('non_renewing', Subscription::sole()->status);
    }

    private function document($workspace): Document
    {
        return Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $workspace->users()->first()->id,
            'name' => 'Contract.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Public', 'year' => 2026, 'size_kb' => 1]);
    }

    public function test_usage_is_once_per_document_paid_first_and_annual_months_do_not_roll_over(): void
    {
        $workspace = $this->workspace(10);
        $purchase = $this->checkout('starter', 'annual');
        $this->charge($purchase)->assertOk();
        $document = $this->document($workspace);
        $service = app(EntitlementService::class);
        $service->reserveDocument($document);
        $service->reserveDocument($document);
        $this->assertSame(19, $service->summary($workspace->id)['documents_remaining']);
        DB::transaction(function () use ($document) {
            app(WorkspaceCreditService::class)->accountForReadyDocument($document);
            $document->save();
        });
        DB::transaction(function () use ($document) {
            app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        });
        $this->assertSame(1, $service->summary($workspace->id)['usage']['documents_used']);
        $this->assertSame(10, $workspace->credits->documents_remaining);
        $this->travel(1)->months();
        $this->assertSame(20, $service->summary($workspace->id)['documents_remaining']);
        $this->assertSame(1, Subscription::sole()->periods()->sum('documents_used'));
        $this->travel(1)->years();
        $this->assertSame(10, $service->summary($workspace->id)['documents_remaining']);
        $this->assertSame('expired', $service->summary($workspace->id)['subscription']['status']);
    }

    public function test_free_balance_never_refreshes_and_exhaustion_blocks_direct_jobs(): void
    {
        $workspace = $this->workspace(0);
        $this->travel(2)->months();
        $this->assertSame(0, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $document = $this->document($workspace);
        $this->expectException(HttpException::class);
        app(EntitlementService::class)->reserveDocument($document);
    }

    public function test_reservations_stop_overspending_and_failures_release_capacity(): void
    {
        $workspace = $this->workspace(1);
        $first = $this->document($workspace);
        app(EntitlementService::class)->reserveDocument($first);
        $this->assertSame(0, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $first->update(['status' => 'Failed']);
        $this->assertSame(1, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        app(EntitlementService::class)->reserveDocument($this->document($workspace));
        $this->assertSame(0, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->assertSame(1, $workspace->credits->documents_remaining);
    }

    public function test_storage_limits_block_uploads_without_hiding_data(): void
    {
        $workspace = $this->workspace();
        config(['billing.free_storage_bytes' => 1024]);
        $this->document($workspace);
        Queue::fake();
        $this->postJson('/api/documents', ['file' => UploadedFile::fake()->create('extra.pdf', 2, 'application/pdf'), 'classification' => 'Public'])->assertStatus(402);
        $this->getJson('/api/documents')->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_only_verified_live_legacy_purchasers_get_ongoing_starter_without_charges(): void
    {
        $workspace = $this->workspace(67);
        $legacy = $workspace->purchases()->create(['user_id' => $workspace->users()->first()->id,
            'paystack_reference' => 'old-live', 'documents_purchased' => 100, 'amount_kobo_or_cents' => 200000,
            'currency' => 'KES', 'status' => 'completed', 'paystack_response' => ['data' => ['status' => 'success', 'domain' => 'test']]]);
        $service = app(EntitlementService::class);
        $this->assertNull($service->summary($workspace->id)['subscription']);
        $legacy->update(['paystack_response' => ['data' => ['status' => 'success', 'domain' => 'live']]]);
        $state = $service->summary($workspace->id);
        $this->assertTrue($state['subscription']['grandfathered']);
        $this->assertSame('starter', $state['subscription']['plan_key']);
        $this->assertFalse($state['subscription']['auto_renews']);
        $this->assertSame(20, $state['documents_remaining']);
        $this->assertSame(67, $state['saved_credits']);
        Subscription::sole()->periods()->first()->update(['documents_used' => 19]);
        $this->travel(3)->months();
        $state = $service->summary($workspace->id);
        $this->assertSame(20, $state['documents_remaining']);
        $this->assertTrue($state['paid_access']);
        $this->assertSame(19, Subscription::sole()->periods()->sum('documents_used'));
        $this->assertSame(67, $workspace->credits->documents_remaining);
        $this->assertDatabaseCount('credit_purchases', 1);
        $this->postJson('/api/workspace/billing/cancel')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_ai_comparison_allowance_is_atomic_and_resets_only_next_month(): void
    {
        $workspace = $this->workspace();
        $purchase = $this->checkout();
        $this->charge($purchase)->assertOk();
        $a = $this->document($workspace);
        $b = $this->document($workspace);
        $create = fn ($i) => DocumentComparison::create(['workspace_id' => $workspace->id, 'created_by' => $a->uploaded_by,
            'base_document_id' => $a->id, 'compared_document_id' => $b->id, 'fingerprint' => hash('sha256', (string) $i), 'metadata' => ['ai_context' => []]]);
        $service = app(EntitlementService::class);
        for ($i = 0; $i < 5; $i++) {
            $comparison = $create($i);
            $service->reserveComparison($comparison);
            $service->reserveComparison($comparison);
        }
        $this->assertSame(5, $service->summary($workspace->id)['usage']['comparisons_used']);
        try {
            $service->reserveComparison($create(6));
            $this->fail('Comparison over limit was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->getStatusCode());
        }
        $this->assertSame(5, Subscription::sole()->periods()->sum('comparisons_used'));
    }

    public function test_automatic_cancellation_calls_existing_client_and_keeps_paid_access(): void
    {
        $this->workspace();
        $this->charge($this->checkout('starter', 'monthly', 'automatic'))->assertOk();
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        Http::fake(['https://api.paystack.co/subscription/SUB_test' => Http::response(['status' => true, 'data' => ['email_token' => 'tok']]),
            'https://api.paystack.co/subscription/disable' => Http::response(['status' => true])]);
        $this->postJson('/api/workspace/billing/cancel')->assertOk()->assertJsonPath('data.paid_access', true);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.paystack.co/subscription/disable' && $r['code'] === 'SUB_test' && $r['token'] === 'tok');
        $this->assertFalse(Subscription::sole()->auto_renews);
    }

    public function test_failed_cancellation_does_not_claim_provider_was_disabled(): void
    {
        $this->workspace();
        $this->charge($this->checkout('starter', 'monthly', 'automatic'))->assertOk();
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        Http::fake(['https://api.paystack.co/subscription/SUB_test' => Http::response(['status' => false], 503)]);
        $this->postJson('/api/workspace/billing/cancel')->assertStatus(502);
        $this->assertTrue(Subscription::sole()->auto_renews);
    }

    public function test_invoice_update_can_reconcile_missing_renewal_and_late_failure_cannot_revoke_it(): void
    {
        $this->workspace();
        $this->charge($this->checkout('starter', 'monthly', 'automatic'))->assertOk();
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        $oldDate = now()->toIso8601String();
        $this->travel(1)->months();
        Http::fake(['https://api.paystack.co/transaction/verify/renewal-invoice' => Http::response(['status' => true, 'data' => [
            'id' => 9999, 'reference' => 'renewal-invoice', 'status' => 'success', 'amount' => 150000, 'currency' => 'KES', 'paid_at' => now()->toIso8601String()]])]);
        $invoice = ['subscription' => ['subscription_code' => 'SUB_test'], 'paid' => true, 'transaction' => ['reference' => 'renewal-invoice']];
        $this->event('invoice.update', $invoice)->assertOk();
        $this->event('invoice.update', $invoice)->assertOk();
        $this->event('invoice.payment_failed', ['subscription' => ['subscription_code' => 'SUB_test'], 'period_start' => $oldDate])->assertOk();
        $this->assertSame('active', Subscription::sole()->status);
        $this->assertDatabaseCount('credit_purchases', 2);
        $this->assertDatabaseCount('subscription_usage_periods', 2);
    }

    public function test_pending_checkout_cannot_be_duplicated_and_amount_mismatch_never_grants_access(): void
    {
        $this->workspace();
        $purchase = $this->checkout();
        $this->postJson('/api/workspace/credits/purchases', ['plan' => 'starter', 'interval' => 'monthly', 'renewal' => 'manual'])->assertStatus(409);
        $this->charge($purchase, ['amount' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('credit_purchases', 1);
    }

    public function test_month_end_provider_date_and_non_utc_payment_time_are_respected(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0, 0, 0));
        $workspace = $this->workspace();
        $purchase = $this->checkout('starter', 'monthly', 'automatic');
        $this->charge($purchase, ['paid_at' => '2026-10-31T15:00:00+03:00'])->assertOk();
        $created = $this->providerCreated();
        $created['next_payment_date'] = '2026-11-28T12:00:00Z';
        $this->event('subscription.create', $created)->assertOk();
        $this->assertSame('2026-11-28T12:00:00+00:00', Subscription::sole()->current_period_end->toIso8601String());
        $this->assertSame('2026-10-31T12:00:00+00:00', Subscription::sole()->current_period_start->toIso8601String());
        $this->travelTo(now()->setDate(2026, 11, 28));
        $this->event('charge.success', ['id' => 8888, 'reference' => 'month-end-renewal', 'status' => 'success', 'amount' => 150000,
            'currency' => 'KES', 'paid_at' => now()->toIso8601String(), 'customer' => ['customer_code' => 'CUS_test'], 'plan' => ['plan_code' => 'PLN_starter_monthly']])->assertOk();
        $this->assertSame(20, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->assertDatabaseCount('subscription_usage_periods', 2);
    }

    public function test_grace_uses_remaining_allowance_without_reset_and_then_expires(): void
    {
        $workspace = $this->workspace(0);
        config(['billing.grace_days' => 3]);
        $this->charge($this->checkout('starter', 'monthly', 'automatic'))->assertOk();
        $this->event('subscription.create', $this->providerCreated())->assertOk();
        Subscription::sole()->periods()->first()->update(['documents_used' => 18]);
        $this->travel(1)->months();
        $this->event('invoice.payment_failed', ['subscription' => ['subscription_code' => 'SUB_test'], 'period_start' => now()->toIso8601String()])->assertOk();
        $this->assertSame(2, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->travel(4)->days();
        $this->assertFalse(app(EntitlementService::class)->summary($workspace->id)['paid_access']);
        $this->assertDatabaseCount('subscription_usage_periods', 1);
    }

    public function test_grandfathered_user_can_pay_for_professional_then_return_to_starter(): void
    {
        $workspace = $this->workspace(41);
        $workspace->purchases()->create(['user_id' => $workspace->users()->first()->id, 'paystack_reference' => 'old-upgrade',
            'documents_purchased' => 100, 'amount_kobo_or_cents' => 200000, 'currency' => 'KES', 'status' => 'completed',
            'paystack_response' => ['data' => ['domain' => 'live', 'status' => 'success']]]);
        $this->assertSame(20, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->charge($this->checkout('professional'))->assertOk();
        $this->assertSame(100, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
        $this->travel(32)->days();
        $state = app(EntitlementService::class)->summary($workspace->id);
        $this->assertSame('starter', $state['subscription']['plan_key']);
        $this->assertTrue($state['subscription']['grandfathered']);
        $this->assertSame(41, $state['saved_credits']);
    }

    public function test_replaying_a_provider_transaction_with_a_different_reference_does_not_extend_access(): void
    {
        $this->workspace();
        $first = $this->checkout();
        $this->charge($first)->assertOk();
        $end = Subscription::sole()->current_period_end;
        $second = $this->checkout();
        $this->charge($second, ['id' => $first->id + 1000])->assertUnprocessable();
        $this->assertTrue(Subscription::sole()->current_period_end->equalTo($end));
        $this->assertSame('pending', $second->fresh()->status);
    }
}
