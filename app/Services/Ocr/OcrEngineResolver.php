<?php

namespace App\Services\Ocr;

use App\Models\Document;
use RuntimeException;

class OcrEngineResolver
{
    /** @param OcrProviderInterface[] $providers checked in order */
    public function __construct(private array $providers) {}

    public function resolve(Document $document): OcrProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($document)) {
                return $provider;
            }
        }

        throw new RuntimeException("No OCR provider available for document {$document->id}.");
    }
}