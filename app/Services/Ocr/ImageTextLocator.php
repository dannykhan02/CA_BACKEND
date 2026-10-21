<?php

namespace App\Services\Ocr;

use App\Models\Document;
use App\Services\Documents\DocumentStorageService;
use App\Services\Intelligence\ImageWordBoxStore;
use Illuminate\Support\Facades\Log;

/**
 * Locates the words in a JPG/PNG source and stores their boxes.
 *
 * Never throws: every failure path returns a result carrying a reason, so the
 * caller cannot accidentally turn a missing binary or a slow page into a
 * failed document. Text extraction is untouched — the document's
 * extracted_text, its analysis and its charging are all exactly what they
 * were before this ran.
 */
class ImageTextLocator
{
    public function __construct(
        private ImageOrienter $orienter,
        private WordBoxRunner $runner,
        private TesseractBinary $binary,
        private ImageWordBoxStore $store,
        private DocumentStorageService $storage,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ocr.image_location.enabled');
    }

    public function supports(Document $document): bool
    {
        return in_array($document->type, ['JPG', 'PNG'], true)
            && is_string($document->file_path) && $document->file_path !== ''
            && trim((string) $document->extracted_text) !== '';
    }

    /**
     * Whether an attempt is worth starting at all.
     *
     * Separate from locate() so a caller can record "this stage did not run"
     * instead of "this stage ran and found nothing" — a container without
     * tesseract should not leave a trail of completed attempts.
     */
    public function engineAvailable(Document $document): bool
    {
        if ($this->binary->available()) {
            return true;
        }
        // The one degradation that is expected rather than exceptional: an image
        // built without tesseract still analyses, just without boxes.
        $this->warn($document, 'tesseract_unavailable', $this->binary->path());

        return false;
    }

    /**
     * @param  bool  $persist  False is a true dry run: OCR runs, nothing is written.
     * @param  int|null  $timeoutSeconds  Overrides the configured per-run cap.
     */
    public function locate(Document $document, bool $persist = true, ?int $timeoutSeconds = null): ImageTextLocationResult
    {
        if (! $this->enabled()) {
            return ImageTextLocationResult::skipped('disabled');
        }
        if (! $this->supports($document)) {
            return ImageTextLocationResult::skipped('unsupported_source');
        }
        if (! $this->engineAvailable($document)) {
            return ImageTextLocationResult::skipped('tesseract_unavailable');
        }

        $source = null;
        $oriented = null;
        try {
            $source = $this->storage->temporaryCopy($document->file_path, 'ocr_locate_');
            $oriented = $this->orienter->orient($source, (int) config('ocr.image_location.max_dimension'));
            $words = $this->runner->run($oriented->path, $oriented->width, $oriented->height,
                $timeoutSeconds ?? (int) config('ocr.image_location.timeout_seconds'));
            $threshold = max(0, (int) config('ocr.image_location.min_word_confidence')) / 100;
            $confident = array_values(array_filter($words,
                static fn (WordBox $word) => $word->confidence >= $threshold));
            $dropped = count($words) - count($confident);

            if ($confident === []) {
                return new ImageTextLocationResult(located: false, wordsStored: 0,
                    wordsDropped: $dropped, width: $oriented->width, height: $oriented->height,
                    exifOrientation: $oriented->exifOrientation, downscaled: $oriented->downscaled,
                    reason: 'no_confident_words');
            }
            if ($persist) {
                $this->store->store($document, $oriented, $confident);
            }

            return new ImageTextLocationResult(located: true, wordsStored: count($confident),
                wordsDropped: $dropped, width: $oriented->width, height: $oriented->height,
                exifOrientation: $oriented->exifOrientation, downscaled: $oriented->downscaled,
                persisted: $persist);
        } catch (\Throwable $e) {
            $this->warn($document, 'ocr_failed', $e->getMessage());

            return ImageTextLocationResult::skipped('ocr_failed');
        } finally {
            if ($oriented !== null) {
                @unlink($oriented->path);
            }
            if ($source !== null) {
                @unlink($source);
            }
        }
    }

    private function warn(Document $document, string $reason, string $detail): void
    {
        Log::warning('Image word-box location unavailable', [
            'document_id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'reason' => $reason,
            // Truncated: a provider message must never fill the log with page text.
            'detail' => substr($detail, 0, 300),
        ]);
    }
}
