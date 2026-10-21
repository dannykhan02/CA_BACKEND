<?php

namespace App\Services\Ocr;

/**
 * Locates words in an already-oriented image.
 *
 * An interface purely so the suite never depends on a real tesseract binary
 * being installed on the machine running it: tests bind a fake, and
 * ImageTextLocatorRealBinaryTest is the single test that exercises
 * TesseractWordBoxRunner for real and skips when the binary is absent.
 */
interface WordBoxRunner
{
    /**
     * @param  string  $imagePath  An EXIF-oriented image; boxes are measured against it.
     * @param  int  $timeoutSeconds  Hard wall-clock cap for the whole run.
     * @return list<WordBox> In reading order. Empty when nothing legible was found.
     *
     * @throws \RuntimeException on engine failure or timeout.
     */
    public function run(string $imagePath, int $width, int $height, int $timeoutSeconds): array;
}
