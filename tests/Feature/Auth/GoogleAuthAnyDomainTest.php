<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGoogleTokens;
use Tests\TestCase;

class GoogleAuthAnyDomainTest extends TestCase
{
    use RefreshDatabase;
    use FakesGoogleTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoogleTokenFaking();
    }

    #[DataProvider('anyEmailDomain')]
    public function test_google_signin_works_regardless_of_email_domain(string $email): void
    {
        $idToken = $this->fakeGoogleIdToken($email, 'Test User');

        $response = $this->postJson('/api/auth/google', ['id_token' => $idToken]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_existing_google_user_logs_in_instead_of_duplicating(): void
    {
        $idToken1 = $this->fakeGoogleIdToken('repeat@gmail.com', 'Repeat User');
        $this->postJson('/api/auth/google', ['id_token' => $idToken1])->assertStatus(200);

        $idToken2 = $this->fakeGoogleIdToken('repeat@gmail.com', 'Repeat User');
        $this->postJson('/api/auth/google', ['id_token' => $idToken2])->assertStatus(200);

        $this->assertDatabaseCount('users', 1);
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
}