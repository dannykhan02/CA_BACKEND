<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_manual_signup_user_can_log_in_via_google_without_duplicating(): void
    {
        $existing = User::factory()->create([
            'email' => 'dan@gmail.com',
            'password' => Hash::make('SomeOriginalPassword123!'),
            'role' => 'Viewer',
            'email_verified_at' => now(),
        ]);

        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google.client_id'),
                'email' => 'dan@gmail.com',
                'email_verified' => 'true',
                'name' => 'Dan From Google',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'fake-token']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'email' => 'dan@gmail.com',
        ]);
    }

    public function test_invalid_google_token_returns_401(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400),
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'garbage-token']);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_audience_mismatch_is_rejected(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'some-other-app.apps.googleusercontent.com',
                'email' => 'someone@gmail.com',
                'email_verified' => 'true',
                'name' => 'Someone',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'fake-token']);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google.client_id'),
                'email' => 'unverified@gmail.com',
                'email_verified' => 'false',
                'name' => 'Unverified Person',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'fake-token']);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }

}
