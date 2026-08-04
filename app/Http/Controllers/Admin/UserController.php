<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const VALID_ROLES = ['Administrator', 'Reviewer', 'Analyst', 'Viewer'];

    private const SAFE_FIELDS = ['id', 'full_name', 'email', 'role', 'active', 'email_verified_at'];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::select(self::SAFE_FIELDS)->get(),
        ]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::VALID_ROLES)],
        ]);

        // Audit F-Medium-4: nothing previously stopped the last active
        // Administrator from demoting themselves (or another admin demoting
        // the only other one), which locks the whole org out of the admin
        // panel with no way back in short of a direct DB edit.
        $isDemotingAdmin = $user->role === 'Administrator' && $validated['role'] !== 'Administrator';

        if ($isDemotingAdmin) {
            $remainingAdmins = User::where('role', 'Administrator')
                ->where('active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($remainingAdmins === 0) {
                return response()->json([
                    'message' => 'Cannot change this role — at least one active Administrator must remain.',
                ], 422);
            }
        }

        $user->update(['role' => $validated['role']]);

        return response()->json([
            'data' => $user->fresh()->only(self::SAFE_FIELDS),
        ]);
    }
}
