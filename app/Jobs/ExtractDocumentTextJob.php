<?php

namespace App\Jobs;

use App\Enums\WorkspaceType;
use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use App\Services\Documents\DocumentStorageService;
use App\Services\DocumentTextExtractor;
use App\Services\EntitlementService;
use App\Services\Extraction\SpreadsheetTextExtractor;
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
    use Dispatchable,
        DispatchesIntelligenceChain,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public string $documentId
    ) {}

    public function handle(
        DocumentTextExtractor $extractor,
        PipelineStageRecorder $recorder,
        OcrEngineResolver $ocrResolver,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find(
            $this->documentId
        );

        if ($document) {
            app(EntitlementService::class)
                ->reserveDocument($document);
        }

        if (
            ! $document
            || $document->status === 'Failed'
        ) {
            if (! $document) {
                Log::warning(
                    "ExtractDocumentTextJob: Document {$this->documentId} not found — unexpected null, possible soft-delete race."
                );
            }

            return;
        }

        $extractStage = $recorder->start(
            $document,
            'extract'
        );

        $document->forceFill([
            'progress' => 25,
        ])->save();

        /*
         * The 'documents' disk may be a remote driver
         * (e.g. S3/R2).
         *
         * Text extractors and the PDF rasterizer need a real local
         * filesystem path, so the remote file is pulled to a local
         * temp copy first.
         *
         * $cleanupAbsolutePath tracks whether this job invocation still
         * owns that temp file.
         */
        $absolutePath = app(
            DocumentStorageService::class
        )->temporaryCopy(
            $document->file_path,
            'extract_'
        );

        $cleanupAbsolutePath = true;

        try {
            $text = match ($document->type) {
                'PDF' =>
                    $extractor->extractPdfText(
                        $absolutePath
                    ),

                'DOCX' =>
                    $extractor->extractDocxText(
                        $absolutePath
                    ),

                'XLSX' =>
                    app(
                        SpreadsheetTextExtractor::class
                    )->extract(
                        $absolutePath
                    ),

                /*
                 * No native text layer. This deliberately forces the
                 * OCR fallback below.
                 */
                'JPG', 'PNG' => '',

                default =>
                    throw new \RuntimeException(
                        "Unsupported document type: {$document->type}"
                    ),
            };

            /*
             * Extraction libraries can return malformed UTF-8 bytes,
             * especially from PDF text layers.
             *
             * Clean the text before it can ever reach PostgreSQL,
             * caching, token counting or downstream AI jobs.
             */
            $text = $extractor->sanitizeUtf8(
                $text
            );

            /*
             * Page count only has a reliable, well-defined answer
             * for PDF.
             *
             * DOCX reflows dynamically with no fixed page count
             * until rendered, and XLSX has sheets rather than pages.
             */
            if ($document->type === 'PDF') {
                $document->forceFill([
                    'pages' =>
                        $extractor->countPdfPages(
                            $absolutePath
                        ),
                ])->save();
            }
        } catch (\Throwable $e) {
            @unlink($absolutePath);

            $recorder->fail(
                $extractStage,
                $e->getMessage()
            );

            $document->forceFill([
                'status' => 'Failed',
                'error_message' =>
                    'Could not extract text: the file may be corrupted or password-protected.',
            ])->save();

            $this->fail($e);

            return;
        }

        if (trim($text) === '') {
            $recorder->complete(
                $extractStage,
                [
                    'native_text_found' => false,
                ]
            );

            $text = $this->fallbackToOcr(
                $document,
                $ocrResolver,
                $rasterizer,
                $recorder,
                $absolutePath
            );

            if ($text === null) {
                /*
                 * fallbackToOcr either set a terminal status itself
                 * or queued OcrPageBatchJob jobs.
                 */
                return;
            }

            /*
             * Keep this safeguard even though the current async OCR
             * path normally returns null here.
             */
            $text = $extractor->sanitizeUtf8(
                $text
            );
        } else {
            $recorder->complete(
                $extractStage,
                [
                    'native_text_found' => true,
                ]
            );
        }

        if ($cleanupAbsolutePath) {
            @unlink($absolutePath);
        }

        /*
         * Persistent source of truth.
         *
         * Text has already been sanitized above, so PostgreSQL does not
         * receive malformed byte sequences from extraction libraries.
         */
        $document->forceFill([
            'extracted_text' => $text,
        ])->save();

        /*
         * Cache retained as a short-lived read-through optimisation only.
         * Nothing downstream should treat it as authoritative.
         */
        Cache::put(
            "document:{$document->id}:extracted_text",
            $text,
            now()->addHours(2)
        );

        $document->forceFill([
            'progress' => 50,
        ])->save();

        $this->dispatchIntelligenceChain(
            $document
        );
    }

    /**
     * Returns null in every case:
     *
     * Either a terminal document status was already set
     * (no OCR provider available, OCR disabled, rasterization failure),
     * or OcrPageBatchJob batches were successfully appended to this
     * job's chain.
     *
     * The final OCR batch sets extracted_text, updates progress and
     * continues the intelligence chain.
     */
    private function fallbackToOcr(
        Document $document,
        OcrEngineResolver $resolver,
        PdfRasterizer $rasterizer,
        PipelineStageRecorder $recorder,
        string $absolutePath,
    ): ?string {
        $ocrEnabled =
            $document->workspace
                ?->settings
                ?->ocr_enabled
            ?? false;

        $ocrEligibleTypes = [
            'PDF',
            'JPG',
            'PNG',
        ];

        $isOcrEligibleType = in_array(
            $document->type,
            $ocrEligibleTypes,
            true
        );

        if (
            ! $isOcrEligibleType
            || ! $ocrEnabled
        ) {
            $recorder->skip(
                $document,
                'ocr_check',
                $ocrEnabled
                    ? 'document type not eligible for OCR'
                    : 'ocr disabled for workspace'
            );

            $document->forceFill([
                'status' => 'Failed',

                'error_message' =>
                    $isOcrEligibleType
                        ? 'No extractable text found and OCR is disabled for this workspace.'
                        : 'No extractable text found in this document.',
            ])->save();

            $this->fail(
                new \RuntimeException(
                    'Empty extracted text, OCR unavailable.'
                )
            );

            @unlink($absolutePath);

            return null;
        }

        /*
         * Confirm a provider is configured before creating OCR jobs.
         */
        try {
            $resolver->resolve($document);
        } catch (\Throwable $e) {
            @unlink($absolutePath);

            $recorder->fail(
                $recorder->start(
                    $document,
                    'ocr_check'
                ),
                $e->getMessage()
            );

            $document->forceFill([
                'status' =>
                    $document->workspace?->type
                        === WorkspaceType::Personal
                            ? 'Failed'
                            : 'Needs Review',

                'error_message' =>
                    'OCR could not process this scanned document.',
            ])->save();

            return null;
        }

        try {
            /*
             * PDFs must be rasterized into page images.
             *
             * JPG/PNG uploads are already single-page images.
             */
            $imagePaths =
                $document->type === 'PDF'
                    ? $rasterizer->toPageImages(
                        $absolutePath
                    )
                    : [$absolutePath];

            @unlink($absolutePath);
        } catch (\Throwable $e) {
            @unlink($absolutePath);

            $recorder->fail(
                $recorder->start(
                    $document,
                    'ocr_check'
                ),
                $e->getMessage()
            );

            $document->forceFill([
                'status' =>
                    $document->workspace?->type
                        === WorkspaceType::Personal
                            ? 'Failed'
                            : 'Needs Review',

                'error_message' =>
                    'OCR could not process this scanned document.',
            ])->save();

            return null;
        }

        /*
         * Three pages per OCR batch.
         *
         * This avoids running page-count-dependent OCR loops inside the
         * original extraction job's 120-second timeout.
         */
        $storedPageImages =
            $document->type === 'PDF';

        if ($storedPageImages) {
            $localImages = $imagePaths;

            try {
                foreach (
                    $localImages as
                    $page => $localPath
                ) {
                    $key =
                        'ai-ocr/'
                        .$document->workspace_id
                        .'/'
                        .$document->id
                        .'/'
                        .$document->file_hash
                        .'/page-'
                        .$page
                        .'.png';

                    $stream = fopen(
                        $localPath,
                        'rb'
                    );

                    try {
                        Storage::disk(
                            'documents'
                        )->put(
                            $key,
                            $stream
                        );
                    } finally {
                        if (
                            is_resource(
                                $stream
                            )
                        ) {
                            fclose(
                                $stream
                            );
                        }
                    }

                    $imagePaths[$page] =
                        $key;
                }
            } finally {
                $rasterizer->cleanup(
                    $localImages
                );
            }
        }

        $batches = array_chunk(
            $imagePaths,
            3
        );

        /*
         * Only PDF rasterization produces disposable page image
         * directories.
         *
         * Page assets now survive worker replacement because they are
         * persisted on the documents disk.
         */
        $tempDir = null;

        /*
         * JPG and PNG uploads are re-fetched by OcrPageBatchJob from
         * document storage instead of relying on a local temp path.
         */
        $fetchFromSourceDisk =
            $document->type !== 'PDF';

        $startingPage = 1;

        $batchJobs = [];

        foreach (
            $batches as
            $index => $batchPaths
        ) {
            $isLastBatch =
                $index
                === array_key_last(
                    $batches
                );

            $batchJobs[] =
                (new OcrPageBatchJob(
                    documentId:
                        $document->id,

                    pageImagePaths:
                        $batchPaths,

                    startingPageNumber:
                        $startingPage,

                    isLastBatch:
                        $isLastBatch,

                    tempDir:
                        $isLastBatch
                            ? $tempDir
                            : null,

                    fetchPageFromSourceDisk:
                        $fetchFromSourceDisk,

                    storedPageImages:
                        $storedPageImages,
                ))->onQueue(
                    'extraction'
                );

            $startingPage +=
                count(
                    $batchPaths
                );
        }

        $this->prependToChain(
            $batchJobs
        );

        return null;
    }

    public function failed(
        \Throwable $e
    ): void {
        Log::error(
            'ExtractDocumentTextJob failed',
            [
                'document_id' =>
                    $this->documentId,

                'exception' =>
                    get_class($e),

                'message' =>
                    $e->getMessage(),
            ]
        );

        $document = Document::find(
            $this->documentId
        );

        if (
            ! $document
            || $document->status === 'Failed'
        ) {
            return;
        }

        $document->forceFill([
            'status' => 'Failed',

            'error_message' =>
                'Document processing could not be completed. Please try again.',
        ])->save();
    }
}