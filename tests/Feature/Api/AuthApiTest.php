<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_assigns_viewer_role_by_default(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'full_name' => 'Test User',
            'email' => 'test@gmail.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@gmail.com')->firstOrFail();
        $this->assertSame('Viewer', $user->role);
        $this->assertSame('Viewer', $response->json('data.user.role'));
    }

    public function test_password_reset_notification_url_matches_frontend_router_format(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = 'test-reset-token-12345';

        $user->sendPasswordResetNotification($token);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($token) {
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
            $expectedUrl = "{$frontendUrl}/#/reset?token={$token}&email=" . urlencode($notification->url);

            $actualUrl = $notification->url;

            $this->assertStringContainsString('/#/reset?token=', $actualUrl);
            $this->assertStringContainsString('&email=', $actualUrl);
            $this->assertStringNotContainsString('/reset-password', $actualUrl);

            return true;
        });
    }

    private function assertGenericSigninFailure($response): void
    {
        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'These credentials do not match our records.',
            'errors' => [],
        ]);
    }

    public function test_signin_with_wrong_password_returns_generic_message(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/signin', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGenericSigninFailure($response);
    }

    public function test_signin_with_unknown_email_returns_identical_generic_message(): void
    {
        $response = $this->postJson('/api/auth/signin', [
            'email' => 'nobody-has-this-account@example.com',
            'password' => 'whatever-password',
        ]);

        $this->assertGenericSigninFailure($response);
    }

    public function test_signin_deactivated_account_returns_identical_generic_message(): void
    {
        $user = User::factory()->inactive()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/signin', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $this->assertGenericSigninFailure($response);
    }

    public function test_signin_unverified_account_returns_identical_generic_message(): void
    {
        $user = User::factory()->unverified()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/signin', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $this->assertGenericSigninFailure($response);
    }

    public function test_repeated_failed_signin_attempts_trigger_429_after_max_attempts(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/signin', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/signin', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString(
            'Too many failed login attempts.',
            $response->json('message')
        );
        $this->assertIsInt($response->json('errors.retry_after'));
    }

    public function test_successful_signin_after_failed_attempts_clears_the_rate_limit(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/signin', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/signin', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Signed in successfully.',
        ]);
        $this->assertNotEmpty($response->json('data.token'));
    }
}
