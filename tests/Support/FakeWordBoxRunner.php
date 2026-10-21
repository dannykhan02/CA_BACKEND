<?php

namespace Tests\Support;

use App\Services\Ocr\WordBox;
use App\Services\Ocr\WordBoxRunner;

/**
 * Keeps the suite independent of a real tesseract binary.
 *
 * The one test that does exercise the real engine is
 * ImageTextLocatorRealBinaryTest, which skips itself when tesseract is not
 * installed. Everything else binds this.
 */
class FakeWordBoxRunner implements WordBoxRunner
{
    public int $calls = 0;

    public ?int $lastWidth = null;

    public ?int $lastHeight = null;

    public ?int $lastTimeout = null;

    /** @param  list<WordBox>  $words */
    public function __construct(
        private array $words = [],
        private ?\Throwable $failure = null,
    ) {}

    /** Words laid out left to right on one line, 20px tall at y=10. */
    public static function line(array $texts, float $confidence = 0.9, int $line = 1): array
    {
        $words = [];
        $left = 10;
        foreach ($texts as $text) {
            $width = 10 * max(1, mb_strlen($text));
            $words[] = new WordBox($text, $left, 10, $width, 20, $confidence, '1:1:'.$line);
            $left += $width + 5;
        }

        return $words;
    }

    public static function failing(\Throwable $failure): self
    {
        return new self([], $failure);
    }

    public function run(string $imagePath, int $width, int $height, int $timeoutSeconds): array
    {
        $this->calls++;
        $this->lastWidth = $width;
        $this->lastHeight = $height;
        $this->lastTimeout = $timeoutSeconds;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->words;
    }
}
