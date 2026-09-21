<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceCreditController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace && $workspace->members()->where('user_id', $request->user()->id)->exists(), 403);

        return $this->success('Workspace credits retrieved.', app(EntitlementService::class)->summary($workspace->id));
    }
}
