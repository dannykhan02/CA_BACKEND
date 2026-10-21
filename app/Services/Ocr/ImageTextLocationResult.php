<?php

namespace App\Services\Ocr;

/** What one location attempt found, whether or not it was persisted. */
final class ImageTextLocationResult
{
    public function __construct(
        public readonly bool $located,
        public readonly int $wordsStored,
        public readonly int $wordsDropped,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly int $exifOrientation = 1,
        public readonly bool $downscaled = false,
        public readonly bool $persisted = false,
        public readonly ?string $reason = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(located: false, wordsStored: 0, wordsDropped: 0, reason: $reason);
    }

    /** @return array<string,mixed> Safe to store in processing_jobs.output. */
    public function toArray(): array
    {
        return ['located' => $this->located, 'words_stored' => $this->wordsStored,
            'words_dropped' => $this->wordsDropped, 'image_width' => $this->width,
            'image_height' => $this->height, 'exif_orientation' => $this->exifOrientation,
            'downscaled' => $this->downscaled, 'persisted' => $this->persisted,
            'reason' => $this->reason];
    }
}
