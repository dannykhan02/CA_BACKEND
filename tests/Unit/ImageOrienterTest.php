<?php

namespace Tests\Unit;

use App\Services\Ocr\ImageOrienter;
use PHPUnit\Framework\TestCase;

class ImageOrienterTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
        parent::tearDown();
    }

    /**
     * A landscape JPEG with a black block in its top-left corner, tagged with
     * the given EXIF orientation.
     */
    private function jpeg(int $orientation, int $width = 200, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 19, 9, imagecolorallocate($image, 0, 0, 0));
        $path = tempnam(sys_get_temp_dir(), 'orient_test_');
        $this->temporary[] = $path;
        imagejpeg($image, $path, 100);
        imagedestroy($image);

        if ($orientation !== 1) {
            file_put_contents($path, $this->withExifOrientation(file_get_contents($path), $orientation));
        }

        return $path;
    }

    /** Splices a minimal APP1/EXIF segment carrying only tag 0x0112 after the SOI. */
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II'.pack('v', 0x2A).pack('V', 8)   // little-endian TIFF header, IFD0 at byte 8
            .pack('v', 1)                            // one entry
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);                           // no next IFD
        $payload = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function colorAt(string $path, int $x, int $y): array
    {
        $image = imagecreatefrompng($path);
        $rgb = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    public function test_exif_orientation_is_read_from_the_file(): void
    {
        self::assertSame(6, (new ImageOrienter)->exifOrientation($this->jpeg(6)));
        self::assertSame(1, (new ImageOrienter)->exifOrientation($this->jpeg(1)));
    }

    public function test_quarter_turn_swaps_the_dimensions_boxes_are_measured_against(): void
    {
        $oriented = (new ImageOrienter)->orient($this->jpeg(6), 4000);
        $this->temporary[] = $oriented->path;

        // The sensor stored 200x100; a browser draws it 100x200, and that is the
        // space every stored box and every normalised region must live in.
        self::assertSame(100, $oriented->width);
        self::assertSame(200, $oriented->height);
        self::assertSame(6, $oriented->exifOrientation);
        self::assertFalse($oriented->downscaled);
    }

    public function test_pixels_move_to_where_a_viewer_sees_them_so_normalised_boxes_are_correct(): void
    {
        $oriented = (new ImageOrienter)->orient($this->jpeg(6), 4000);
        $this->temporary[] = $oriented->path;

        // Orientation 6 is corrected by a 90 degree clockwise turn, which sends
        // the original top-left corner to the top-right. Normalising the block's
        // new box gives x ~0.9, y ~0.0 — not the x ~0.0 the unoriented pixels
        // would have claimed.
        self::assertSame([0, 0, 0], $this->colorAt($oriented->path, 95, 5));
        self::assertSame([255, 255, 255], $this->colorAt($oriented->path, 5, 5));
        self::assertSame(0.9, round(90 / $oriented->width, 6));
    }

    public function test_an_upright_image_is_passed_through_unrotated(): void
    {
        $oriented = (new ImageOrienter)->orient($this->jpeg(1), 4000);
        $this->temporary[] = $oriented->path;

        self::assertSame(200, $oriented->width);
        self::assertSame(100, $oriented->height);
        self::assertSame([0, 0, 0], $this->colorAt($oriented->path, 5, 5));
    }

    public function test_half_turn_and_mirror_orientations_are_applied(): void
    {
        $orienter = new ImageOrienter;
        $half = $orienter->orient($this->jpeg(3), 4000);
        $this->temporary[] = $half->path;
        self::assertSame(200, $half->width);
        self::assertSame(100, $half->height);
        self::assertSame([0, 0, 0], $this->colorAt($half->path, 194, 94));

        $mirrored = $orienter->orient($this->jpeg(2), 4000);
        $this->temporary[] = $mirrored->path;
        self::assertSame([0, 0, 0], $this->colorAt($mirrored->path, 194, 5));
    }

    public function test_an_oversized_image_is_downscaled_to_the_configured_longest_side(): void
    {
        $oriented = (new ImageOrienter)->orient($this->jpeg(1, 1000, 500), 400);
        $this->temporary[] = $oriented->path;

        self::assertSame(400, $oriented->width);
        self::assertSame(200, $oriented->height);
        self::assertTrue($oriented->downscaled);
    }

    public function test_an_undecodable_file_is_rejected_rather_than_measured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'orient_test_');
        $this->temporary[] = $path;
        file_put_contents($path, 'not an image');

        $this->expectException(\RuntimeException::class);
        (new ImageOrienter)->orient($path, 4000);
    }
}
