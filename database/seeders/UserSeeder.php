<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Creates dev-only test accounts with real, known passwords for local
     * testing of authenticated endpoints (e.g. the Day 5 upload pipeline).
     * Distinct from DocumentSeeder's fixture authors, which intentionally
     * have unusable random passwords since they only exist as FK targets.
     *
     * Passwords are passed as plain text, NOT pre-hashed here — the User
     * model's `password => hashed` cast handles hashing on save, same as
     * AuthController::signup(). Pre-hashing with Hash::make() would cause
     * a double-hash, and Hash::check() in signin() would never match.
     *
     * Password meets the frontend's complexity rules (8+ chars, upper,
     * lower, number, symbol) so these accounts stay usable if ever tested
     * through the actual signup/reset UI, not just seeded directly.
     *
     * Roles mirror UserController::VALID_ROLES exactly. If that list ever
     * changes, update it here too — there's no shared enum/constant on the
     * User model to keep these in sync automatically.
     *
     * Domain is example.com (IANA-reserved for documentation/testing),
     * NOT ca.go.ke — these accounts trigger real outbound email (signup
     * verification, and now email-change security alerts), and ca.go.ke is
     * a real government domain we don't control. Using a real-but-fake
     * mailbox there risks bounces against CA's actual mail infrastructure.
     */
    private const TEST_PASSWORD = 'Password123!';

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'test.admin@example.com'],
            [
                'full_name' => 'Test Administrator',
                'password' => self::TEST_PASSWORD,
                'role' => 'Administrator',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'test.reviewer@example.com'],
            [
                'full_name' => 'Test Reviewer',
                'password' => self::TEST_PASSWORD,
                'role' => 'Reviewer',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'test.analyst@example.com'],
            [
                'full_name' => 'Test Analyst',
                'password' => self::TEST_PASSWORD,
                'role' => 'Analyst',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'test.viewer@example.com'],
            [
                'full_name' => 'Test Viewer',
                'password' => self::TEST_PASSWORD,
                'role' => 'Viewer',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
