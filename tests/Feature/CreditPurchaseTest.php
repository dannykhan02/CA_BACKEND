<?php

namespace Tests\Feature;

use App\Exceptions\PaystackInitializationException;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PaystackClient;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_billing_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => self::SECRET]);
        Http::preventStrayRequests();
    }

    private function workspace(): Workspace
    {
        return app(WorkspaceService::class)->createPersonalWorkspaceFor(User::factory()->create());
    }

    private function purchase(?Workspace $workspace = null): CreditPurchase
    {
        return ($workspace ?? $this->workspace())->purchases()->create([
            'paystack_reference' => 'credits-'.Str::uuid(),
            'documents_purchased' => 100,
            'amount_kobo_or_cents' => 200000,
            'currency' => 'KES',
            'status' => 'pending',
        ]);
    }

    private function payload(CreditPurchase $purchase, array $overrides = []): array
    {
        return [
            'event' => 'charge.success',
            'data' => array_replace([
                'reference' => $purchase->paystack_reference,
                'amount' => $purchase->amount_kobo_or_cents,
                'currency' => $purchase->currency,
                'status' => 'success',
            ], $overrides),
        ];
    }

    private function webhook(array $payload, ?string $signature = null): TestResponse
    {
        // Deliberately include whitespace: signature must cover original bytes.
        $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    public function test_store_uses_config_and_current_workspace_before_calling_client(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace();
        $user = $workspace->users()->first();
        Sanctum::actingAs($user);
        $this->mock(PaystackClient::class, function ($mock) use ($workspace, $user) {
            $mock->shouldReceive('initialize')->once()->withArgs(function ($purchase, $email) use ($workspace, $user) {
                $this->assertDatabaseHas('credit_purchases', [
                    'id' => $purchase->id, 'workspace_id' => $workspace->id,
                    'status' => 'pending', 'documents_purchased' => 100,
                    'amount_kobo_or_cents' => 200000, 'currency' => 'KES',
                ]);

                return $email === $user->email;
            })->andReturnUsing(fn ($purchase) => [
                'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                'reference' => $purchase->paystack_reference,
            ]);
        });

        $response = $this->postJson('/api/workspace/credits/purchases', [
            'package' => 'documents-100', 'workspace_id' => $other->id,
            'amount_kobo_or_cents' => 1, 'currency' => 'USD', 'documents_purchased' => 9999,
        ])->assertCreated()->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/test-checkout');
        $purchase = $workspace->purchases()->sole();
        $response->assertJsonPath('data.reference', $purchase->paystack_reference);
        $this->assertSame(0, $workspace->credits->documents_remaining);
        $this->assertSame(0, $workspace->credits->documents_purchased_total);
        $this->assertSame(0, $other->purchases()->count());
        Http::assertNothingSent();
    }

    public function test_initialize_http_request_matches_paystack_contract(): void
    {
        $workspace = $this->workspace();
        $user = $workspace->users()->first();
        Sanctum::actingAs($user);
        Http::fake(['https://api.paystack.co/transaction/initialize' => function (ClientRequest $request) {
            return Http::response(['status' => true, 'data' => [
                'reference' => $request['reference'],
                'authorization_url' => 'https://checkout.paystack.com/fixture',
                'access_code' => 'fixture',
            ]]);
        }]);

        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])
            ->assertCreated()->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/fixture');
        $purchase = $workspace->purchases()->sole();
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['email'] === $user->email
            && $request['amount'] === '200000' && $request['currency'] === 'KES'
            && $request['callback_url'] === 'https://classy-narwhal-44186a.netlify.app/#/billing/return'
            && $request['reference'] === $purchase->paystack_reference);
        Http::assertSentCount(1);
    }

    public function test_purchase_requires_authentication(): void
    {
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_invalid_package_is_rejected_before_purchase_creation(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'unknown'])->assertUnprocessable();
        $this->postJson('/api/workspace/credits/purchases', [])->assertUnprocessable();
        $this->assertSame(0, $workspace->purchases()->count());
        Http::assertNothingSent();
    }

    public function test_nonmember_cannot_purchase_for_a_workspace(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs(User::factory()->create(['current_workspace_id' => $workspace->id]));
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertForbidden();
        $this->assertSame(0, $workspace->purchases()->count());
        Http::assertNothingSent();
    }

    public function test_missing_secret_disables_initialization_and_webhook(): void
    {
        config(['services.paystack.secret_key' => null]);
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertStatus(503);
        $this->webhook(['event' => 'charge.success'])->assertStatus(503);
        $this->assertSame(0, $workspace->purchases()->count());
        Http::assertNothingSent();
    }

    public function test_definitive_initialization_rejection_marks_failed_without_credits(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        Http::fake(['https://api.paystack.co/*' => Http::response(['status' => false], 400)]);
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertStatus(502);
        $this->assertSame('failed', $workspace->purchases()->sole()->status);
        $this->assertSame(0, $workspace->credits->documents_remaining);
    }

    public function test_timeout_stays_pending_and_later_webhook_can_complete(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        Http::fake(['https://api.paystack.co/*' => Http::failedConnection()]);
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertStatus(502);
        $purchase = $workspace->purchases()->sole();
        $this->assertSame('pending', $purchase->status);
        $this->webhook($this->payload($purchase))->assertOk();
        $this->assertSame(100, $workspace->credits->documents_remaining);
    }

    public function test_mismatched_initialize_reference_does_not_return_checkout_url(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        Http::fake(['https://api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'reference' => 'wrong-reference', 'authorization_url' => 'https://checkout.paystack.com/fixture',
        ]])]);
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertStatus(502);
        $this->assertSame('pending', $workspace->purchases()->sole()->status);
    }

    public function test_valid_signed_pending_purchase_credits_workspace_and_stores_full_payload(): void
    {
        $purchase = $this->purchase();
        $purchase->workspace->credits()->update(['documents_remaining' => 7, 'documents_purchased_total' => 200]);
        $payload = $this->payload($purchase);
        $payload['data']['customer'] = ['email' => 'payer@example.com'];
        $this->webhook($payload)->assertOk(); // No Sanctum token.
        $this->assertSame('completed', $purchase->fresh()->status);
        $this->assertSame($payload, $purchase->fresh()->paystack_response);
        $this->assertSame(107, $purchase->workspace->credits->documents_remaining);
        $this->assertSame(300, $purchase->workspace->credits->documents_purchased_total);
        Http::assertNothingSent();
    }

    public function test_duplicate_delivery_does_not_double_credit_or_replace_audit_payload(): void
    {
        $purchase = $this->purchase();
        $payload = $this->payload($purchase);
        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();
        $this->assertSame(100, $purchase->workspace->credits->documents_remaining);
        $this->assertSame(100, $purchase->workspace->credits->documents_purchased_total);
        $this->assertSame($payload, $purchase->fresh()->paystack_response);
    }

    #[DataProvider('invalidSignatures')]
    public function test_missing_or_invalid_signature_cannot_mutate_purchase(?string $signature): void
    {
        $purchase = $this->purchase();
        if ($signature === null) {
            $this->postJson('/api/paystack/webhook', $this->payload($purchase))->assertUnauthorized();
        } else {
            $this->webhook($this->payload($purchase), $signature)->assertUnauthorized();
        }
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $this->assertSame(0, $purchase->workspace->credits->documents_remaining);
    }

    public static function invalidSignatures(): array
    {
        return [[null], [''], [str_repeat('0', 128)]];
    }

    #[DataProvider('mismatchedCharges')]
    public function test_signed_mismatched_charge_is_audited_without_crediting(array $overrides): void
    {
        $purchase = $this->purchase();
        $payload = $this->payload($purchase, $overrides);
        $this->webhook($payload)->assertUnprocessable();
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertSame($payload, $purchase->fresh()->paystack_response);
        $this->assertSame(0, $purchase->workspace->credits->documents_remaining);
        $this->assertSame(0, $purchase->workspace->credits->documents_purchased_total);
    }

    public static function mismatchedCharges(): array
    {
        return [[['amount' => 2000]], [['amount' => 200001]], [['currency' => 'USD']], [['status' => 'failed']]];
    }

    public function test_webhook_uses_purchase_snapshot_and_ignores_client_metadata(): void
    {
        $purchase = $this->purchase();
        $other = $this->workspace();
        config(['credits.packages.documents-100' => ['documents' => 999, 'currency' => 'USD', 'amount_kobo_or_cents' => 1]]);
        $this->webhook($this->payload($purchase, ['metadata' => ['workspace_id' => $other->id, 'documents' => 999]]))->assertOk();
        $this->assertSame(100, $purchase->workspace->credits->documents_remaining);
        $this->assertSame(0, $other->credits->documents_remaining);
    }

    public function test_failed_purchase_and_unrelated_events_do_not_credit(): void
    {
        $purchase = $this->purchase();
        $this->webhook(['event' => 'transfer.success', 'data' => $this->payload($purchase)['data']])->assertOk();
        $this->webhook($this->payload($purchase, ['reference' => 'unknown']))->assertOk();
        $purchase->update(['status' => 'failed']);
        $this->webhook($this->payload($purchase))->assertOk();
        $this->assertSame(0, $purchase->workspace->credits->documents_remaining);
        $this->assertSame('failed', $purchase->fresh()->status);
    }

    public function test_balance_failure_rolls_back_completion_and_payload(): void
    {
        $purchase = $this->purchase();
        $purchase->workspace->credits()->delete();
        $this->webhook($this->payload($purchase))->assertNotFound();
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $purchase->workspace->credits()->create([]);
        $this->webhook($this->payload($purchase))->assertOk();
        $this->assertSame(100, $purchase->workspace->credits->documents_remaining);
    }

    public function test_initialize_failure_cannot_overwrite_an_early_webhook_completion(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace->users()->first());
        $this->mock(PaystackClient::class, function ($mock) {
            $mock->shouldReceive('initialize')->once()->andReturnUsing(function ($purchase) {
                $this->webhook($this->payload($purchase))->assertOk();
                throw new PaystackInitializationException(true);
            });
        });
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])->assertStatus(502);
        $this->assertSame('completed', $workspace->purchases()->sole()->status);
        $this->assertSame(100, $workspace->credits->documents_remaining);
    }
}
