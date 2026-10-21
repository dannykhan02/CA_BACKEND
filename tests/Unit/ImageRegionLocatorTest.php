<?php

namespace Tests\Unit;

use App\Services\Intelligence\ImageRegionLocator;
use App\Services\Intelligence\MatchingTextNormalizer;
use PHPUnit\Framework\TestCase;

class ImageRegionLocatorTest extends TestCase
{
    private function locator(): ImageRegionLocator
    {
        return new ImageRegionLocator(new MatchingTextNormalizer);
    }

    /** @param  array{0:string,1:int,2:int,3:int,4:int}  ...$words text, left, top, width, height */
    private function words(int $imageWidth, int $imageHeight, array ...$words): array
    {
        return array_map(static fn (array $word) => [
            'text' => $word[0],
            'geometry' => ['system' => 'image_pixels_top_left',
                'box' => [$word[1], $word[2], $word[3], $word[4]],
                'width' => $imageWidth, 'height' => $imageHeight, 'rotation' => 0,
                'confidence' => 0.9, 'line' => $word[5] ?? '1:1:1'],
        ], $words);
    }

    public function test_matched_quote_returns_one_merged_box_per_line_normalised_to_unit_square(): void
    {
        $words = $this->words(1000, 500,
            ['Total', 100, 50, 100, 20],
            ['amount', 220, 50, 120, 20],
            ['payable', 100, 100, 130, 20, '1:1:2'],
        );

        $regions = $this->locator()->locate('Total amount payable', $words, 2);

        // Line 1 spans x 100..340, line 2 spans x 100..230. Two lines, two boxes.
        self::assertSame([
            ['x' => 0.1, 'y' => 0.1, 'width' => 0.24, 'height' => 0.04],
            ['x' => 0.1, 'y' => 0.2, 'width' => 0.13, 'height' => 0.04],
        ], $regions);
    }

    public function test_quote_absent_from_the_words_locates_nothing(): void
    {
        $words = $this->words(1000, 500, ['Total', 100, 50, 100, 20], ['amount', 220, 50, 120, 20]);

        self::assertSame([], $this->locator()->locate('Net position', $words, 2));
    }

    public function test_repeated_phrase_is_ambiguous_rather_than_guessed(): void
    {
        $words = $this->words(1000, 500,
            ['Total', 100, 50, 100, 20],
            ['amount', 220, 50, 120, 20],
            ['Total', 100, 100, 100, 20, '1:1:2'],
            ['amount', 220, 100, 120, 20, '1:1:2'],
        );

        self::assertSame([], $this->locator()->locate('Total amount', $words, 2));
    }

    public function test_small_ocr_differences_are_tolerated_but_digits_are_not(): void
    {
        $words = $this->words(1000, 500,
            ['Memorandurn', 100, 50, 150, 20],   // rn for m
            ['of', 260, 50, 30, 20],
            ['Understandlng', 300, 50, 170, 20], // l for i
        );
        self::assertNotSame([], $this->locator()->locate('Memorandum of Understanding', $words, 2));

        $figures = $this->words(1000, 500, ['Revenue', 100, 50, 100, 20], ['12.4', 220, 50, 60, 20]);
        self::assertSame([], $this->locator()->locate('Revenue 12.9', $figures, 2));
        self::assertNotSame([], $this->locator()->locate('Revenue 12.4', $figures, 2));
    }

    public function test_punctuation_case_and_whitespace_differences_do_not_prevent_a_match(): void
    {
        $words = $this->words(1000, 500, ['TOTAL:', 100, 50, 110, 20], ['amount', 220, 50, 120, 20]);

        self::assertNotSame([], $this->locator()->locate("total   \n amount.", $words, 2));
    }

    public function test_quote_shorter_than_the_configured_minimum_is_never_located(): void
    {
        $words = $this->words(1000, 500, ['Total', 100, 50, 100, 20]);

        self::assertSame([], $this->locator()->locate('Total', $words, 2));
        self::assertNotSame([], $this->locator()->locate('Total', $words, 1));
    }

    public function test_words_without_usable_geometry_are_not_matchable(): void
    {
        $words = [
            ['text' => 'Total', 'geometry' => ['system' => 'pdf_points_bottom_left',
                'box' => [1, 2, 3, 4], 'width' => 10, 'height' => 10, 'rotation' => 0]],
            ['text' => 'amount', 'geometry' => ['system' => 'image_pixels_top_left',
                'box' => [1, 2, 3, 4], 'width' => 0, 'height' => 10, 'rotation' => 0]],
        ];

        self::assertSame([], $this->locator()->locate('Total amount', $words, 2));
    }
}
