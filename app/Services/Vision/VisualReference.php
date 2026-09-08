<?php

namespace App\Services\Vision;

/**
 * Uniform handle for "something worth sending to vision", regardless of
 * whether it came from a rasterized PDF page or a DOCX-embedded image.
 * Exactly one of pageNumber/imageBase64 is meaningful per source type —
 * AnalyzeEmbeddedVisualsJob branches on which is set.
 */
class VisualReference
{
    public function __construct(
        public readonly ?int $pageNumber = null,      // PDF: page to rasterize
        public readonly ?string $imageBase64 = null,   // DOCX: bytes already in hand
        public readonly string $mediaType = 'image/png',
    ) {}
}