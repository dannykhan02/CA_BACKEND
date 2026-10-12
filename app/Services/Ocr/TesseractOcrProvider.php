<?php

namespace App\Services\Ocr;

use App\Models\Document;
use Symfony\Component\Process\Process;

class TesseractOcrProvider implements OcrProviderInterface
{
    public function engine(): string
    {
        return 'printed';
    }

    public function supports(Document $document): bool
    {
        return true;
    }

    public function extractPage(string $imagePath, Document $document): OcrPageResult
    {
        $binary = config('ocr.tesseract_binary');
        $outputBase = sys_get_temp_dir().'/tess_'.uniqid();

        $process = new Process([$binary, $imagePath, $outputBase, 'tsv']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Tesseract OCR failed: '.$process->getErrorOutput());
        }

        $tsv = file_get_contents($outputBase.'.tsv');
        @unlink($outputBase.'.tsv');
        $size = @getimagesize($imagePath);

        return $this->parseTsv($tsv === false ? '' : $tsv,
            is_array($size) ? (int) $size[0] : null,
            is_array($size) ? (int) $size[1] : null);
    }

    private function parseTsv(string $tsv, ?int $imageWidth = null, ?int $imageHeight = null): OcrPageResult
    {
        $lines = array_filter(explode("\n", trim($tsv)));
        array_shift($lines);
        $words = [];
        $confidences = [];
        $regions = [];
        $cursor = 0;
        $geometryIncomplete = false;
        foreach ($lines as $line) {
            $cols = explode("\t", $line, 12);
            if (count($cols) < 12) {
                continue;
            }
            $conf = (float) $cols[10];
            $text = trim($cols[11]);
            if ($text === '' || $conf < 0) {
                continue;
            }
            $start = $cursor + ($words === [] ? 0 : 1);
            $end = $start + mb_strlen($text);
            $words[] = $text;
            $confidences[] = $conf;
            $cursor = $end;
            $box = array_slice($cols, 6, 4);
            $validBox = $imageWidth > 0 && $imageHeight > 0
                && count($box) === 4 && count(array_filter($box,
                    static fn ($value) => preg_match('/^\d+$/D', $value) === 1)) === 4
                && (int) $box[2] > 0 && (int) $box[3] > 0
                && (int) $box[0] + (int) $box[2] <= $imageWidth
                && (int) $box[1] + (int) $box[3] <= $imageHeight;
            if (count($regions) < 5000 && $validBox) {
                $regions[] = ['start' => $start, 'end' => $end,
                    'left' => (int) $box[0], 'top' => (int) $box[1],
                    'width' => (int) $box[2], 'height' => (int) $box[3],
                    'block' => (int) $cols[2], 'paragraph' => (int) $cols[3],
                    'line' => (int) $cols[4], 'confidence' => round($conf / 100, 4)];
            } else {
                $geometryIncomplete = true;
            }
        }
        $avg = $confidences ? (array_sum($confidences) / count($confidences)) / 100 : 0.0;

        return new OcrPageResult(implode(' ', $words), round($avg, 2), [
            'source' => 'tesseract', 'word_count' => count($words),
            'reading_order' => 'tesseract_layout_unverified',
            'rotation' => 'unavailable', 'tables' => 'unavailable',
            'geometry' => $regions === [] ? 'unavailable' : ($geometryIncomplete ? 'partial' : 'word_boxes'),
            'coordinate_system' => $regions === [] ? null : 'image_pixels',
            'image_width' => $imageWidth, 'image_height' => $imageHeight,
            'regions' => $regions,
        ]);
    }
}
