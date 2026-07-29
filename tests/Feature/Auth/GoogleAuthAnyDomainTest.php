<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthAnyDomainTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleToken(string $email, string $name, bool $verified = true): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google.client_id'),
                'email' => $email,
                'email_verified' => $verified ? 'true' : 'false',
                'name' => $name,
            ], 200),
        ]);
    }

    #[DataProvider('anyEmailDomain')]
    public function test_google_signin_works_regardless_of_email_domain(string $email): void
    {
        $this->fakeGoogleToken($email, 'Test User');

        $response = $this->postJson('/api/auth/google', ['id_token' => 'fake-token']);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public static function anyEmailDomain(): array
    {
        return [
            ['user@gmail.com'],
            ['user@outlook.com'],
            ['user@yahoo.com'],
            ['user@icloud.com'],
            ['user@ca.go.ke'],
            ['user@some-custom-company.co.ke'],
        ];
    }

    public function test_existing_google_user_logs_in_instead_of_duplicating(): void
    {
        $this->fakeGoogleToken('repeat@gmail.com', 'Repeat User');
        $this->postJson('/api/auth/google', ['id_token' => 'fake-token'])->assertStatus(200);

        $this->fakeGoogleToken('repeat@gmail.com', 'Repeat User');
        $this->postJson('/api/auth/google', ['id_token' => 'fake-token'])->assertStatus(200);

        $this->assertDatabaseCount('users', 1);
    }
}
