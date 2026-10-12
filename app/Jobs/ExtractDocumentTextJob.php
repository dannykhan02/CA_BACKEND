<?php

namespace App\Jobs;

use App\Enums\WorkspaceType;
use App\Exceptions\AiProcessingException;
use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\Documents\DocumentStorageService;
use App\Services\DocumentTextExtractor;
use App\Services\EntitlementService;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Pipeline\DocumentProgress;
use App\Services\Pipeline\DocumentStagePolicy;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Support\QueueTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, DispatchesIntelligenceChain, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $documentId, public ?string $revisionId = null) {}

    public function handle(
        DocumentTextExtractor $extractor,
        PipelineStageRecorder $recorder,
        OcrEngineResolver $ocrResolver,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find($this->documentId);
        if (! $document || ! app(DocumentStagePolicy::class)->permits($document, 'extract')
            || ! app(DocumentRevisionPublisher::class)->owns($document, $this->revisionId)) {
            if (! $document) {
                Log::warning("ExtractDocumentTextJob: Document {$this->documentId} not found — unexpected null, possible soft-delete race.");
            }

            return;
        }
        app(EntitlementService::class)->reserveDocument($document);

        $extractStage = $recorder->start($document, 'extract');
        $document->forceFill(['progress' => max(25, (int) $document->progress)])->save();
        app(DocumentProgress::class)->record($document->id, 'extracting_text', 25);

        // The 'documents' disk may be a remote driver (e.g. S3/R2) — text
        // extractors and the PDF rasterizer need a real local filesystem
        // path, so the remote file is pulled to a local temp copy first.
        // $cleanupAbsolutePath tracks whether *this* job invocation still
        // owns that temp file: true until/unless fallbackToOcr() hands
        // ownership off for the JPG/PNG async-OCR case (see its comments).
        $absolutePath = app(DocumentStorageService::class)->temporaryCopy($document->file_path, 'extract_');
        $cleanupAbsolutePath = true;

        try {
            $structured = match ($document->type) {
                'PDF' => null,
                'DOCX' => $extractor->extractDocxStructured($absolutePath),
                'XLSX' => app(SpreadsheetTextExtractor::class)->extractStructured($absolutePath),
                'JPG', 'PNG' => null, // no native text layer — forces straight to OCR fallback below
                default => throw new \RuntimeException("Unsupported document type: {$document->type}"),
            };
            if ($document->type === 'PDF') {
                $text = $extractor->extractPdfText($absolutePath);
                // Test and legacy adapters may supply only the established text method.
                $structured = get_class($extractor) === DocumentTextExtractor::class
                    ? $extractor->pdfStructuredResult() : null;
            } else {
                $text = $structured?->text ?? '';
            }
            if ($structured !== null && hash_file('sha256', $absolutePath) !== $document->file_hash) {
                throw new \RuntimeException('Document source hash changed before extraction.');
            }

            // Page count only has a reliable, well-defined answer for PDF —
            // DOCX reflows dynamically with no fixed page count until
            // rendered, and XLSX has sheets, not pages. Deliberately scoped
            // to PDF only rather than guessing a number for the other types.
            if ($document->type === 'PDF') {
                $document->forceFill(['pages' => $extractor->countPdfPages($absolutePath)])->save();
            }
        } catch (\Throwable $e) {
            @unlink($absolutePath);
            $recorder->fail($extractStage, 'Document extraction failed or exceeded a resource limit.');
            $this->markExtractionFailed('Could not extract text: the file may be corrupted or password-protected.');
            app(EntitlementService::class)->releaseStaleReservations($document->workspace_id);
            $this->fail($e);

            return;
        }

        if (trim($text) === '') {
            $recorder->complete($extractStage, ['native_text_found' => false]);
            $text = $this->fallbackToOcr($document, $ocrResolver, $rasterizer, $recorder, $absolutePath);
            if ($text === null) {
                // fallbackToOcr either set a terminal status itself (no
                // provider available, OCR disabled, rasterization
                // failure), or queued OcrPageBatchJob batches via
                // appendToChain() — the last of which sets
                // extracted_text and continues the chain once OCR
                // actually completes. Either way, nothing more to do in
                // this job invocation.
                return;
            }
        } else {
            $recorder->complete($extractStage, ['native_text_found' => true]);
        }

        // Persistent source of truth — previously this only went to cache,
        // which expires in 2 hours and silently starved every downstream
        // job (embeddings, and likely AI insights) that ran after that.
        try {
            if ($structured !== null) {
                app(StructuredExtractionStore::class)->publish($document, $structured, $this->revisionId);
            } else {
                $written = DB::transaction(function () use ($document, $text): bool {
                    $locked = Document::whereKey($document->id)->lockForUpdate()->first();
                    if (! $locked || ! app(DocumentRevisionPublisher::class)->owns($locked, $this->revisionId)) {
                        return false;
                    }
                    $locked->forceFill(['extracted_text' => $text])->save();

                    return true;
                });
                if (! $written) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            $recorder->fail($extractStage, 'Document extraction revision could not be published.');
            $this->markExtractionFailed('Could not publish document extraction.');
            app(EntitlementService::class)->releaseStaleReservations($document->workspace_id);
            $this->fail($e);

            return;
        } finally {
            if ($cleanupAbsolutePath) {
                @unlink($absolutePath);
            }
        }

        // Cache retained as a short-lived read-through optimisation only —
        // nothing downstream should treat it as authoritative anymore.
        Cache::put("document:{$document->id}:extracted_text", $text, now()->addHours(2));
        if (! app(DocumentRevisionPublisher::class)->owns($document->fresh(), $this->revisionId)) {
            return;
        }
        $document->forceFill(['progress' => 50])->save();
        $this->dispatchIntelligenceChain($document, $this->revisionId === null);
    }

    /**
     * Returns null in every case: either a terminal document status was
     * already set (no OCR provider available, OCR disabled, rasterization
     * failure), or OcrPageBatchJob batches were successfully appended to
     * this job's chain via appendToChain() — the last of which finishes
     * extraction (sets extracted_text, progress) and calls
     * dispatchIntelligenceChain() once OCR actually completes.
     *
     * The OCR loop itself no longer runs here — it used to run
     * synchronously inside this single job (one Anthropic call per page,
     * sequentially, no checkpointing), which meant this job's 120s
     * timeout was effectively a per-document OCR budget rather than a
     * per-call one, and fired mid-loop on any real multi-page scanned
     * document. See docs/REGRESSION_SCENARIO.md, 2026-08-30 entry.
     */
    private function fallbackToOcr(
        Document $document,
        OcrEngineResolver $resolver,
        PdfRasterizer $rasterizer,
        PipelineStageRecorder $recorder,
        string $absolutePath,
    ): ?string {
        $batchLimit = config('document_processing.ocr_batch_max_pages');
        if (! is_int($batchLimit) || $batchLimit < 1 || $batchLimit > 3) {
            throw new \RuntimeException('Invalid OCR batch resource policy.');
        }
        $ocrEnabled = $document->workspace?->settings?->ocr_enabled ?? false;
        $ocrEligibleTypes = ['PDF', 'JPG', 'PNG'];
        $isOcrEligibleType = in_array($document->type, $ocrEligibleTypes, true);

        if (! $isOcrEligibleType || ! $ocrEnabled) {
            $recorder->skip($document, 'ocr_check', $ocrEnabled ? 'document type not eligible for OCR' : 'ocr disabled for workspace');
            $this->markExtractionFailed($isOcrEligibleType
                ? 'No extractable text found and OCR is disabled for this workspace.'
                : 'No extractable text found in this document.');
            $this->fail(new \RuntimeException('Empty extracted text, OCR unavailable.'));
            @unlink($absolutePath);

            return null;
        }

        // Confirm a provider is actually configured/available before
        // queuing any batch jobs — fail fast here, once, rather than have
        // every batch job independently discover "no provider" and each
        // write its own failed ProcessingJob row for the same root cause.
        try {
            $resolver->resolve($document);
        } catch (\Throwable $e) {
            @unlink($absolutePath);
            $recorder->fail($recorder->start($document, 'ocr_check'), 'OCR provider unavailable.');
            $this->markExtractionFailed('OCR could not process this scanned document.',
                $document->workspace?->type === WorkspaceType::Personal ? 'Failed' : 'Needs Review');

            return null;
        }

        try {
            // A scanned PDF needs rasterizing into one image per page; a
            // bare JPG/PNG upload already IS the single page image. Either
            // way our downloaded temp copy is discarded right after — for
            // JPG/PNG specifically, OcrPageBatchJob no longer trusts a raw
            // local path across the queue boundary (nothing guaranteed
            // this temp file would survive until that job actually ran);
            // instead it re-fetches its own fresh copy from R2 when it
            // executes. See OcrPageBatchJob's $fetchPageFromSourceDisk.
            $imagePaths = $document->type === 'PDF'
                ? $rasterizer->toPageImages($absolutePath)
                : [$absolutePath];
            @unlink($absolutePath);
        } catch (\Throwable $e) {
            @unlink($absolutePath);
            $recorder->fail($recorder->start($document, 'ocr_check'), 'OCR rasterization failed.');
            $this->markExtractionFailed('OCR could not process this scanned document.',
                $document->workspace?->type === WorkspaceType::Personal ? 'Failed' : 'Needs Review');

            return null;
        }

        // AI credits: price OCR from the page count and enforce the page cap before any vision call.
        if (! app(AiCreditAdmission::class)->admitOcr($document, count($imagePaths))) {
            $recorder->skip($document, 'ocr_check', 'ocr not admitted: credits or page limit');
            if ($document->type === 'PDF') {
                $rasterizer->cleanup($imagePaths);
            }

            return null;
        }

        // Batched into chained jobs rather than looped in-process here —
        // this job's single 120s timeout was sized for one AI call, not a
        // page-count-dependent loop (see docs/REGRESSION_SCENARIO.md,
        // 2026-08-30 entry). 3 pages/batch is sized off real measured
        // per-page OCR latency (5-11s/page observed on an 18-page
        // reference document), leaving comfortable headroom under
        // OcrPageBatchJob's own 90s timeout even in the worst observed
        // case, with an extra safety margin after observing one real batch exceed 90s at 4 pages/batch during testing.
        $storedPageImages = $document->type === 'PDF';
        if ($storedPageImages) {
            $localImages = $imagePaths;
            try {
                foreach ($localImages as $page => $localPath) {
                    $key = 'ai-ocr/'.$document->workspace_id.'/'.$document->id.'/'.$document->file_hash.'/page-'.$page.'.png';
                    $stream = fopen($localPath, 'rb');
                    try {
                        Storage::disk('documents')->put($key, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $imagePaths[$page] = $key;
                }
            } finally {
                $rasterizer->cleanup($localImages);
            }
        }
        $batches = array_chunk($imagePaths, $batchLimit);
        // Only a real PDF rasterization produces a disposable temp
        // directory — a bare JPG/PNG upload's "image path" IS the
        // original stored file, which must never be cleaned up here.
        $tempDir = null; // Page assets now survive worker replacement in the documents disk.
        // JPG/PNG is always exactly one page, so exactly one batch below —
        // that batch re-fetches its own fresh copy from R2 at execution
        // time rather than trusting the (already-deleted) local path
        // above. See OcrPageBatchJob's $fetchPageFromSourceDisk.
        $fetchFromSourceDisk = $document->type !== 'PDF';
        $startingPage = 1;
        $batchJobs = [];

        foreach ($batches as $index => $batchPaths) {
            $isLastBatch = $index === array_key_last($batches);
            $batchJobs[] = (new OcrPageBatchJob(
                documentId: $document->id,
                pageImagePaths: $batchPaths,
                startingPageNumber: $startingPage,
                isLastBatch: $isLastBatch,
                tempDir: $isLastBatch ? $tempDir : null,
                fetchPageFromSourceDisk: $fetchFromSourceDisk,
                storedPageImages: $storedPageImages,
                revisionId: $this->revisionId,
            ))->onQueue(QueueTopology::for(OcrPageBatchJob::class));
            $startingPage += count($batchPaths);
        }

        $this->prependToChain($batchJobs);

        return null;
    }

    private function markExtractionFailed(string $message, string $status = 'Failed'): void
    {
        DB::transaction(function () use ($message, $status): void {
            $locked = Document::whereKey($this->documentId)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'Failed'
                || ! app(DocumentRevisionPublisher::class)->owns($locked, $this->revisionId)) {
                return;
            }
            $locked->forceFill(['status' => $status, 'progress' => 100, 'error_message' => $message])->save();
            if ($this->revisionId !== null) {
                app(DocumentRevisionPublisher::class)->fail($locked, $this->revisionId);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        // Previously fell back to a generic 'Text extraction failed.'
        // string, which masked real errors that happened after extraction
        // had already succeeded (e.g. this allowFailures() bug) and made
        // them look like extraction problems in the UI/logs.
        Log::error('ExtractDocumentTextJob failed', [
            'document_id' => $this->documentId,
            'exception' => get_class($e),
            'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'extraction_failure',
        ]);

        $this->markExtractionFailed('Document processing could not be completed. Please try again.');
    }
}
