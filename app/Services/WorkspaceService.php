<?php

namespace App\Services;

use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    public function createPersonalWorkspaceFor(User $user, ?string $ip = null, ?string $fingerprint = null, ?string $referralCode = null, bool $makeCurrent = true): Workspace
    {
        return DB::transaction(function () use ($user, $ip, $fingerprint, $referralCode, $makeCurrent) {
            $workspace = Workspace::create([
                'type' => WorkspaceType::Personal,
                'name' => null,
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => null,
                'joined_at' => now(),
            ]);

            if ($makeCurrent) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            if ($ip !== null) {
                $trialGranted = app(WorkspaceCreditService::class)->grantTrial($workspace, $user, $ip, $fingerprint);
                app(ReferralService::class)->recordSignup($user, $referralCode, $trialGranted);
            }

            return $workspace;
        });
    }
}
