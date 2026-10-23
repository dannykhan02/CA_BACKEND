<?php

namespace App\Services\Ocr;

/** What one PDF location attempt found, whether or not it was persisted. */
final class PdfTextLocationResult
{
    /**
     * @param  list<int>  $locatedPages
     */
    public function __construct(
        public readonly bool $located,
        public readonly array $locatedPages = [],
        public readonly int $pagesAttempted = 0,
        public readonly int $pagesSkipped = 0,
        public readonly int $pagesFailed = 0,
        public readonly int $wordsStored = 0,
        public readonly bool $persisted = false,
        public readonly ?string $reason = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(located: false, reason: $reason);
    }

    /** @return array<string,mixed> Safe to store in processing_jobs.output. */
    public function toArray(): array
    {
        return ['located' => $this->located, 'located_pages' => $this->locatedPages,
            'pages_attempted' => $this->pagesAttempted, 'pages_skipped' => $this->pagesSkipped,
            'pages_failed' => $this->pagesFailed, 'words_stored' => $this->wordsStored,
            'persisted' => $this->persisted, 'reason' => $this->reason];
    }
}
