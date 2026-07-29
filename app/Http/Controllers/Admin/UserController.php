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

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::select('id', 'full_name', 'email', 'role', 'active', 'email_verified_at')->get(),
        ]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::VALID_ROLES)],
        ]);

        $user->update(['role' => $validated['role']]);

        return response()->json(['data' => $user->fresh()]);
    }
}
