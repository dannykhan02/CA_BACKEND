<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

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

        GenerateInsightsJob::dispatch($document->id, true)->onQueue('extraction');

        // Was a chain with ->allowFailures() bolted on — that method only
        // exists on Bus::batch()'s PendingBatch, not on PendingChain, so
        // every reprocess call fatally errored here too (same root cause as
        // ExtractDocumentTextJob::dispatchIntelligenceChain()). Switched to
        // a batch so one stage failing doesn't cancel the rest.
        //
        // GenerateDocumentSummaryJob is deliberately excluded from the
        // batch array and dispatched in finally() instead — it reads the
        // entities/risks/deadlines relations written by its siblings, and
        // a batch gives no ordering guarantee, so running it inside the
        // batch risks it firing before those siblings have written
        // anything. finally() only fires once every batch job has settled.
        $documentId = $document->id;

        Bus::batch([
            new \App\Jobs\ClassifyDocumentTypeJob($documentId, true),
            new \App\Jobs\ExtractDocumentEntitiesJob($documentId, true),
            new \App\Jobs\DetectDocumentRisksJob($documentId, true),
            new \App\Jobs\DetectDocumentDeadlinesJob($documentId, true),
        ])
            ->name("document-reprocess:{$documentId}")
            ->onQueue('extraction')
            ->allowFailures()
            ->finally(function () use ($documentId) {
                \App\Jobs\GenerateDocumentSummaryJob::dispatch($documentId, true)
                    ->onQueue('extraction');
            })
            ->dispatch();

        return response()->json([
            'message' => 'Reprocessing started.',
            'data' => new DocumentResource($document->fresh()),
        ], 202);
    }
}