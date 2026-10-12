<?php

namespace Tests\Unit;

use App\Services\Ocr\TesseractOcrProvider;
use PHPUnit\Framework\TestCase;

class TesseractOcrProvenanceTest extends TestCase
{
    public function test_word_boxes_keep_exact_text_offsets_and_declared_pixel_coordinates(): void
    {
        $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
            ."5\t1\t1\t1\t1\t1\t10\t20\t40\t12\t95\tTotal\n"
            ."5\t1\t1\t1\t1\t2\t60\t20\t30\t12\t90\t12.4\n";
        $result = (new \ReflectionMethod(TesseractOcrProvider::class, 'parseTsv'))
            ->invoke(new TesseractOcrProvider, $tsv, 100, 100);

        self::assertSame('Total 12.4', $result->text);
        self::assertSame('word_boxes', $result->metadata['geometry']);
        self::assertSame('image_pixels', $result->metadata['coordinate_system']);
        self::assertSame(['start' => 6, 'end' => 10, 'left' => 60, 'top' => 20,
            'width' => 30, 'height' => 12, 'block' => 1, 'paragraph' => 1,
            'line' => 1, 'confidence' => .9], $result->metadata['regions'][1]);
        self::assertSame('12.4', mb_substr($result->text, 6, 4));
        self::assertSame('unavailable', $result->metadata['tables']);
    }

    public function test_invalid_or_unmeasured_boxes_never_claim_precise_geometry(): void
    {
        $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
            ."5\t1\t1\t1\t1\t1\t99\t20\t40\t12\t95\tValue\n";
        $result = (new \ReflectionMethod(TesseractOcrProvider::class, 'parseTsv'))
            ->invoke(new TesseractOcrProvider, $tsv, 100, 100);
        self::assertSame('Value', $result->text);
        self::assertSame('unavailable', $result->metadata['geometry']);
        self::assertSame([], $result->metadata['regions']);
    }
}
