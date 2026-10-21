<?php

namespace Tests\Feature;

use App\Services\Intelligence\ImageRegionLocator;
use App\Services\Ocr\ImageOrienter;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\TesseractWordBoxRunner;
use Tests\TestCase;

/**
 * The only test that runs the real engine. Everything else binds
 * Tests\Support\FakeWordBoxRunner, so the suite — and CI — stay green on a
 * machine without tesseract, while this still catches a broken TSV contract
 * or a missing language pack in the image.
 */
class ImageTextLocatorRealBinaryTest extends TestCase
{
    private ?string $image = null;

    protected function setUp(): void
    {
        parent::setUp();
        $path = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
        if ($path === '' || ! is_executable($path)) {
            self::markTestSkipped('tesseract is not installed on this machine.');
        }
        config(['ocr.tesseract_binary' => $path,
            // Only eng is guaranteed on a developer machine; the container also
            // installs swa, which the configured default asks for.
            'ocr.image_location.languages' => 'eng',
            'ocr.image_location.page_segmentation_mode' => 6,
            'ocr.image_location.max_words' => 5000]);
    }

    protected function tearDown(): void
    {
        if ($this->image !== null) {
            @unlink($this->image);
        }
        parent::tearDown();
    }

    /** Large, high-contrast built-in font text so OCR is reliable without fixtures. */
    private function renderedText(string $text): string
    {
        $scale = 6;
        $image = imagecreatetruecolor(imagefontwidth(5) * strlen($text) * $scale + 80, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $strip = imagecreatetruecolor(imagefontwidth(5) * strlen($text) + 4, 20);
        imagefill($strip, 0, 0, imagecolorallocate($strip, 255, 255, 255));
        imagestring($strip, 5, 2, 2, $text, imagecolorallocate($strip, 0, 0, 0));
        imagecopyresized($image, $strip, 40, 50, 0, 0,
            imagefontwidth(5) * strlen($text) * $scale, 120,
            imagesx($strip), imagesy($strip));
        imagedestroy($strip);

        $this->image = tempnam(sys_get_temp_dir(), 'real_ocr_').'.png';
        imagepng($image, $this->image);
        imagedestroy($image);

        return $this->image;
    }

    public function test_the_real_binary_produces_boxes_inside_the_oriented_image(): void
    {
        $path = $this->renderedText('TOTAL AMOUNT');
        $oriented = (new ImageOrienter)->orient($path, 4000);

        try {
            $words = (new TesseractWordBoxRunner(app(TesseractBinary::class)))
                ->run($oriented->path, $oriented->width, $oriented->height, 60);
        } finally {
            @unlink($oriented->path);
        }

        self::assertNotEmpty($words, 'The real engine produced no words at all.');
        foreach ($words as $word) {
            self::assertGreaterThanOrEqual(0, $word->left);
            self::assertGreaterThanOrEqual(0, $word->top);
            self::assertLessThanOrEqual($oriented->width, $word->left + $word->width);
            self::assertLessThanOrEqual($oriented->height, $word->top + $word->height);
            self::assertGreaterThan(0.0, $word->confidence);
            self::assertLessThanOrEqual(1.0, $word->confidence);
        }

        // The pipeline end to end: a real reading locating a real quote.
        $stored = array_map(static fn ($word) => ['text' => $word->text,
            'geometry' => ['system' => 'image_pixels_top_left',
                'box' => [$word->left, $word->top, $word->width, $word->height],
                'width' => $oriented->width, 'height' => $oriented->height,
                'rotation' => 0, 'confidence' => $word->confidence, 'line' => $word->line]], $words);
        $regions = app(ImageRegionLocator::class)->locate('TOTAL AMOUNT', $stored, 2);

        self::assertNotEmpty($regions, 'Words read: '
            .implode(' ', array_map(static fn ($w) => $w->text, $words)));
        foreach ($regions as $region) {
            self::assertGreaterThanOrEqual(0.0, $region['x']);
            self::assertGreaterThanOrEqual(0.0, $region['y']);
            self::assertLessThanOrEqual(1.0, $region['x'] + $region['width']);
            self::assertLessThanOrEqual(1.0, $region['y'] + $region['height']);
        }
    }

    public function test_a_missing_language_pack_surfaces_as_a_failure_not_a_silent_empty_reading(): void
    {
        config(['ocr.image_location.languages' => 'zzz']);
        $path = $this->renderedText('TOTAL AMOUNT');
        $oriented = (new ImageOrienter)->orient($path, 4000);

        try {
            $this->expectException(\RuntimeException::class);
            (new TesseractWordBoxRunner(app(TesseractBinary::class)))
                ->run($oriented->path, $oriented->width, $oriented->height, 60);
        } finally {
            @unlink($oriented->path);
        }
    }
}
