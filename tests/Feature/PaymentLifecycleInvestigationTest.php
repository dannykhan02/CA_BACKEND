<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentLifecycleInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_lifecycle_fixture';

    private function purchase(): CreditPurchase
    {
        config(['services.paystack.secret_key' => self::SECRET]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        Sanctum::actingAs($user->fresh());

        return $workspace->purchases()->create([
            'user_id' => $user->id, 'paystack_reference' => 'credits-'.Str::uuid(),
            'documents_purchased' => 100, 'amount_kobo_or_cents' => 200000,
            'currency' => 'KES', 'status' => 'pending',
        ]);
    }

    private function complete(CreditPurchase $purchase, string $via): TestResponse
    {
        $data = ['reference' => $purchase->paystack_reference, 'status' => 'success',
            'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency];
        if ($via === 'verification') {
            Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $data])]);

            return $this->postJson('/api/workspace/credits/purchases/'.$purchase->paystack_reference.'/verify');
        }
        $body = json_encode(['event' => 'charge.success', 'data' => $data]);

        return $this->call('POST', '/api/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    public static function completionPaths(): array
    {
        return ['webhook' => ['webhook'], 'verification' => ['verification']];
    }

    #[DataProvider('completionPaths')]
    public function test_exception_after_completed_update_rolls_back_status_payload_and_both_counters(string $via): void
    {
        $purchase = $this->purchase();
        $shouldFail = true;
        // The SQL update has already happened when this event fires; both
        // balance counters were also incremented earlier in completePurchase.
        CreditPurchase::updated(function (CreditPurchase $updated) use (&$shouldFail) {
            if ($shouldFail && $updated->status === 'completed') {
                throw new \RuntimeException('Synthetic failure after completed update.');
            }
        });
        $this->complete($purchase, $via)->assertStatus(500);
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $this->assertSame(0, $purchase->workspace->credits()->first()->documents_remaining);
        $this->assertSame(0, $purchase->workspace->credits()->first()->documents_purchased_total);

        $shouldFail = false;
        $this->complete($purchase, $via)->assertOk();
        $this->complete($purchase, 'webhook')->assertOk();
        $this->complete($purchase, 'verification')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(100, $purchase->workspace->credits()->first()->documents_remaining);
        $this->assertSame(100, $purchase->workspace->credits()->first()->documents_purchased_total);
    }

    public function test_workspace_change_blocks_verification_but_webhook_credits_original_workspace(): void
    {
        $purchase = $this->purchase();
        $buyer = User::findOrFail($purchase->user_id);
        $other = app(WorkspaceService::class)->createPersonalWorkspaceFor($buyer);
        Sanctum::actingAs($buyer->fresh());
        $this->complete($purchase, 'verification')->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame('pending', $purchase->fresh()->status);

        $this->complete($purchase, 'webhook')->assertOk();
        $this->assertSame('completed', $purchase->fresh()->status);
        $this->assertSame(100, $purchase->workspace->credits()->first()->documents_remaining);
        $this->assertSame(100, $purchase->workspace->credits()->first()->documents_purchased_total);
        $this->assertSame(0, $other->credits()->first()->documents_remaining);
        $this->getJson('/api/workspace/credits')->assertOk()->assertJsonPath('data.documents_remaining', 0);
    }

    public function test_replay_does_not_repair_a_preexisting_inconsistent_completed_marker(): void
    {
        $purchase = $this->purchase();
        // Deliberately corrupt LOCAL fixture only: this is not produced by the
        // transaction above and does not establish any real affected account.
        $purchase->update(['status' => 'completed']);
        $this->complete($purchase, 'webhook')->assertOk();
        $this->complete($purchase, 'verification')->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.documents_remaining', 0);
        Http::assertNothingSent();
        $this->assertSame(0, $purchase->workspace->credits()->first()->documents_purchased_total);
    }
}
