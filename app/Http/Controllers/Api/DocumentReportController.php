<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records that a Word report was generated client-side for this document.
 * No file is generated or stored server-side — the .docx is built entirely
 * in the browser from data the frontend already has. This endpoint exists
 * solely to create an audit trail entry, matching the existing
 * 'document.viewed' / 'document.uploaded' pattern.
 */
class DocumentReportController extends Controller
{
    public function store(Request $request, Document $document, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorize('view', $document);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $auditLogger->log(
            $request->user(),
            'document.report_generated',
            $document,
            array_filter(['note' => $validated['note'] ?? null])
        );

        return response()->json(['message' => 'Report generation recorded.']);
    }
}