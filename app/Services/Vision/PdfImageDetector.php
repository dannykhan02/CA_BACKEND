<?php

namespace App\Services\Vision;

use App\Models\Document;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\XObject\Image as PdfImageXObject;

class PdfImageDetector implements EmbeddedVisualDetector
{
    public function supports(Document $document): bool
    {
        return $document->type === 'PDF';
    }

    /** @return VisualReference[] one per page containing at least one image XObject */
    public function detect(string $absolutePath): array
    {
        $pdf = (new PdfParser())->parseFile($absolutePath);
        $refs = [];

        foreach ($pdf->getPages() as $i => $page) {
            $hasImage = false;
            foreach ($page->getXObjects() as $xobject) {
                if ($xobject instanceof PdfImageXObject) {
                    $hasImage = true;
                    break;
                }
            }
            if ($hasImage) {
                $refs[] = new VisualReference(pageNumber: $i + 1);
            }
        }

        return $refs;
    }
}