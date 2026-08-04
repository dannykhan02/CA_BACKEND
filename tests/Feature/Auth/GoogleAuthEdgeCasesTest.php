<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class GoogleAuthEdgeCasesTest extends TestCase
{
    use RefreshDatabase;
    use FakesGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoogleTokenFaking();
    }

    public function test_existing_manual_signup_user_can_log_in_via_google_without_duplicating(): void
    {
        $existing = User::factory()->create([
            'email' => 'dan@gmail.com',
            'password' => Hash::make('SomeOriginalPassword123!'),
            'role' => 'Viewer',
            'email_verified_at' => now(),
        ]);

        $idToken = $this->fakeGoogleIdToken('dan@gmail.com', 'Dan From Google');

        $response = $this->postJson('/api/auth/google', ['id_token' => $idToken]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'email' => 'dan@gmail.com',
        ]);
    }

    public function test_invalid_google_token_returns_401(): void
    {
        // Deliberately not a JWT at all — must fail signature parsing,
        // not hit the mocked JWKS endpoint's happy path.
        $response = $this->postJson('/api/auth/google', ['id_token' => 'garbage-token']);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_audience_mismatch_is_rejected(): void
    {
        // Real signature, wrong aud — GoogleTokenVerifier checks aud
        // against config('services.google.client_id') after signature
        // verification succeeds, so this needs a validly-signed token.
        $idToken = $this->fakeGoogleIdToken(
            'someone@gmail.com',
            'Someone',
            audience: 'some-other-app.apps.googleusercontent.com',
        );

        $response = $this->postJson('/api/auth/google', ['id_token' => $idToken]);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $idToken = $this->fakeGoogleIdToken('unverified@gmail.com', 'Unverified Person', verified: false);

        $response = $this->postJson('/api/auth/google', ['id_token' => $idToken]);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('users', 0);
    }
}