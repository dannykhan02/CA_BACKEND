<?php

namespace Tests\Unit\Services\Ocr;

use App\Services\Ocr\PdfRasterizer;
use Tests\TestCase;

class PdfRasterizerTest extends TestCase
{
    private string $fixturePdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePdf = base_path('tests/Fixtures/test_5page.pdf');
        if (! file_exists($this->fixturePdf)) {
            $this->markTestSkipped('Fixture tests/Fixtures/test_5page.pdf is missing.');
        }
    }

    public function test_rasterizes_only_the_requested_page_range(): void
    {
        $rasterizer = new PdfRasterizer();

        $result = $rasterizer->toPageImages($this->fixturePdf, [2, 4]);

        // Only pages 2, 3, 4 should exist (pdftoppm -f 2 -l 4 produces a
        // contiguous range covering min..max of the requested pages).
        $this->assertCount(3, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
        $this->assertArrayHasKey(4, $result);
        $this->assertArrayNotHasKey(1, $result);
        $this->assertArrayNotHasKey(5, $result);

        foreach ($result as $path) {
            $this->assertFileExists($path);
        }

        $rasterizer->cleanup($result);
    }

    public function test_rasterizes_whole_document_when_no_pages_given(): void
    {
        $rasterizer = new PdfRasterizer();

        $result = $rasterizer->toPageImages($this->fixturePdf, null);

        $this->assertCount(5, $result);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertArrayHasKey($i, $result);
        }

        $rasterizer->cleanup($result);
    }

    public function test_cleanup_removes_the_temp_directory_entirely(): void
    {
        $rasterizer = new PdfRasterizer();

        $result = $rasterizer->toPageImages($this->fixturePdf, [1, 2]);
        $dir = dirname(reset($result));

        $this->assertDirectoryExists($dir);
        foreach ($result as $path) {
            $this->assertFileExists($path);
        }

        $rasterizer->cleanup($result);

        $this->assertDirectoryDoesNotExist($dir);
        foreach ($result as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_cleanup_on_empty_array_does_nothing_and_does_not_error(): void
    {
        $rasterizer = new PdfRasterizer();

        // Must not throw when there's nothing to clean up (e.g. a document
        // with no page-backed visual references never called toPageImages()).
        $rasterizer->cleanup([]);

        $this->assertTrue(true);
    }

    public function test_leaves_no_leftover_ocr_directories_in_tmp_after_a_full_cycle(): void
    {
        $before = glob(sys_get_temp_dir() . '/ocr_*', GLOB_ONLYDIR) ?: [];

        $rasterizer = new PdfRasterizer();
        $result = $rasterizer->toPageImages($this->fixturePdf, [2, 3]);
        $rasterizer->cleanup($result);

        $after = glob(sys_get_temp_dir() . '/ocr_*', GLOB_ONLYDIR) ?: [];

        $this->assertCount(
            count($before),
            $after,
            'toPageImages()+cleanup() must not leave any ocr_* temp directory behind.'
        );
    }
}
