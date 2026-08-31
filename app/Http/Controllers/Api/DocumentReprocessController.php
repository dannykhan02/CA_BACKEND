<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class DocumentReprocessController extends Controller
{
    public function store(Request $request, Document $document): JsonResponse
    {
        $this->authorize('reprocess', $document);

        if ($document->status === 'Failed' && empty($document->extracted_text)) {
            // Full pipeline re-run: scan/extract never produced usable text,
            // so re-running only the AI batch (like the Needs-Review path
            // below) would run against nothing. Mirrors the exact chain
            // DocumentUploadController::store() dispatches on first upload.
            $document->forceFill([
                'status' => 'Processing',
                'progress' => 0,
                'error_message' => null,
                'last_updated_by' => $request->user()->id,
            ])->save();

            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id))->onQueue('extraction'),
                (new GenerateInsightsJob($document->id))->onQueue('extraction'),
                (new GenerateEmbeddingsJob($document->id))->onQueue('extraction'),
            ])->onQueue('default')->dispatch($document->id);

            return response()->json([
                'message' => 'Reprocessing started.',
                'data' => new DocumentResource($document->fresh()),
            ], 202);
        }

        if ($document->status !== 'Needs Review' && $document->status !== 'Failed') {
            return response()->json([
                'message' => 'Only documents with status "Needs Review" or "Failed" (with existing extracted text) can be reprocessed.',
            ], 422);
        }

        // Existing Needs-Review-style AI-only batch path — also now covers a
        // Failed document that already has extracted_text (no need to
        // re-scan/re-extract; text is there, only the AI stages need a retry).
        $document->forceFill([
            'status' => 'Processing',
            'progress' => 60,
            'error_message' => null,
            'last_updated_by' => $request->user()->id,
        ])->save();

        GenerateInsightsJob::dispatch($document->id, true)->onQueue('extraction');

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
