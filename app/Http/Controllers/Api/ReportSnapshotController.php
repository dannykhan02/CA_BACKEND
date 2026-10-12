<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\IntelligenceAccess;
use App\Services\Reports\ReportSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportSnapshotController extends Controller
{
    public function document(Request $request, Document $document, ReportSnapshotService $snapshots): JsonResponse
    {
        return response()->json(['data' => $snapshots->document($request, $document)]);
    }

    public function matter(Request $request, string $matter, IntelligenceAccess $access,
        ReportSnapshotService $snapshots): JsonResponse
    {
        return response()->json(['data' => $snapshots->matter($request, $access->matter($request->user(), $matter))]);
    }
}
