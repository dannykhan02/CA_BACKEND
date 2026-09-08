<?php

namespace App\Services\Vision;

use App\Models\Document;
use PhpOffice\PhpWord\Element\Image as WordImage;
use PhpOffice\PhpWord\IOFactory;

class DocxImageDetector implements EmbeddedVisualDetector
{
    public function supports(Document $document): bool
    {
        return $document->type === 'DOCX';
    }

    /** @return VisualReference[] one per embedded image, bytes already in hand — no rasterization needed */
    public function detect(string $absolutePath): array
    {
        $phpWord = IOFactory::load($absolutePath);
        $refs = [];

        foreach ($phpWord->getSections() as $section) {
            $this->walk($section->getElements(), $refs);
        }

        return $refs;
    }

    private function walk(array $elements, array &$refs): void
    {
        foreach ($elements as $element) {
            if ($element instanceof WordImage) {
                $refs[] = new VisualReference(
                    imageBase64: base64_encode($element->getImageStringData(true)), // true = actual binary, then we b64 it ourselves
                    mediaType: 'image/' . ($element->getImageExtension() ?: 'png'),
                );
                continue;
            }
            if (method_exists($element, 'getElements')) {
                $this->walk($element->getElements(), $refs);
            }
        }
    }
}