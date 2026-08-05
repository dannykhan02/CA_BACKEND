<?php

namespace App\Services\Ocr;

use App\Models\Document;
use App\Services\AnthropicClient;

class ClaudeVisionOcrProvider implements OcrProviderInterface
{
    public function __construct(private AnthropicClient $client) {}

    public function engine(): string
    {
        return 'vision';
    }

    public function supports(Document $document): bool
    {
        return $document->workspace?->aiConfig?->vision_enabled ?? true;
    }

    public function extractPage(string $imagePath, Document $document): OcrPageResult
    {
        $mediaType = match (strtolower(pathinfo($imagePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png', // pdftoppm -png always produces this
        };

        $result = $this->client->extractTextFromImage(
            base64_encode(file_get_contents($imagePath)),
            $mediaType,
            $document,
        );

        return new OcrPageResult(
            text: $result['text'],
            confidence: $result['confidence'],
            metadata: ['source' => 'claude_vision'],
        );
    }
}