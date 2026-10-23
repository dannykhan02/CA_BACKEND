<?php

namespace App\Services\Ocr;

use App\Models\Document;
use App\Services\Documents\DocumentStorageService;
use App\Services\Intelligence\ImageWordBoxStore;
use Illuminate\Support\Facades\Log;

/**
 * Locates the words on the scanned pages of a PDF and stores their boxes.
 *
 * The PDF counterpart of ImageTextLocator, with the same guarantees: it never
 * throws, it never touches extracted_text, analysis or credits, and every
 * failure becomes a result carrying a reason. Only pages that already went
 * through OCR are passed in, so a page with a text layer is never rendered and
 * keeps the highlighting it has today.
 */
class PdfTextLocator
{
    public function __construct(
        private PdfPageRenderer $renderer,
        private WordBoxRunner $runner,
        private TesseractBinary $binary,
        private ImageWordBoxStore $store,
        private DocumentStorageService $storage,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ocr.pdf_location.enabled');
    }

    public function supports(Document $document): bool
    {
        return $document->type === 'PDF'
            && is_string($document->file_path) && $document->file_path !== ''
            && trim((string) $document->extracted_text) !== '';
    }

    public function engineAvailable(Document $document): bool
    {
        if ($this->binary->available()) {
            return true;
        }
        $this->warn($document, 'tesseract_unavailable', $this->binary->path());

        return false;
    }

    /**
     * @param  list<int>  $pages  1-based pages that were read by OCR (no usable text layer).
     * @param  bool  $persist  False is a true dry run: OCR runs, nothing is written.
     */
    public function locate(Document $document, array $pages, bool $persist = true): PdfTextLocationResult
    {
        if (! $this->enabled()) {
            return PdfTextLocationResult::skipped('disabled');
        }
        if (! $this->supports($document)) {
            return PdfTextLocationResult::skipped('unsupported_source');
        }
        $pages = array_values(array_unique(array_filter(array_map('intval', $pages), static fn (int $p) => $p > 0)));
        sort($pages);
        if ($pages === []) {
            return PdfTextLocationResult::skipped('no_ocr_pages');
        }
        if (! $this->engineAvailable($document)) {
            return PdfTextLocationResult::skipped('tesseract_unavailable');
        }

        $selected = array_slice($pages, 0, max(1, (int) config('ocr.pdf_location.max_pages')));
        $skipped = count($pages) - count($selected);
        $dpi = (int) config('ocr.pdf_location.dpi');
        $pageTimeout = max(1, (int) config('ocr.pdf_location.page_timeout_seconds'));
        $deadline = microtime(true) + max(1, (int) config('ocr.pdf_location.total_budget_seconds'));
        $threshold = max(0, (int) config('ocr.image_location.min_word_confidence')) / 100;
        $pixelLimit = config('document_processing.image_max_pixels');

        $source = null;
        $attempted = 0;
        $failed = 0;
        $located = [];
        try {
            $source = $this->storage->temporaryCopy($document->file_path, 'pdf_locate_src_');
            foreach ($selected as $page) {
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 5) {
                    $skipped += count($selected) - $attempted; // Out of time: later pages get no highlight.
                    break;
                }
                $attempted++;
                $png = null;
                try {
                    $png = $this->renderer->render($source, $page, $dpi, min($pageTimeout, $remaining));
                    $size = @getimagesize($png);
                    if (! is_array($size) || $size[0] < 1 || $size[1] < 1
                        || (is_int($pixelLimit) && $pixelLimit >= 1 && $pixelLimit < $size[0] * $size[1])) {
                        throw new \RuntimeException('Rendered page dimensions are unusable.');
                    }
                    $words = $this->runner->run($png, (int) $size[0], (int) $size[1],
                        min($pageTimeout, max(1, (int) floor($deadline - microtime(true)))));
                    $confident = array_values(array_filter($words,
                        static fn (WordBox $word) => $word->confidence >= $threshold));
                    if ($confident !== []) {
                        $located[] = ['page' => $page, 'width' => (int) $size[0], 'height' => (int) $size[1], 'words' => $confident];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn($document, 'page_failed', 'page '.$page.': '.$e->getMessage());
                } finally {
                    if ($png !== null) {
                        @unlink($png);
                    }
                }
            }

            if ($located === []) {
                return new PdfTextLocationResult(located: false, pagesAttempted: $attempted,
                    pagesSkipped: $skipped, pagesFailed: $failed,
                    reason: $failed === $attempted && $attempted > 0 ? 'ocr_failed' : 'no_confident_words');
            }
            if ($persist) {
                $this->store->storePages($document, $located);
            }

            return new PdfTextLocationResult(located: true, locatedPages: array_column($located, 'page'),
                pagesAttempted: $attempted, pagesSkipped: $skipped, pagesFailed: $failed,
                wordsStored: array_sum(array_map(static fn (array $p) => count($p['words']), $located)),
                persisted: $persist);
        } catch (\Throwable $e) {
            $this->warn($document, 'ocr_failed', $e->getMessage());

            return PdfTextLocationResult::skipped('ocr_failed');
        } finally {
            if ($source !== null) {
                @unlink($source);
            }
        }
    }

    private function warn(Document $document, string $reason, string $detail): void
    {
        Log::warning('PDF word-box location unavailable', [
            'document_id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'reason' => $reason,
            'detail' => substr($detail, 0, 300),
        ]);
    }
}
