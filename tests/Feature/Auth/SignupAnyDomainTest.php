<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignupAnyDomainTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('anyEmailDomain')]
    public function test_signup_works_regardless_of_email_domain(string $email): void
    {
        $this->postJson('/api/auth/signup', [
            'full_name' => 'Test User',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonMissingValidationErrors('email');
    }

    public static function anyEmailDomain(): array
    {
        return [
            ['user1@gmail.com'],
            ['user2@outlook.com'],
            ['user3@yahoo.com'],
            ['user4@icloud.com'],
            ['user5@proton.me'],
            ['user6@ca.go.ke'],
            ['user7@some-university.ac.ke'],
            ['user8@some-company.co.ke'],
        ];
    }
}
