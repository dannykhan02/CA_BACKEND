<?php

namespace Tests\Unit\Services;

use App\Services\Extraction\PdfHybridAssembler;
use App\Services\Extraction\StructuredDocument;
use PHPUnit\Framework\TestCase;

class PdfHybridAssemblerTest extends TestCase
{
    private function native(): StructuredDocument
    {
        return new StructuredDocument('PDF', 'fixture.v1', "Native page\f\fNative end", [
            ['id' => 'page:1', 'type' => 'page', 'page' => 1, 'path' => 'page:1',
                'start' => 0, 'end' => 11, 'text' => 'Native page',
                'meta' => ['modality' => 'embedded_pdf_text']],
            ['id' => 'page:2', 'type' => 'page', 'page' => 2, 'path' => 'page:2',
                'meta' => ['modality' => 'embedded_pdf_text']],
            ['id' => 'page:3', 'type' => 'page', 'page' => 3, 'path' => 'page:3',
                'start' => 13, 'end' => 23, 'text' => 'Native end',
                'meta' => ['modality' => 'embedded_pdf_text']],
        ], ['empty_text_pages' => [2], 'tables' => 'unavailable']);
    }

    public function test_ocr_of_blank_page_preserves_native_pages_and_exact_offsets(): void
    {
        $result = (new PdfHybridAssembler)->assemble($this->native(), [
            2 => ['text' => 'Scan 12.4', 'confidence' => 0.88,
                'metadata' => ['geometry' => 'word_boxes']],
        ]);
        self::assertSame("Native page\fScan 12.4\fNative end", $result->text);
        self::assertSame('ocr', $result->node('page:2')['meta']['modality']);
        self::assertSame('embedded_pdf_text', $result->node('page:3')['meta']['modality']);
        self::assertSame(12, $result->node('page:2')['start']);
        self::assertSame(22, $result->node('page:3')['start']);
        self::assertSame([2], $result->capabilities['ocr_pages']);
        self::assertSame('unavailable', $result->capabilities['tables']);
    }

    public function test_ocr_cannot_override_native_page_or_invent_a_page(): void
    {
        foreach ([1, 4] as $page) {
            try {
                (new PdfHybridAssembler)->assemble($this->native(), [
                    $page => ['text' => 'Conflicting 99'],
                ]);
                self::fail('Unsupported OCR page was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('OCR page', $e->getMessage());
            }
        }
    }
}
