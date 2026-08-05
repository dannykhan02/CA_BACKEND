<?php

namespace App\Services\Ocr;

use App\Models\Document;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

class OcrEngineResolver
{
    public function __construct(
        private Container $container,
        private array $providerMap,
        private string $defaultProviderKey,
    ) {}

    public function resolve(Document $document): OcrProviderInterface
    {
        foreach ($this->orderedKeys($this->preferredProviderKey($document)) as $key) {
            $fqcn = $this->providerMap[$key] ?? null;
            if (! $fqcn) continue;

            $provider = $this->container->make($fqcn);
            if ($provider->supports($document)) {
                return $provider;
            }
        }

        throw new RuntimeException("No OCR provider available for document {$document->id}.");
    }

    private function preferredProviderKey(Document $document): ?string
    {
        return $document->workspace?->aiConfig?->ocr_provider
            ?? $document->workspace?->settings?->ocr_provider
            ?? null;
    }

    private function orderedKeys(?string $preferred): array
    {
        $ordered = array_values(array_unique(array_filter([$preferred, $this->defaultProviderKey])));
        foreach (array_keys($this->providerMap) as $key) {
            if (! in_array($key, $ordered, true)) $ordered[] = $key;
        }
        return array_values(array_intersect($ordered, array_keys($this->providerMap)));
    }
}
