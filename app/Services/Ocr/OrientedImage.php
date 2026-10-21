<?php

namespace App\Services\Ocr;

/** An EXIF-normalised copy of an image, with the dimensions boxes must be measured against. */
final class OrientedImage
{
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly int $exifOrientation,
        public readonly bool $downscaled,
    ) {}
}
