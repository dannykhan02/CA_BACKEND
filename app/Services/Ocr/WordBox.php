<?php

namespace App\Services\Ocr;

/** One OCR word with its pixel box in the oriented image's coordinate space. */
final class WordBox
{
    public function __construct(
        public readonly string $text,
        public readonly int $left,
        public readonly int $top,
        public readonly int $width,
        public readonly int $height,
        public readonly float $confidence,
        public readonly string $line,
    ) {}
}
