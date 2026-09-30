<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class GoogleAuthEmailVerifiedTest extends TestCase
{
    use RefreshDatabase;
    use FakesGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoogleTokenFaking();
    }

    public function test_google_signup_sets_email_verified_at_immediately(): void
    {
        $idToken = $this->fakeGoogleIdToken('googleuser@gmail.com', 'Google User');

        $this->postJson('/api/auth/google', ['accepted_terms' => true, 'terms_version' => config('legal.version'), 'id_token' => $idToken])->assertStatus(200);

        $user = User::where('email', 'googleuser@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }
}