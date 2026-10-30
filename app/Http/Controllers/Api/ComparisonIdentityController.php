<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentComparison;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\IntelligenceAccess;
use Illuminate\Http\Request;

class ComparisonIdentityController extends Controller
{
    public function show(Request $request, string $comparison, ComparisonIdentityService $identity, IntelligenceAccess $access)
    {
        $item = DocumentComparison::where('workspace_id', $access->workspace($request->user()))->findOrFail($comparison);

        return response()->json(['data' => $identity->read($request->user(), $item)]);
    }

    public function update(Request $request, string $comparison, ComparisonIdentityService $identity, IntelligenceAccess $access)
    {
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }
        $input = $request->validate(['key' => ['required', 'string', 'size:64'],
            'state' => ['required', 'in:confirmed,rejected,revoked'], 'version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $item = DocumentComparison::where('workspace_id', $access->workspace($request->user(), true))->findOrFail($comparison);

        return response()->json(['data' => $identity->decide($request->user(), $item, $input['key'], $input['state'], (int) $input['version'], trim($input['reason']))]);
    }
}
