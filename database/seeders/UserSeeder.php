<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Creates dev-only test accounts with real, known passwords for local
     * testing of authenticated endpoints (e.g. the Day 5 upload pipeline).
     * Distinct from DocumentSeeder's fixture authors, which intentionally
     * have unusable random passwords since they only exist as FK targets.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'test.admin@ca.go.ke'],
            [
                'full_name' => 'Test Administrator',
                'password' => Hash::make('password123'),
                'role' => 'Administrator',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'test.reviewer@ca.go.ke'],
            [
                'full_name' => 'Test Reviewer',
                'password' => Hash::make('password123'),
                'role' => 'Reviewer',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'test.viewer@ca.go.ke'],
            [
                'full_name' => 'Test Viewer',
                'password' => Hash::make('password123'),
                'role' => 'Viewer',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
