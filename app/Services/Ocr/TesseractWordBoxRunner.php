<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs tesseract for geometry only.
 *
 * This is deliberately not the text-extraction path: extraction stays on
 * claude_vision (see docs/IMAGE_TEXT_LOCATION.md). Only the boxes are taken
 * from here, so a weaker reading than Claude's costs accuracy of the
 * highlight and nothing else — the matcher tolerates per-word differences
 * between the two readings.
 */
class TesseractWordBoxRunner implements WordBoxRunner
{
    public function __construct(private TesseractBinary $binary) {}

    public function run(string $imagePath, int $width, int $height, int $timeoutSeconds): array
    {
        if (! $this->binary->available()) {
            throw new \RuntimeException('The tesseract binary is not available.');
        }
        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('Oriented image dimensions are unusable.');
        }
        $outputBase = tempnam(sys_get_temp_dir(), 'ocr_tsv_');
        if ($outputBase === false) {
            throw new \RuntimeException('Word-box output could not be staged.');
        }
        // tesseract appends its own extension, so the placeholder tempnam()
        // created is not the file that gets written. Both are cleaned up.
        $tsvPath = $outputBase.'.tsv';

        try {
            $process = new Process([
                $this->binary->path(), $imagePath, $outputBase,
                '-l', (string) config('ocr.image_location.languages'),
                '--psm', (string) config('ocr.image_location.page_segmentation_mode'),
                'tsv',
            ]);
            $process->setTimeout(max(1, $timeoutSeconds));
            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                throw new \RuntimeException('Word-box OCR timed out after '.$timeoutSeconds.'s.', 0, $e);
            }
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Word-box OCR failed: '
                    .trim(substr($process->getErrorOutput(), 0, 500)));
            }
            $tsv = @file_get_contents($tsvPath);
            if ($tsv === false) {
                throw new \RuntimeException('Word-box OCR produced no output.');
            }

            return $this->parse($tsv, $width, $height);
        } finally {
            @unlink($tsvPath);
            @unlink($outputBase);
        }
    }

    /**
     * TSV columns: level, page_num, block_num, par_num, line_num, word_num,
     * left, top, width, height, conf, text.
     *
     * @return list<WordBox>
     */
    private function parse(string $tsv, int $width, int $height): array
    {
        $maxWords = max(1, (int) config('ocr.image_location.max_words'));
        $words = [];
        $lines = explode("\n", trim($tsv));
        array_shift($lines); // header
        foreach ($lines as $line) {
            if (count($words) >= $maxWords) {
                break;
            }
            $cols = explode("\t", $line, 12);
            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue; // level 5 is a word; the rest are page/block/line summaries.
            }
            $text = trim($cols[11]);
            $confidence = (float) $cols[10];
            if ($text === '' || $confidence < 0) {
                continue;
            }
            [$left, $top, $boxWidth, $boxHeight] = array_map('intval', array_slice($cols, 6, 4));
            // A box that is not wholly inside the measured image is not a box we
            // can normalise honestly, so it is dropped rather than clamped.
            if ($boxWidth < 1 || $boxHeight < 1 || $left < 0 || $top < 0
                || $left + $boxWidth > $width || $top + $boxHeight > $height) {
                continue;
            }
            $words[] = new WordBox(
                text: $text,
                left: $left,
                top: $top,
                width: $boxWidth,
                height: $boxHeight,
                confidence: round($confidence / 100, 4),
                line: $cols[2].':'.$cols[3].':'.$cols[4],
            );
        }

        return $words;
    }
}
