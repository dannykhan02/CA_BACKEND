<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\CreditPurchase;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\PaystackClient;
use App\Services\ReferralService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use FakesGoogleTokens, RefreshDatabase;

    private const SECRET = 'sk_test_referral_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.paystack.secret_key' => self::SECRET, 'credits.referral_reward_documents' => 17]);
    }

    private function referrer(): User
    {
        $user = User::factory()->create(['role' => 'Viewer']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return $user->fresh();
    }

    private function code(User $owner): string
    {
        return app(ReferralService::class)->codeFor($owner)->code;
    }

    private function signup(?string $code, string $email = 'friend@example.com', string $ip = '203.0.113.10', ?string $fingerprint = 'new-browser'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/signup', [
            'full_name' => 'Referred Friend', 'email' => $email,
            'password' => 'StrongPassword1!', 'password_confirmation' => 'StrongPassword1!',
            'fingerprint' => $fingerprint, 'referral_code' => $code,
            // Eligibility is decided by the service; input cannot override it.
            'reward_eligible' => true,
        ]);
    }

    private function purchase(User $buyer): CreditPurchase
    {
        return CreditPurchase::create([
            'user_id' => $buyer->id, 'workspace_id' => $buyer->current_workspace_id,
            'paystack_reference' => 'credits-'.Str::uuid(), 'documents_purchased' => 100,
            'amount_kobo_or_cents' => 200000, 'currency' => 'KES', 'status' => 'pending',
        ]);
    }

    private function webhook(CreditPurchase $purchase, array $overrides = [], ?string $signature = null): TestResponse
    {
        $body = json_encode(['event' => 'charge.success', 'data' => array_replace([
            'reference' => $purchase->paystack_reference, 'status' => 'success',
            'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency,
        ], $overrides)], JSON_PRETTY_PRINT);

        return $this->call('POST', '/api/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    public function test_my_code_requires_authentication(): void
    {
        $this->getJson('/api/referrals/my-code')->assertUnauthorized();
        $this->assertDatabaseCount('referral_codes', 0);
    }

    public function test_any_user_gets_one_stable_url_safe_code_and_own_summary(): void
    {
        $first = $this->referrer();
        $second = $this->referrer();
        $second->currentWorkspace->update(['type' => WorkspaceType::Organization]);
        foreach ([$first, $second] as $user) {
            Sanctum::actingAs($user);
            $response = $this->getJson('/api/referrals/my-code?user_id='.$first->id)->assertOk()
                ->assertJsonPath('success', true)->assertJsonPath('summary', null)
                ->assertJsonPath('data.summary', ['signups' => 0, 'rewarded' => 0, 'credits_earned' => 0]);
            $code = $response->json('data.code');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $code);
            $response->assertJsonPath('data.link', rtrim(config('app.frontend_url'), '/').'/#/signup?referral_code='.$code);
            $this->getJson('/api/referrals/my-code')->assertOk()->assertJsonPath('data.code', $code);
            $this->assertDatabaseHas('referral_codes', ['user_id' => $user->id, 'code' => $code]);
        }
        $this->assertDatabaseCount('referral_codes', 2);
    }

    public function test_valid_signup_records_pending_and_only_the_normal_trial_is_granted(): void
    {
        $owner = $this->referrer();
        $code = $this->code($owner);
        $this->signup($code)->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $this->assertDatabaseHas('referrals', [
            'referral_code_id' => ReferralCode::where('code', $code)->sole()->id,
            'referred_user_id' => $buyer->id, 'status' => 'pending', 'reward_eligible' => true,
            'rewarded_at' => null, 'reward_documents' => 0, 'ineligible_reason' => null,
        ]);
        $this->assertSame(10, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $owner->currentWorkspace->credits->documents_remaining);
        $this->assertDatabaseCount('trial_grants', 1);
    }

    public static function invalidCodes(): array
    {
        return [[null], [''], ['missing1'], ['bad/code?']];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_or_missing_referral_proceeds_normally(?string $code): void
    {
        $this->signup($code)->assertCreated();
        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseCount('trial_grants', 1);
    }

    public function test_first_completed_purchase_rewards_once_across_retries_and_later_purchases(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $purchase = $this->purchase($buyer);
        $this->webhook($purchase)->assertOk();
        $rewardedAt = Referral::sole()->rewarded_at->toISOString();
        $this->webhook($purchase)->assertOk();
        config(['credits.referral_reward_documents' => 99]);
        // A later purchase in a different workspace still cannot reward again.
        app(WorkspaceService::class)->createPersonalWorkspaceFor($buyer);
        $this->webhook($this->purchase($buyer))->assertOk();
        $this->assertSame(17, $owner->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $owner->currentWorkspace->credits->documents_purchased_total);
        $this->assertSame(110, $purchase->workspace->credits->documents_remaining);
        $this->assertSame($rewardedAt, Referral::sole()->rewarded_at->toISOString());
        $this->assertDatabaseHas('referrals', ['status' => 'rewarded', 'reward_documents' => 17, 'rewarded_workspace_id' => $owner->current_workspace_id]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/referrals/my-code')->assertOk()->assertJsonPath('data.summary', [
            'signups' => 1, 'rewarded' => 1, 'credits_earned' => 17,
        ]);
    }

    public function test_prior_completed_purchase_prevents_reward_even_if_referral_is_still_pending(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $this->purchase($buyer)->update(['status' => 'completed']);
        $this->webhook($this->purchase($buyer))->assertOk();
        $this->assertSame('pending', Referral::sole()->status);
        $this->assertSame(0, $owner->currentWorkspace->credits->documents_remaining);
    }

    public function test_earned_credit_history_survives_referred_account_deletion(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $this->webhook($this->purchase($buyer))->assertOk();
        $buyer->delete();
        Sanctum::actingAs($owner);
        $this->getJson('/api/referrals/my-code')->assertOk()->assertJsonPath('data.summary', [
            'signups' => 1, 'rewarded' => 1, 'credits_earned' => 17,
        ]);
    }

    public static function abuseSignals(): array
    {
        return [['email'], ['ip'], ['fingerprint']];
    }

    #[DataProvider('abuseSignals')]
    public function test_any_existing_trial_signal_permanently_blocks_referral_reward(string $signal): void
    {
        $owner = $this->referrer();
        $previous = User::factory()->create(['email' => 'previous@example.com']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($previous, '203.0.113.20', 'used-browser');
        $previous->delete();
        $email = $signal === 'email' ? 'PREVIOUS@example.com' : 'friend@example.com';
        $this->signup($this->code($owner), $email,
            $signal === 'ip' ? '203.0.113.20' : '203.0.113.10',
            $signal === 'fingerprint' ? 'used-browser' : 'new-browser')->assertCreated();
        $buyer = User::where('email', strtolower($email))->sole();
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertDatabaseHas('referrals', [
            'referred_user_id' => $buyer->id, 'status' => 'pending',
            'reward_eligible' => false, 'ineligible_reason' => 'trial_abuse_signal_match',
        ]);
        $this->webhook($this->purchase($buyer))->assertOk();
        $this->assertSame(0, $owner->currentWorkspace->credits->documents_remaining);
        $this->assertNull(Referral::sole()->rewarded_at);
        $this->assertSame(100, $buyer->currentWorkspace->credits()->sole()->documents_remaining);
        Sanctum::actingAs($owner);
        $this->getJson('/api/referrals/my-code')->assertOk()->assertJsonPath('data.summary', [
            'signups' => 1, 'rewarded' => 0, 'credits_earned' => 0,
        ]);
    }

    public function test_absent_fingerprints_do_not_block_distinct_users(): void
    {
        $owner = $this->referrer();
        $previous = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($previous, '203.0.113.20');
        $this->signup($this->code($owner), fingerprint: null)->assertCreated();
        $this->assertTrue(Referral::sole()->reward_eligible);
        $this->webhook($this->purchase(User::where('email', 'friend@example.com')->sole()))->assertOk();
        $this->assertSame(17, $owner->currentWorkspace->credits->documents_remaining);
    }

    public function test_user_identity_and_normalized_email_cannot_self_refer(): void
    {
        $owner = $this->referrer();
        $code = $this->code($owner);
        $service = app(ReferralService::class);
        $service->recordSignup($owner, $code, true);
        $this->assertDatabaseCount('referrals', 0);
        $other = $this->referrer();
        $other->setRawAttributes(array_replace($other->getAttributes(), ['email' => ' '.strtoupper($owner->email).' ']));
        $service->recordSignup($other, $code, true);
        $this->assertDatabaseCount('referrals', 0);
        $this->signup($code, strtoupper($owner->email))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_new_google_signup_records_referral_but_existing_google_user_cannot_attach_one(): void
    {
        $this->setUpGoogleTokenFaking();
        $owner = $this->referrer();
        $token = $this->fakeGoogleIdToken('google@example.com', 'Google Friend');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])->postJson('/api/auth/google', [
            'id_token' => $token, 'referral_code' => $this->code($owner), 'fingerprint' => 'google-browser',
        ])->assertOk();
        $referral = Referral::sole();
        $this->assertTrue($referral->reward_eligible);
        $this->assertSame('pending', $referral->status);
        $other = $this->referrer();
        $this->postJson('/api/auth/google', ['id_token' => $token, 'referral_code' => $this->code($other)])->assertOk();
        $this->assertSame($referral->referral_code_id, Referral::sole()->referral_code_id);

        $existing = User::factory()->create(['email' => 'existing@example.com']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($existing);
        $this->postJson('/api/auth/google', [
            'id_token' => $this->fakeGoogleIdToken($existing->email, 'Existing User'), 'referral_code' => $this->code($owner),
        ])->assertOk();
        $this->assertDatabaseCount('referrals', 1);
    }

    public function test_invalid_charges_and_failed_purchases_do_not_reward(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $purchase = $this->purchase($buyer);
        $this->webhook($purchase, signature: 'invalid')->assertUnauthorized();
        $this->webhook($purchase, ['amount' => 1])->assertUnprocessable();
        $this->webhook($purchase, ['status' => 'failed'])->assertUnprocessable();
        $purchase->update(['status' => 'failed']);
        $this->webhook($purchase)->assertOk();
        $this->assertSame('pending', Referral::sole()->status);
        $this->assertSame(0, $owner->currentWorkspace->credits->documents_remaining);
        $this->webhook($this->purchase($buyer))->assertOk();
        $this->assertSame('rewarded', Referral::sole()->status);
    }

    public function test_reward_failure_rolls_back_purchase_and_retry_can_finish(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $purchase = $this->purchase($buyer);
        $owner->currentWorkspace->credits()->delete();
        $this->webhook($purchase)->assertNotFound();
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $this->assertSame('pending', Referral::sole()->status);
        $this->assertSame(10, $buyer->currentWorkspace->credits->documents_remaining);
        $owner->currentWorkspace->credits()->create([]);
        $this->webhook($purchase)->assertOk();
        $this->assertSame(17, $owner->currentWorkspace->credits->documents_remaining);
    }

    public function test_purchase_records_authenticated_buyer_and_ignores_identity_from_request(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        Sanctum::actingAs($buyer);
        $this->mock(PaystackClient::class, fn ($mock) => $mock->shouldReceive('initialize')->once()->andReturn(['authorization_url' => 'https://checkout.paystack.com/test']));
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100', 'user_id' => $owner->id])->assertCreated();
        $purchase = CreditPurchase::sole();
        $this->assertSame($buyer->id, $purchase->user_id);
        $this->webhook($purchase, ['metadata' => ['user_id' => $owner->id]])->assertOk();
        $this->assertSame('rewarded', Referral::sole()->status);
    }

    public function test_rewards_follow_personal_workspace_even_when_referrer_is_active_in_organization(): void
    {
        $owner = $this->referrer();
        $personal = $owner->currentWorkspace;
        $organization = app(WorkspaceService::class)->createPersonalWorkspaceFor($owner);
        $organization->update(['type' => WorkspaceType::Organization]);
        $this->signup($this->code($owner))->assertCreated();
        $this->webhook($this->purchase(User::where('email', 'friend@example.com')->sole()))->assertOk();
        $this->assertSame(17, $personal->credits->documents_remaining);
        $this->assertSame(0, $organization->credits->documents_remaining);
        $this->assertSame($organization->id, $owner->fresh()->current_workspace_id);
    }

    public function test_organization_only_referrer_gets_personal_reward_without_new_trial_or_workspace_switch(): void
    {
        $owner = $this->referrer();
        $organization = $owner->currentWorkspace;
        $organization->update(['type' => WorkspaceType::Organization]);
        $this->signup($this->code($owner))->assertCreated();
        $this->webhook($this->purchase(User::where('email', 'friend@example.com')->sole()))->assertOk();
        $personal = $owner->workspaces()->where('type', WorkspaceType::Personal)->sole();
        $this->assertSame(17, $personal->credits->documents_remaining);
        $this->assertSame($organization->id, $owner->fresh()->current_workspace_id);
        $this->assertDatabaseCount('trial_grants', 1);
    }

    public function test_verified_return_and_later_webhook_share_exactly_once_referral_reward(): void
    {
        $owner = $this->referrer();
        $this->signup($this->code($owner))->assertCreated();
        $buyer = User::where('email', 'friend@example.com')->sole();
        $purchase = $this->purchase($buyer);
        Sanctum::actingAs($buyer);
        $this->mock(PaystackClient::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn([
            'status' => true, 'data' => ['status' => 'success', 'reference' => $purchase->paystack_reference,
                'amount' => 200000, 'currency' => 'KES', 'domain' => 'test'],
        ]));
        $url = '/api/workspace/credits/purchases/'.$purchase->paystack_reference.'/verify';
        $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($url)->assertOk();
        $this->webhook($purchase)->assertOk();
        $this->assertSame(17, $owner->currentWorkspace->credits->documents_remaining);
        $this->assertSame(110, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame('rewarded', Referral::sole()->status);
    }
}
