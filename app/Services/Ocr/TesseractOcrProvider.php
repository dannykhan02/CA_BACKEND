<?php

namespace App\Services\Ocr;

use App\Models\Document;
use Symfony\Component\Process\Process;

class TesseractOcrProvider implements OcrProviderInterface
{
    public function engine(): string { return 'printed'; }

    public function supports(Document $document): bool { return true; }

    public function extractPage(string $imagePath, Document $document): OcrPageResult
    {
        $binary = config('ocr.tesseract_binary');
        $outputBase = sys_get_temp_dir() . '/tess_' . uniqid();

        $process = new Process([$binary, $imagePath, $outputBase, 'tsv']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Tesseract OCR failed: ' . $process->getErrorOutput());
        }

        $tsv = file_get_contents($outputBase . '.tsv');
        @unlink($outputBase . '.tsv');
        return $this->parseTsv($tsv);
    }

    private function parseTsv(string $tsv): OcrPageResult
    {
        $lines = array_filter(explode("\n", trim($tsv)));
        array_shift($lines);
        $words = []; $confidences = [];
        foreach ($lines as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 12) continue;
            $conf = (float) $cols[10];
            $text = trim($cols[11]);
            if ($text === '' || $conf < 0) continue;
            $words[] = $text; $confidences[] = $conf;
        }
        $avg = $confidences ? (array_sum($confidences) / count($confidences)) / 100 : 0.0;
        return new OcrPageResult(implode(' ', $words), round($avg, 2), ['source' => 'tesseract', 'word_count' => count($words)]);
    }
}
