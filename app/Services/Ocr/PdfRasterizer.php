<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Process;

class PdfRasterizer
{
    /**
     * @param string $pdfPath
     * @param int[]|null $pageNumbers 1-indexed pages actually needed. Null/empty
     *        rasterizes the whole document (preserves original behavior for any
     *        caller that genuinely needs every page).
     * @return array<int,string> page_number => absolute PNG path. Only the pages
     *         actually rasterized are present (a contiguous range covering
     *         min..max of $pageNumbers when a subset is requested).
     */
    public function toPageImages(string $pdfPath, ?array $pageNumbers = null): array
    {
        $outputDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($outputDir);
        $prefix = $outputDir . '/page';

        $command = ['pdftoppm', '-png', '-r', '200'];

        if ($pageNumbers !== null && ! empty($pageNumbers)) {
            $command[] = '-f';
            $command[] = (string) min($pageNumbers);
            $command[] = '-l';
            $command[] = (string) max($pageNumbers);
        }

        $command[] = $pdfPath;
        $command[] = $prefix;

        // Requires poppler-utils (pdftoppm) on the host. Confirm with
        // "which pdftoppm"; install via "sudo apt install poppler-utils"
        // if missing on Wu-Tang.
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('PDF rasterization failed: ' . $process->getErrorOutput());
        }

        $files = glob($prefix . '*.png');
        sort($files, SORT_NATURAL);

        // pdftoppm names output <prefix>-<actual_page_number>.png even when
        // -f/-l narrows the range. Map by the real page number embedded in
        // the filename rather than array index, so callers can safely look
        // up $result[$pageNumber] regardless of which range was rasterized.
        $byPageNumber = [];
        foreach ($files as $file) {
            if (preg_match('/-(\d+)\.png$/', $file, $m)) {
                $byPageNumber[(int) $m[1]] = $file;
            }
        }

        return $byPageNumber;
    }

    /** Deletes every PNG and the output directory created by a prior toPageImages() call. */
    public function cleanup(array $pageImages): void
    {
        if (empty($pageImages)) {
            return;
        }
        $dir = dirname(reset($pageImages));
        foreach (glob($dir . '/*.png') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
