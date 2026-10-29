<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiCredits\CreditUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditUsageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['range' => ['sometimes', 'in:cycle,6m']]);
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace && $workspace->members()->where('user_id', $request->user()->id)->exists(), 403);

        return $this->success('Credit usage retrieved.', app(CreditUsage::class)->forWorkspace($workspace->id, $validated['range'] ?? 'cycle'));
    }
}
