<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (User::count() === 0) {
                return; // fresh install — nothing to backfill
            }

            $legacyWorkspaceId = (string) Str::uuid();

            DB::table('workspaces')->insert([
                'id' => $legacyWorkspaceId,
                'type' => 'Organization',
                'name' => 'Legacy Organization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            User::query()->chunkById(200, function ($users) use ($legacyWorkspaceId) {
                foreach ($users as $user) {
                    DB::table('workspace_members')->insert([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $legacyWorkspaceId,
                        'user_id' => $user->id,
                        'role' => $user->role,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            DB::table('users')->update(['current_workspace_id' => $legacyWorkspaceId]);
            DB::table('documents')->whereNull('workspace_id')->update(['workspace_id' => $legacyWorkspaceId]);
        });
    }

    public function down(): void
    {
        // Deliberately irreversible.
    }
};
