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
        $pdf = (new PdfParser)->parseFile($absolutePath);
        $refs = [];

        foreach ($pdf->getPages() as $i => $page) {
            $hasImage = false;
            foreach ($page->getXObjects() as $xobject) {
                if ($xobject instanceof PdfImageXObject) {
                    $hasImage = true;
                    break;
                }
            }
            if ($hasImage && preg_match('/\b(chart|figure|table|diagram|map|exhibit|graph)\b/i', $page->getText())) {
                $refs[] = ['ref' => new VisualReference(pageNumber: $i + 1),
                    'score' => preg_match_all('/\b(chart|figure|table|diagram|map|exhibit|graph)\b/i', $page->getText())];
            }
        }

        usort($refs, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_column($refs, 'ref');
    }
}
