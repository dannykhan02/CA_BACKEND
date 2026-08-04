<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Process;

class PdfRasterizer
{
    /** @return string[] absolute paths to one PNG per page, in order */
    public function toPageImages(string $pdfPath): array
    {
        $outputDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($outputDir);
        $prefix = $outputDir . '/page';

        // Requires poppler-utils (`pdftoppm`) on the host — confirm with
        // `which pdftoppm`; install via `sudo apt install poppler-utils`
        // if missing on Wu-Tang.
        $process = new Process(['pdftoppm', '-png', '-r', '200', $pdfPath, $prefix]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('PDF rasterization failed: ' . $process->getErrorOutput());
        }

        $files = glob($prefix . '*.png');
        sort($files, SORT_NATURAL);

        return $files;
    }
}