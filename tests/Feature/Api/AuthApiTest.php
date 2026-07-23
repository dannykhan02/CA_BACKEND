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
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertStatus(201);
        
        // Verify the user was created with Viewer role in the database
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertSame('Viewer', $user->role);
        
        // Verify the API response also reflects the Viewer role
        $this->assertSame('Viewer', $response->json('data.user.role'));
    }

    public function test_password_reset_notification_url_matches_frontend_router_format(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = 'test-reset-token-12345';

        // Trigger the password reset notification
        $user->sendPasswordResetNotification($token);

        // Verify the notification was sent
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($token) {
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
            $expectedUrl = "{$frontendUrl}/#/reset?token={$token}&email=" . urlencode($notification->url);
            
            // Extract the URL from the notification
            $actualUrl = $notification->url;
            
            // Verify the URL matches the expected format for the frontend hash router
            $this->assertStringContainsString('/#/reset?token=', $actualUrl);
            $this->assertStringContainsString('&email=', $actualUrl);
            $this->assertStringNotContainsString('/reset-password', $actualUrl);
            
            return true;
        });
    }
}
