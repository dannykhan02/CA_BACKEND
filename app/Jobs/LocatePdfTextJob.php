<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ocr\PdfTextLocator;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Optional word-box stage for the scanned pages of a PDF.
 *
 * Same contract as LocateImageTextJob and recorded under the same
 * 'image_text_location' stage name, so every place that already knows this stage is
 * optional (never required, never 'failed', never charged) treats it identically:
 * handle() cannot throw, it touches no credit or entitlement service, and it is
 * dispatched outside the intelligence batch.
 */
class LocatePdfTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Optional work is attempted once; a retry would spend a worker for a highlight. */
    public int $tries = 1;

    /** Below the default pool's 180s worker timeout, and above ocr.pdf_location.total_budget_seconds. */
    public int $timeout = 150;

    /** @param list<int> $pages 1-based pages that were read by OCR. */
    public function __construct(
        public string $documentId,
        public ?string $revisionId = null,
        public array $pages = [],
    ) {}

    public function handle(PdfTextLocator $locator, PipelineStageRecorder $recorder): void
    {
        try {
            $document = Document::find($this->documentId);
            if (! $document || $document->trashed() || ! $locator->enabled() || ! $locator->supports($document)) {
                return;
            }
            if ($this->revisionId !== null) {
                $document->setAttribute('read_intelligence_revision_id', $this->revisionId);
            }
            if (! $locator->engineAvailable($document)) {
                $recorder->skip($document, LocateImageTextJob::STAGE, 'tesseract_unavailable');

                return;
            }
            $stage = $recorder->start($document, LocateImageTextJob::STAGE);
            $recorder->complete($stage, $locator->locate($document, $this->pages)->toArray());
        } catch (\Throwable $e) {
            Log::warning('PDF word-box stage could not run', [
                'document_id' => $this->documentId,
                'revision_id' => $this->revisionId,
                'reason' => get_class($e).': '.substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
