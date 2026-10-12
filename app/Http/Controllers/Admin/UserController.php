<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const VALID_ROLES = ['Administrator', 'Reviewer', 'Analyst', 'Viewer'];

    private const SAFE_FIELDS = ['id', 'full_name', 'email', 'role', 'active', 'email_verified_at'];

    /**
     * Track 2 F1-P1: previously User::select(...)->get() with no
     * workspace filter at all — any Administrator saw every user on the
     * entire platform, including accounts that only ever exist in their
     * own unrelated Personal workspace. Scoped to users who share a
     * workspace_members row with the acting admin's current workspace.
     *
     * NOTE: role itself (users.role) remains a global column in this
     * path — only the LISTING is scoped. updateRole() below still
     * writes the same global column it always did. This is a deliberate
     * partial fix — see Track 2 handoff report for why the full
     * per-workspace role model (Path 2) was not applied here.
     */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id;
        abort_unless($workspaceId && WorkspaceMember::where('workspace_id', $workspaceId)
            ->where('user_id', $request->user()->id)->exists(), 403);

        $userIds = WorkspaceMember::where('workspace_id', $workspaceId)
            ->pluck('user_id');

        return response()->json([
            'data' => User::whereIn('id', $userIds)->select(self::SAFE_FIELDS)->get(),
        ]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::VALID_ROLES)],
        ]);

        $result = DB::transaction(function () use ($request, $user, $validated): ?JsonResponse {
            $workspaceId = $request->user()->current_workspace_id;
            // Serialize role changes within this workspace, including the
            // last-administrator check. A global role affects every membership.
            abort_unless($workspaceId && Workspace::whereKey($workspaceId)->lockForUpdate()->first(), 403);
            abort_unless($request->user()->fresh()->role === 'Administrator'
                && WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $request->user()->id)->exists(), 403);

            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $memberships = WorkspaceMember::where('user_id', $target->id)->pluck('workspace_id');
            // Until roles are workspace-owned, a shared target cannot be
            // changed without granting or removing rights in another workspace.
            abort_unless($memberships->count() === 1 && $memberships->first() === $workspaceId, 404);

            if ($target->role === 'Administrator' && $validated['role'] !== 'Administrator') {
                $otherAdmins = User::where('role', 'Administrator')->where('active', true)
                    ->where('id', '!=', $target->id)
                    ->whereHas('workspaceMemberships', fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->exists();
                if (! $otherAdmins) {
                    return response()->json([
                        'message' => 'Cannot change this role — at least one active Administrator must remain.',
                    ], 422);
                }
            }

            $target->update(['role' => $validated['role']]);

            return null;
        }, 3);
        if ($result) {
            return $result;
        }

        return response()->json([
            'data' => $user->fresh()->only(self::SAFE_FIELDS),
        ]);
    }
}
