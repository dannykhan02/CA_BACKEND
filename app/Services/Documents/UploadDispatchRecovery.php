<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\AiCredits\CreditAccountant;
use App\Services\Intelligence\DocumentRevisionPublisher;
use Illuminate\Support\Facades\DB;

/** Closes an upload whose first queue delivery never reached a worker. */
class UploadDispatchRecovery
{
    public function claim(Document $document, ?string $revisionId): void
    {
        DB::transaction(function () use ($document, $revisionId): void {
            Document::whereKey($document->id)->lockForUpdate()->first();
            ProcessingJob::where('document_id', $document->id)->where('stage', 'virus_scan')
                ->where('intelligence_revision_id', $revisionId)
                ->where('input->source', 'upload_dispatch')->where('status', 'pending')
                ->update(['status' => 'skipped', 'output' => json_encode(['reason' => 'Queue delivery received.']),
                    'completed_at' => now()]);
        });
    }

    public function failIfUnclaimed(string $documentId, ?string $revisionId): bool
    {
        return DB::transaction(function () use ($documentId, $revisionId): bool {
            $document = Document::whereKey($documentId)->lockForUpdate()->first();
            if (! $document || $document->status !== 'Processing'
                || $document->active_intelligence_revision_id !== $revisionId) {
                return false;
            }
            $pending = ProcessingJob::where('document_id', $documentId)->where('stage', 'virus_scan')
                ->where('intelligence_revision_id', $revisionId)
                ->where('input->source', 'upload_dispatch')->where('status', 'pending')
                ->lockForUpdate()->first();
            if (! $pending) {
                return false;
            }
            $pending->forceFill(['status' => 'failed', 'completed_at' => now(),
                'error_message' => 'Initial queue delivery was not confirmed.'])->save();
            $document->forceFill(['status' => 'Failed', 'error_message' => 'Document processing could not start. Please retry processing.'])->save();
            if ($revisionId !== null) {
                app(DocumentRevisionPublisher::class)->fail($document, $revisionId);
            }
            app(CreditAccountant::class)->releaseDocument($documentId, 'upload_dispatch_failed');

            return true;
        }, 3);
    }
}
