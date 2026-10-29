<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiCredits\CreditActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditActivityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace && $workspace->members()->where('user_id', $request->user()->id)->exists(), 403);

        return $this->success('Credit activity retrieved.', app(CreditActivity::class)->forWorkspace($workspace->id, (int) ($validated['limit'] ?? 10)));
    }
}
