<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\TesseractOcrProvider;
use Tests\TestCase;

class TesseractBinaryAvailabilityTest extends TestCase
{
    private function binary(string $path): TesseractBinary
    {
        config(['ocr.tesseract_binary' => $path]);
        $binary = new TesseractBinary;
        $binary->forget();

        return $binary;
    }

    public function test_supports_is_false_when_the_binary_is_missing(): void
    {
        $binary = $this->binary('/nonexistent/tesseract');
        $provider = new TesseractOcrProvider($binary);

        self::assertFalse($binary->available());
        self::assertNull($binary->version());
        // Unconditionally true before this change, which claimed OCR geometry
        // the container could not produce.
        self::assertFalse($provider->supports(new Document(['id' => 'doc-1'])));
    }

    public function test_an_empty_binary_path_is_not_probed(): void
    {
        self::assertFalse($this->binary('')->available());
    }

    public function test_supports_is_true_and_the_version_is_read_when_the_binary_exists(): void
    {
        $real = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
        if ($real === '' || ! is_executable($real)) {
            self::markTestSkipped('tesseract is not installed on this machine.');
        }
        $binary = $this->binary($real);

        self::assertTrue($binary->available());
        self::assertStringContainsStringIgnoringCase('tesseract', (string) $binary->version());
        self::assertTrue((new TesseractOcrProvider($binary))->supports(new Document(['id' => 'doc-1'])));
    }

    public function test_the_probe_result_is_cached_for_the_process(): void
    {
        $binary = $this->binary('/nonexistent/tesseract');
        self::assertFalse($binary->available());

        // A later config change must not re-probe: the cost of a fork per
        // document is exactly what caching this avoids.
        config(['ocr.tesseract_binary' => '/usr/bin/tesseract']);
        self::assertFalse($binary->available());
        $binary->forget();
        self::assertSame(is_executable('/usr/bin/tesseract'), $binary->available());
    }
}
