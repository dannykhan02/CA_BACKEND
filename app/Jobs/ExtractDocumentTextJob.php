<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use App\Services\DocumentTextExtractor;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, DispatchesIntelligenceChain;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(
        DocumentTextExtractor $extractor,
        PipelineStageRecorder $recorder,
        OcrEngineResolver $ocrResolver,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find($this->documentId);
        if (! $document || $document->status === 'Failed') {
            return;
        }

        $extractStage = $recorder->start($document, 'extract');
        $document->forceFill(['progress' => 25])->save();

        // The 'documents' disk may be a remote driver (e.g. S3/R2) — text
        // extractors and the PDF rasterizer need a real local filesystem
        // path, so the remote file is pulled to a local temp copy first.
        // $cleanupAbsolutePath tracks whether *this* job invocation still
        // owns that temp file: true until/unless fallbackToOcr() hands
        // ownership off for the JPG/PNG async-OCR case (see its comments).
        $absolutePath = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($absolutePath, Storage::disk('documents')->get($document->file_path));
        chmod($absolutePath, 0644);
        $cleanupAbsolutePath = true;

        try {
            $text = match ($document->type) {
                'PDF' => $extractor->extractPdfText($absolutePath),
                'DOCX' => $extractor->extractDocxText($absolutePath),
                'XLSX' => app(\App\Services\Extraction\SpreadsheetTextExtractor::class)->extract($absolutePath),
                'JPG', 'PNG' => '', // no native text layer — forces straight to OCR fallback below
                default => throw new \RuntimeException("Unsupported document type: {$document->type}"),
            };
        } catch (\Throwable $e) {
            @unlink($absolutePath);
            $recorder->fail($extractStage, $e->getMessage());
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'Could not extract text: the file may be corrupted or password-protected.',
            ])->save();
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

        if ($cleanupAbsolutePath) {
            @unlink($absolutePath);
        }

        // Persistent source of truth — previously this only went to cache,
        // which expires in 2 hours and silently starved every downstream
        // job (embeddings, and likely AI insights) that ran after that.
        $document->forceFill(['extracted_text' => $text])->save();

        // Cache retained as a short-lived read-through optimisation only —
        // nothing downstream should treat it as authoritative anymore.
        Cache::put("document:{$document->id}:extracted_text", $text, now()->addHours(2));
        $document->forceFill(['progress' => 50])->save();
        $this->dispatchIntelligenceChain($document);
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
        $ocrEnabled = $document->workspace?->settings?->ocr_enabled ?? false;
        $ocrEligibleTypes = ['PDF', 'JPG', 'PNG'];
        $isOcrEligibleType = in_array($document->type, $ocrEligibleTypes, true);

        if (! $isOcrEligibleType || ! $ocrEnabled) {
            $recorder->skip($document, 'ocr_check', $ocrEnabled ? 'document type not eligible for OCR' : 'ocr disabled for workspace');
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => $isOcrEligibleType
                    ? 'No extractable text found and OCR is disabled for this workspace.'
                    : 'No extractable text found in this document.',
            ])->save();
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
            $recorder->fail($recorder->start($document, 'ocr_check'), $e->getMessage());
            $document->forceFill([
                'status' => 'Needs Review',
                'error_message' => 'OCR could not process this scanned document.',
            ])->save();
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
            $recorder->fail($recorder->start($document, 'ocr_check'), $e->getMessage());
            $document->forceFill([
                'status' => 'Needs Review',
                'error_message' => 'OCR could not process this scanned document.',
            ])->save();
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
        $batches = array_chunk($imagePaths, 3);
        // Only a real PDF rasterization produces a disposable temp
        // directory — a bare JPG/PNG upload's "image path" IS the
        // original stored file, which must never be cleaned up here.
        $tempDir = $document->type === 'PDF' ? dirname($imagePaths[0]) : null;
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
            ))->onQueue('extraction');
            $startingPage += count($batchPaths);
        }

        $this->prependToChain($batchJobs);

        return null;
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
            'message' => $e->getMessage(),
        ]);

        $document = Document::find($this->documentId);
        $document?->forceFill([
            'status' => 'Failed',
            'error_message' => $document->error_message ?? ($e->getMessage() ?: get_class($e)),
        ])->save();
    }
}
