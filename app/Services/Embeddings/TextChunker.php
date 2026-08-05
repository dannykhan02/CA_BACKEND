<?php

namespace App\Services\Embeddings;

class TextChunker
{
    private const TARGET_CHARS = 1200;
    private const MAX_CHUNKS = 200; // hard cap — bounds cost/storage on pathologically large documents

    /** @return string[] */
    public function chunk(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text)) ?: [];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($current) + mb_strlen($paragraph) > self::TARGET_CHARS && $current !== '') {
                $chunks[] = $current;
                $current = '';
                if (count($chunks) >= self::MAX_CHUNKS) {
                    break;
                }
            }

            $current .= ($current === '' ? '' : "\n\n") . $paragraph;
        }

        if ($current !== '' && count($chunks) < self::MAX_CHUNKS) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
