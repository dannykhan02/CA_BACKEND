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
        $limit = config('document_processing.image_max_pixels');
        $size = @getimagesize($imagePath);
        if (! is_int($limit) || $limit < 1 || ! is_array($size) || $size[0] < 1 || $size[1] < 1
            || $limit < $size[0] * $size[1]) {
            throw new \RuntimeException('Image dimensions are invalid or exceed the processing limit.');
        }
        // The bytes decide, not the name: the batch job hands over an extensionless temp copy of the stored file.
        $mediaType = match ($size['mime'] ?? null) {
            'image/jpeg', 'image/png', 'image/gif', 'image/webp' => $size['mime'],
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
