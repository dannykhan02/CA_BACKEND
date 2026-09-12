<?php

namespace App\Services\Vision;

use App\Models\Document;

/**
 * Handles documents uploaded directly as JPG/PNG — the whole file IS the
 * image, so there's nothing to detect within a container format. Unlike
 * PdfImageDetector/DocxImageDetector, this always returns exactly one
 * reference (or zero, if the file can't be read) rather than scanning for
 * embedded pictures.
 */
class ImageFileDetector implements EmbeddedVisualDetector
{
    public function supports(Document $document): bool
    {
        return in_array($document->type, ['JPG', 'PNG'], true);
    }

    /** @return VisualReference[] exactly one reference for the whole file */
    public function detect(string $absolutePath): array
    {
        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false) {
            return [];
        }

        $mediaType = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        return [new VisualReference(imageBase64: base64_encode($bytes), mediaType: $mediaType)];
    }
}
