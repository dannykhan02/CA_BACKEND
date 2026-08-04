<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentApproveController extends Controller
{
    public function store(Request $request, Document $document, AuditLogger $audit): JsonResponse
    {
        $this->authorize('approve', $document);

        if ($document->status !== 'Needs Review') {
            return response()->json([
                'message' => 'Only documents with status "Needs Review" can be approved.',
            ], 422);
        }

        $document->forceFill([
            'status' => 'Ready',
            'last_updated_by' => $request->user()->id,
            'error_message' => null,
            'progress' => null,
        ])->save();

        $audit->log($request->user(), 'document.approve', $document, [
            'classification' => $document->classification,
        ]);

        $document->load(['kpis', 'charts', 'pageFlags', 'uploader:id,full_name', 'lastUpdater:id,full_name']);

        return response()->json([
            'message' => 'Document approved and marked as ready.',
            'data' => new DocumentResource($document),
        ]);
    }
}
