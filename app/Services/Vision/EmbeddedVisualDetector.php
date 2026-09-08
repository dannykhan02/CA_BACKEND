<?php

namespace App\Services\Vision;

use App\Models\Document;

interface EmbeddedVisualDetector
{
    public function supports(Document $document): bool;

    /** @return VisualReference[] */
    public function detect(string $absolutePath): array;
}