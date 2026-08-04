<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\RejectDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class DocumentRejectController extends Controller
{
    public function store(RejectDocumentRequest $request, Document $document, AuditLogger $audit): JsonResponse
    {
        $this->authorize('reject', $document);

        if ($document->status !== 'Needs Review') {
            return response()->json([
                'message' => 'Only documents with status "Needs Review" can be rejected.',
            ], 422);
        }

        $note = trim((string) $request->validated('note', ''));

        $document->forceFill([
            'status' => 'Failed',
            'last_updated_by' => $request->user()->id,
            'error_message' => $note !== ''
                ? $note
                : 'Document rejected during review.',
            'progress' => null,
        ])->save();

        $audit->log($request->user(), 'document.reject', $document, [
            'classification' => $document->classification,
            'has_note' => $note !== '',
        ]);

        $document->load(['kpis', 'charts', 'pageFlags', 'uploader:id,full_name', 'lastUpdater:id,full_name']);

        return response()->json([
            'message' => 'Document rejected.',
            'data' => new DocumentResource($document),
        ]);
    }
}
