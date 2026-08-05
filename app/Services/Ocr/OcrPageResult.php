<?php

namespace App\Services\Ocr;

final class OcrPageResult
{
    public function __construct(
        public readonly string $text,
        public readonly float $confidence,
        public readonly array $metadata = [],
    ) {}
}
