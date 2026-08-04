<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentReprocessController extends Controller
{
    public function store(Request $request, Document $document): JsonResponse
    {
        // Was an inline in_array(...) role check via abort(403, ...) — see
        // DocumentPolicy::reprocess() for why that bypassed the
        // classification-aware view() gate (audit F-High-3).
        $this->authorize('reprocess', $document);

        if ($document->status !== 'Needs Review') {
            return response()->json([
                'message' => 'Only documents with status "Needs Review" can be reprocessed.',
            ], 422);
        }

        // Re-running GenerateInsightsJob directly (not the full chain) since
        // scan + extract already succeeded — re-running those would be
        // wasted work and risks re-scanning/re-parsing an unchanged file.
        // Note: this assumes the extracted-text cache (2hr TTL) is still
        // present. If it's expired, the job's own "text unavailable" check
        // will correctly fail it rather than silently proceeding with stale
        // or missing data — worth telling the user to re-upload in that case.
        $document->forceFill([
            'status' => 'Processing',
            'progress' => 60,
            'error_message' => null, // Clear stale error from previous failures
            'last_updated_by' => $request->user()->id,
        ])->save();

        GenerateInsightsJob::dispatch($document->id)->onQueue('extraction');

        return response()->json([
            'message' => 'Reprocessing started.',
            'data' => new DocumentResource($document->fresh()),
        ], 202);
    }
}
