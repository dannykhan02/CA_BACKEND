<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ocr\ImageTextLocator;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The optional 'image_text_location' stage: word boxes for a JPG/PNG source.
 *
 * OPTIONAL means strictly optional, and that is the whole design constraint:
 *
 *  - 'image_text_location' is in no required-stage list. Neither
 *    DocumentRevisionPublisher::publish()'s $required nor
 *    normalStagesComplete()'s, so this stage can never hold a revision back.
 *    It is also absent from ProcessingStageReconciler::OPTIONAL_STAGES, whose
 *    members get expired into 'failed' — which is correct here, because this
 *    stage must never produce a 'failed' row at all (below).
 *  - It records 'completed' with a reason, never 'failed'.
 *    DocumentIntelligenceService::getDocumentFailure() reports the most recent
 *    failed row outside its own STAGES list as the document's failure stage, so
 *    a failed row here would make an unrelated document failure claim it was
 *    caused by OCR. A location attempt that found nothing genuinely did
 *    complete; textLocated=false comes from the absence of anchors, not from
 *    this row.
 *  - handle() cannot throw, so there is no retry and no failed_jobs entry, and
 *    $tries is 1 to say so explicitly.
 *  - It touches no credit or entitlement service. Nothing here charges.
 *  - It is dispatched outside the intelligence batch, so it cannot cancel,
 *    delay or fail a sibling stage.
 *
 * It is revision-scoped: the processing_jobs row is pinned to the revision that
 * produced the text, and SkipsUnchangedDocuments is deliberately not used — an
 * unchanged file_hash is no reason to skip, because the boxes belong to the
 * revision's text, not to the file.
 */
class LocateImageTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const STAGE = 'image_text_location';

    /** Optional work is attempted once; a retry would spend a worker for a highlight. */
    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(
        public string $documentId,
        public ?string $revisionId = null,
    ) {}

    public function handle(ImageTextLocator $locator, PipelineStageRecorder $recorder): void
    {
        try {
            $document = Document::find($this->documentId);
            if (! $document || $document->trashed() || ! $locator->enabled()
                || ! $locator->supports($document)) {
                return;
            }
            // Pin the stage row to the revision whose text these boxes locate,
            // even if that revision has published by the time this runs.
            if ($this->revisionId !== null) {
                $document->setAttribute('read_intelligence_revision_id', $this->revisionId);
            }
            if (! $locator->engineAvailable($document)) {
                // Recorded as not-run rather than as a completed attempt: the
                // engine is absent, so there was nothing to attempt.
                $recorder->skip($document, self::STAGE, 'tesseract_unavailable');

                return;
            }
            $stage = $recorder->start($document, self::STAGE);
            $result = $locator->locate($document, persist: true,
                timeoutSeconds: (int) config('ocr.image_location.timeout_seconds'));
            $recorder->complete($stage, $result->toArray());
        } catch (\Throwable $e) {
            // Nothing above is allowed to escape: this stage failing must look
            // like no stage at all to the rest of the pipeline.
            Log::warning('Image word-box stage could not run', [
                'document_id' => $this->documentId,
                'revision_id' => $this->revisionId,
                'reason' => get_class($e).': '.substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
