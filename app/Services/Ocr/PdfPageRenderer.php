<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders one PDF page to a PNG for word-box location.
 *
 * Separate from PdfRasterizer on purpose: that class renders a page range at a fixed
 * resolution for text extraction and runs the extraction preflight. This one renders
 * exactly one page at the configured location resolution under its own timeout, and
 * leaves a single file for the caller to delete.
 */
class PdfPageRenderer
{
    /**
     * @return string Absolute path of the PNG. The caller must unlink it.
     *
     * @throws \RuntimeException when poppler fails, times out or writes nothing.
     */
    public function render(string $pdfPath, int $page, int $dpi, int $timeoutSeconds): string
    {
        if ($page < 1 || $dpi < 50) {
            throw new \RuntimeException('Page render parameters are unusable.');
        }
        $base = tempnam(sys_get_temp_dir(), 'pdf_locate_');
        if ($base === false) {
            throw new \RuntimeException('Page render output could not be staged.');
        }
        // -singlefile makes pdftoppm write exactly "<base>.png", whatever the page number.
        $png = $base.'.png';

        try {
            $process = new Process(['pdftoppm', '-png', '-r', (string) $dpi, '-f', (string) $page,
                '-l', (string) $page, '-singlefile', $pdfPath, $base]);
            $process->setTimeout(max(1, $timeoutSeconds));
            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                throw new \RuntimeException('Page render timed out after '.$timeoutSeconds.'s.', 0, $e);
            }
            if (! $process->isSuccessful() || ! is_file($png)) {
                throw new \RuntimeException('Page render failed: '.trim(substr($process->getErrorOutput(), 0, 300)));
            }

            return $png;
        } catch (\Throwable $e) {
            @unlink($png);
            throw $e;
        } finally {
            @unlink($base);
        }
    }
}
