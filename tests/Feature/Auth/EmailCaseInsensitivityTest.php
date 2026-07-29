<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailCaseInsensitivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_rejects_case_variant_of_existing_email(): void
    {
        $this->postJson('/api/auth/signup', [
            'full_name' => 'Original User',
            'email' => 'dkimaiyo@ca.go.ke',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertStatus(201);

        $response = $this->postJson('/api/auth/signup', [
            'full_name' => 'Case Variant User',
            'email' => 'DKimaiyo@CA.GO.KE',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_is_stored_normalized_to_lowercase(): void
    {
        $this->postJson('/api/auth/signup', [
            'full_name' => 'Case Test',
            'email' => 'MixedCase@Example.COM',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'mixedcase@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'MixedCase@Example.COM']);
    }

    public function test_signin_works_regardless_of_email_case(): void
    {
        $user = User::factory()->create([
            'email' => 'lowercase@example.com',
            'password' => bcrypt('SecurePass123!'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/signin', [
            'email' => 'LOWERCASE@EXAMPLE.COM',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(200);
    }
}
