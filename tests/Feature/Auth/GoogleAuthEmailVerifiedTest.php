<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthEmailVerifiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_signup_sets_email_verified_at_immediately(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google.client_id'),
                'email' => 'googleuser@gmail.com',
                'email_verified' => 'true',
                'name' => 'Google User',
            ], 200),
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'fake-token'])->assertStatus(200);

        $user = User::where('email', 'googleuser@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }
}
