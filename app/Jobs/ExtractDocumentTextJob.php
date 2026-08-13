<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\OcrResult;
use App\Services\DocumentTextExtractor;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        $absolutePath = Storage::disk('documents')->path($document->file_path);

        try {
            $text = match ($document->type) {
                'PDF' => $extractor->extractPdfText($absolutePath),
                'DOCX' => $extractor->extractDocxText($absolutePath),
                'XLSX' => app(\App\Services\Extraction\SpreadsheetTextExtractor::class)->extract($absolutePath),
                'JPG', 'PNG' => '', // no native text layer — forces straight to OCR fallback below
                default => throw new \RuntimeException("Unsupported document type: {$document->type}"),
            };
        } catch (\Throwable $e) {
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
                return; // fallbackToOcr already set status + returned
            }
        } else {
            $recorder->complete($extractStage, ['native_text_found' => true]);
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
     * Was a chain (SomeJob::withChain([...])) with ->allowFailures() bolted
     * on — allowFailures() only exists on PendingBatch (Bus::batch()), not
     * PendingChain, so this fatally errored on every document. Switched to
     * a real batch so one stage failing (e.g. risks) doesn't cancel the
     * others (entities, deadlines), matching the intended
     * "classification ✓, entities ✓, risks ✗, deadlines pending" partial
     * state.
     *
     * GenerateDocumentSummaryJob is deliberately NOT in the batch array.
     * It reads the document_type/entities/risks/deadlines relations
     * written by these four jobs, and a batch gives no ordering guarantee
     * between its jobs — running it inside the batch risks a worker
     * picking it up before its siblings have written anything, silently
     * producing an incomplete summary instead of erroring. finally() runs
     * once, after every job in the batch has settled (success, failure,
     * or skip) — unlike then(), which only fires if nothing failed, which
     * would drop the summary entirely in the "risks ✗" partial-failure
     * case this pipeline is meant to support.
     */
    private function dispatchIntelligenceChain($document): void
    {
        $documentId = $document->id;

        Bus::batch([
            new \App\Jobs\ClassifyDocumentTypeJob($documentId),
            new \App\Jobs\ExtractDocumentEntitiesJob($documentId),
            new \App\Jobs\DetectDocumentRisksJob($documentId),
            new \App\Jobs\DetectDocumentDeadlinesJob($documentId),
        ])
            ->name("document-intelligence:{$documentId}")
            ->onQueue('extraction')
            ->allowFailures()
            ->finally(function () use ($documentId) {
                \App\Jobs\GenerateDocumentSummaryJob::dispatch($documentId)
                    ->onQueue('extraction');
            })
            ->dispatch();
    }

    /** Returns extracted OCR text, or null if it already terminated the document's status. */
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
            return null;
        }

        $ocrStage = $recorder->start($document, 'ocr_check');

        try {
            $provider = $resolver->resolve($document);
            // A scanned PDF needs rasterizing into one image per page; a
            // bare JPG/PNG upload already IS the single page image.
            $imagePaths = $document->type === 'PDF'
                ? $rasterizer->toPageImages($absolutePath)
                : [$absolutePath];

            $pages = [];
            foreach ($imagePaths as $index => $imagePath) {
                $result = $provider->extractPage($imagePath, $document);

                OcrResult::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'page_number' => $index + 1,
                    'engine' => $provider->engine(),
                    'raw_text' => $result->text,
                    'confidence' => $result->confidence,
                    'metadata' => $result->metadata,
                ]);

                $pages[] = $result->text;
            }

            $text = implode("\f", $pages);
        } catch (\Throwable $e) {
            $recorder->fail($ocrStage, $e->getMessage());
            $document->forceFill([
                'status' => 'Needs Review',
                'error_message' => 'OCR could not process this scanned document.',
            ])->save();
            return null;
        }

        if (trim($text) === '') {
            $recorder->complete($ocrStage, ['pages_processed' => count($imagePaths)]);
            $document->forceFill([
                'status' => 'Needs Review',
                'error_message' => 'OCR ran but found no readable text — needs manual review.',
            ])->save();
            return null;
        }

        $recorder->complete($ocrStage, ['pages_processed' => count($imagePaths)]);
        return $text;
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