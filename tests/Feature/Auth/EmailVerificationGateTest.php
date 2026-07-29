<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_sign_in(): void
    {
        User::factory()->create([
            'email' => 'verified@gmail.com',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
            'active' => true,
        ]);

        $this->postJson('/api/auth/signin', [
            'email' => 'verified@gmail.com',
            'password' => 'Password123!',
        ])->assertStatus(200);
    }

    public function test_unverified_user_is_blocked_with_403(): void
    {
        User::factory()->create([
            'email' => 'unverified@gmail.com',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => null,
            'active' => true,
        ]);

        $this->postJson('/api/auth/signin', [
            'email' => 'unverified@gmail.com',
            'password' => 'Password123!',
        ])->assertStatus(403);
    }

    public function test_wrong_password_returns_401_not_verification_error(): void
    {
        User::factory()->create([
            'email' => 'wrongpass@gmail.com',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => null,
            'active' => true,
        ]);

        $this->postJson('/api/auth/signin', [
            'email' => 'wrongpass@gmail.com',
            'password' => 'TotallyWrongPassword',
        ])->assertStatus(401);
    }

    public function test_old_verification_code_is_invalidated_after_resend(): void
    {
        $signup = $this->postJson('/api/auth/signup', [
            'full_name' => 'Resend Test',
            'email' => 'resendtest@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $signup->assertStatus(201);
        $oldCode = $signup->json('data.verification_code');

        $resend = $this->postJson('/api/auth/resend-verification', [
            'email' => 'resendtest@example.com',
        ]);
        $resend->assertStatus(200);

        $response = $this->postJson('/api/auth/verify-email', [
            'email' => 'resendtest@example.com',
            'code' => $oldCode,
        ]);

        $response->assertStatus(422);
    }
}
