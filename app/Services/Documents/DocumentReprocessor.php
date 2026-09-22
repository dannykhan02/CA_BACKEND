<?php

namespace App\Services\Documents;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\DocumentIntelligenceService;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\Bus;

class DocumentReprocessor
{
    public function reprocess(Document $document, User $actor, bool $intelligenceOnly = false): Document
    {
        if ($intelligenceOnly) {
            $states = app(DocumentIntelligenceService::class)->getProcessingStatus($document);
            abort_unless($document->status === 'Ready' && $document->extracted_text
                && count(array_intersect($states, ['failed', 'not_started'])) > 0
                && count(array_intersect($states, ['pending', 'processing'])) === 0, 422,
                'Only missing or failed intelligence on a processed document can be retried here.');
        }

        abort_unless($intelligenceOnly || in_array($document->status, ['Failed', 'Needs Review'], true),
            422, 'Only Failed or Needs Review documents can be reprocessed.');

        app(EntitlementService::class)->reserveDocument($document, true);

        if ($document->status === 'Failed' && empty($document->extracted_text)) {
            // Full pipeline re-run: scan/extract never produced usable text,
            // so re-running only the AI batch (like the Needs-Review path
            // below) would run against nothing. Mirrors the exact chain
            // DocumentUploadController::store() dispatches on first upload.
            $document->forceFill([
                'status' => 'Processing',
                'progress' => 0,
                'error_message' => null,
                'last_updated_by' => $actor->id,
            ])->save();

            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id))->onQueue('extraction'),
                (new GenerateInsightsJob($document->id, true))->onQueue('extraction'),
                (new GenerateEmbeddingsJob($document->id))->onQueue('extraction'),
            ])->onQueue('default')->dispatch($document->id);

            return $document->fresh();
        }

        // Existing Needs-Review-style AI-only batch path — also now covers a
        // Failed document that already has extracted_text (no need to
        // re-scan/re-extract; text is there, only the AI stages need a retry).
        if (! $intelligenceOnly) {
            $document->forceFill([
                'status' => 'Processing', 'progress' => 60, 'error_message' => null,
                'last_updated_by' => $actor->id,
            ])->save();
            GenerateInsightsJob::dispatch($document->id, true)->onQueue('extraction');
        } else {
            $document->update(['last_updated_by' => $actor->id]);
            foreach (['document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
                ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                    'stage' => $stage, 'status' => 'pending']);
            }
        }

        $documentId = $document->id;

        Bus::batch([
            new ClassifyDocumentTypeJob($documentId, true),
            new ExtractDocumentEntitiesJob($documentId, true),
            new DetectDocumentRisksJob($documentId, true),
            new DetectDocumentDeadlinesJob($documentId, true),
        ])
            ->name("document-reprocess:{$documentId}")
            ->onQueue('extraction')
            ->allowFailures()
            ->finally(function () use ($documentId) {
                GenerateDocumentSummaryJob::dispatch($documentId, true)
                    ->onQueue('extraction');
            })
            ->dispatch();

        return $document->fresh();
    }
}
