<?php

namespace App\Services\Ocr;

use App\Models\Document;

interface OcrProviderInterface
{
    /** Must match ocr_results.engine: 'printed' | 'handwriting' | 'vision' */
    public function engine(): string;

    public function supports(Document $document): bool;

    public function extractPage(string $imagePath, Document $document): OcrPageResult;
}