<?php

namespace Tests\Support;

use App\Services\Ocr\PdfPageRenderer;

/** Keeps the suite independent of pdftoppm: renders a blank PNG of a fixed size and records every call. */
class FakePdfPageRenderer extends PdfPageRenderer
{
    /** @var list<int> */
    public array $pages = [];

    /** @var list<string> */
    public array $paths = [];

    public ?int $lastDpi = null;

    /** @param list<int> $failPages */
    public function __construct(private int $width = 600, private int $height = 800, private array $failPages = []) {}

    public function render(string $pdfPath, int $page, int $dpi, int $timeoutSeconds): string
    {
        $this->pages[] = $page;
        $this->lastDpi = $dpi;
        if (in_array($page, $this->failPages, true)) {
            throw new \RuntimeException('Page render failed: fake');
        }
        $image = imagecreatetruecolor($this->width, $this->height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $path = tempnam(sys_get_temp_dir(), 'fake_pdf_page_').'.png';
        imagepng($image, $path);
        imagedestroy($image);
        $this->paths[] = $path;

        return $path;
    }
}
