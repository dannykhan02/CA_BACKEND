<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    private const TEST_PASSWORD = 'Password123!';

    public function run(): void
    {
        $accounts = [
            ['email' => 'test.admin@example.com', 'full_name' => 'Test Administrator', 'role' => 'Administrator'],
            ['email' => 'test.reviewer@example.com', 'full_name' => 'Test Reviewer', 'role' => 'Reviewer'],
            ['email' => 'test.analyst@example.com', 'full_name' => 'Test Analyst', 'role' => 'Analyst'],
            ['email' => 'test.viewer@example.com', 'full_name' => 'Test Viewer', 'role' => 'Viewer'],
        ];

        foreach ($accounts as $account) {
            DB::transaction(function () use ($account) {
                $user = User::updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'full_name' => $account['full_name'],
                        'password' => self::TEST_PASSWORD,
                        'role' => $account['role'],
                        'active' => true,
                        'email_verified_at' => now(),
                    ]
                );

                // updateOrCreate on a re-run must not create a second
                // workspace for an already-provisioned user.
                if (! $user->current_workspace_id) {
                    app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
                    $user->refresh();
                }
            });
        }
    }
}