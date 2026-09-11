<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceCreditController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Balance belongs to the current workspace for both workspace types;
        // unlike documents, there is no uploader/classification filter.
        $credits = WorkspaceCredit::where('workspace_id', $request->user()->current_workspace_id)->first();

        return $this->success('Workspace credits retrieved.', [
            'documents_remaining' => $credits?->documents_remaining ?? 0,
            'documents_purchased_total' => $credits?->documents_purchased_total ?? 0,
        ]);
    }
}
