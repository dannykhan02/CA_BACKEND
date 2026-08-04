<?php

namespace App\Services;

use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

class WorkspaceService
{
    public function createPersonalWorkspaceFor(User $user): Workspace
    {
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

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $workspace;
    }
}