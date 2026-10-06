<?php

namespace App\Jobs;

use App\Enums\WorkspaceType;
use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use App\Models\OcrResult;
use App\Services\Documents\DocumentStorageService;
use App\Services\EntitlementService;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Processes one slice of a document's rasterized pages through the OCR
 * provider. Introduced to fix the P1 regression: ExtractDocumentTextJob
 * used to OCR every page of a document sequentially inside one
 * 120s-timeout job, which reliably timed out mid-loop on any real
 * multi-page scanned document (see docs/REGRESSION_SCENARIO.md,
 * 2026-08-30 entry). Several of these are appended to the existing
 * upload/reprocess chain (ScanUploadedFileJob -> ExtractDocumentTextJob
 * -> ...) via appendToChain() from
 * ExtractDocumentTextJob::fallbackToOcr(), each with its own bounded
 * timeout, instead of one job holding the whole document's OCR budget.
 *
 * Batch size (3 pages) and timeout (90s) are sized off real measured
 * per-page OCR latency — 5-11s/page observed on an 18-page reference
 * document — not guessed.
 *
 * Reuses the 'ocr_check' processing_jobs stage (no new enum value added
 * — see class-level note in fallbackToOcr()'s caller for why); batches
 * are distinguished via the 'output' json instead of the stage name.
 */
class OcrPageBatchJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, DispatchesIntelligenceChain, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    /**
     * @param  string[]  $pageImagePaths  Absolute paths to this batch's page images, in page order.
     * @param  int  $startingPageNumber  1-based page_number of the first image in this batch.
     * @param  bool  $isLastBatch  Whether this batch finalizes extracted_text and continues the chain.
     * @param  string|null  $tempDir  Rasterization temp dir to clean up — only set (and only ever
     *                                cleaned up) on the last batch, and only when rasterization actually created one.
     *                                Never set for a bare JPG/PNG upload, whose "page image" is the original stored file.
     * @param  bool  $fetchPageFromSourceDisk  When true (JPG/PNG source only, always a single-page,
     *                                         single-batch job), $pageImagePaths is ignored for extraction — instead a fresh copy
     *                                         is re-downloaded from the 'documents' disk (R2) right here at execution time and
     *                                         deleted again once this batch finishes, rather than trusting a raw local path handed
     *                                         across the queue boundary to still exist whenever this job happens to run.
     */
    public function __construct(
        public string $documentId,
        public array $pageImagePaths,
        public int $startingPageNumber,
        public bool $isLastBatch,
        public ?string $tempDir = null,
        public bool $fetchPageFromSourceDisk = false,
        public bool $storedPageImages = false,
    ) {}

    public function handle(OcrEngineResolver $resolver, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);
        if ($document) {
            app(EntitlementService::class)->reserveDocument($document);
        }
        if (! $document || $document->status === 'Failed') {
            $this->cleanupTempDir();

            return;
        }

        $batchStage = $recorder->start($document, 'ocr_check');

        // Completed OCR pages are checkpoints; a retry skips them rather than deleting them.

        // For the JPG/PNG single-image case, $pageImagePaths[0] points at
        // a temp file ExtractDocumentTextJob already deleted — nothing
        // guaranteed it would survive the gap between being queued and
        // this job actually running. Re-fetch a fresh copy straight from
        // the 'documents' disk (R2) right here instead, and clean it up
        // ourselves once we're done with it, regardless of outcome.
        $fetchedSourcePath = null;
        if ($this->fetchPageFromSourceDisk) {
            $fetchedSourcePath = app(DocumentStorageService::class)->temporaryCopy($document->file_path, 'ocr_src_');
        }

        try {
            $provider = $resolver->resolve($document);

            foreach ($this->pageImagePaths as $offset => $imagePath) {
                if (OcrResult::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
                    ->where('page_number', $this->startingPageNumber + $offset)->exists()) {
                    if ($this->storedPageImages) {
                        Storage::disk('documents')->delete($imagePath);
                    }

                    continue;
                }
                $localPage = $this->storedPageImages
                    ? app(DocumentStorageService::class)->temporaryCopy($imagePath, 'ocr_page_') : null;
                try {
                    $result = $provider->extractPage($localPage ?? $fetchedSourcePath ?? $imagePath, $document);
                } finally {
                    if ($localPage) {
                        @unlink($localPage);
                    }
                }

                OcrResult::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'page_number' => $this->startingPageNumber + $offset,
                    'engine' => $provider->engine(),
                    'raw_text' => $result->text,
                    'confidence' => $result->confidence,
                    'metadata' => $result->metadata,
                ]);
                if ($this->storedPageImages) {
                    Storage::disk('documents')->delete($imagePath);
                }
            }
        } catch (ProviderBusyException $e) {
            // Completed pages are checkpointed; a delayed copy (same chain) resumes the rest.
            if ($fetchedSourcePath) {
                @unlink($fetchedSourcePath);
            }
            $recorder->complete($batchStage, ['deferred_for_provider_capacity' => true]);
            $this->deferForProvider($e, ['document_id' => $document->id]);

            return;
        } catch (\Throwable $e) {
            if ($fetchedSourcePath) {
                @unlink($fetchedSourcePath);
            }
            $recorder->fail($batchStage, $e->getMessage());
            $document->forceFill([
                'status' => $document->workspace?->type === WorkspaceType::Personal ? 'Failed' : 'Needs Review',
                'error_message' => 'OCR could not process this scanned document.',
            ])->save();
            $this->cleanupTempDir();

            // Deliberately not calling $this->fail() — matches the
            // fallbackToOcr() behavior: an OCR failure terminates into
            // 'Failed' for Personal or 'Needs Review' for Organization, without
            // cancelling the rest of the chain. GenerateInsightsJob and
            // GenerateEmbeddingsJob both guard on document status
            // themselves and simply no-op for a document that isn't
            // 'Processing'/'Ready' respectively.
            return;
        }

        if ($fetchedSourcePath) {
            @unlink($fetchedSourcePath);
        }

        $recorder->complete($batchStage, [
            'batch_pages' => count($this->pageImagePaths),
            'starting_page_number' => $this->startingPageNumber,
            'is_last_batch' => $this->isLastBatch,
        ]);

        if (! $this->isLastBatch) {
            return;
        }

        $this->finalizeDocument($document);
    }

    private function finalizeDocument(Document $document): void
    {
        $text = $document->ocrResults()
            ->orderBy('page_number')
            ->pluck('raw_text')
            ->map(fn ($t) => $t ?? '')
            ->implode("\f");

        $this->cleanupTempDir();

        if (trim($text) === '') {
            $document->forceFill([
                'status' => $document->workspace?->type === WorkspaceType::Personal ? 'Failed' : 'Needs Review',
                'error_message' => $document->workspace?->type === WorkspaceType::Personal
                    ? 'OCR ran but found no readable text.'
                    : 'OCR ran but found no readable text — needs manual review.',
            ])->save();

            return;
        }

        // Persistent source of truth — same reasoning as
        // ExtractDocumentTextJob's native-text path: nothing downstream
        // should treat the 2hr cache entry as authoritative.
        $document->forceFill(['extracted_text' => $text])->save();
        Cache::put("document:{$document->id}:extracted_text", $text, now()->addHours(2));
        $document->forceFill(['progress' => 50])->save();

        $this->dispatchIntelligenceChain($document);
    }

    private function cleanupTempDir(): void
    {
        if (! $this->isLastBatch || ! $this->tempDir || ! is_dir($this->tempDir)) {
            return;
        }

        foreach (glob($this->tempDir.'/*.png') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('OcrPageBatchJob failed', [
            'document_id' => $this->documentId,
            'starting_page_number' => $this->startingPageNumber,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        $document = Document::find($this->documentId);
        if ($document && $document->status !== 'Failed') {
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'Document processing could not be completed. Please try again.',
            ])->save();
        }

        $this->cleanupTempDir();
    }
}
