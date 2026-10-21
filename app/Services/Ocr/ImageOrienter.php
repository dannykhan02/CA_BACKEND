<?php

namespace App\Services\Ocr;

/**
 * Applies EXIF orientation before anything measures the image, and caps the
 * longest side.
 *
 * Nothing in the pipeline normalised orientation before this class existed,
 * and TextRoleProvenanceProducer's OCR geometry hardcoded `rotation => 0`.
 * A phone photo carrying Orientation=6 therefore produced boxes in the
 * sensor's coordinate space while every browser draws the rotated image, so
 * a highlight would land on the wrong part of the picture. Orienting first
 * is what makes `rotation => 0` an accurate statement rather than a guess:
 * the stored boxes and the image a client renders share one coordinate
 * space.
 */
class ImageOrienter
{
    /**
     * Writes an oriented (and, if needed, downscaled) copy next to $sourcePath.
     *
     * @param  int  $maxDimension  Cap for the longest side, in pixels.
     *
     * @throws \RuntimeException when the image cannot be decoded.
     */
    public function orient(string $sourcePath, int $maxDimension): OrientedImage
    {
        $size = @getimagesize($sourcePath);
        if (! is_array($size) || (int) $size[0] < 1 || (int) $size[1] < 1) {
            throw new \RuntimeException('Image dimensions could not be read.');
        }
        $orientation = $this->exifOrientation($sourcePath);
        $image = $this->decode($sourcePath, (string) ($size['mime'] ?? ''));

        try {
            $image = $this->applyOrientation($image, $orientation);
            $width = imagesx($image);
            $height = imagesy($image);
            $downscaled = false;
            if ($maxDimension > 0 && max($width, $height) > $maxDimension) {
                $scale = $maxDimension / max($width, $height);
                // Boxes are measured against the scaled image and then normalised to
                // 0..1, so downscaling changes precision, never correctness.
                $scaled = imagescale($image, max(1, (int) round($width * $scale)),
                    max(1, (int) round($height * $scale)));
                if ($scaled === false) {
                    throw new \RuntimeException('Image could not be downscaled.');
                }
                imagedestroy($image);
                $image = $scaled;
                $width = imagesx($image);
                $height = imagesy($image);
                $downscaled = true;
            }
            $target = tempnam(sys_get_temp_dir(), 'ocr_oriented_');
            if ($target === false) {
                throw new \RuntimeException('Oriented image could not be staged.');
            }
            // PNG: tesseract reads it losslessly, so no JPEG artefacts are
            // introduced between orientation and measurement.
            if (! imagepng($image, $target)) {
                @unlink($target);
                throw new \RuntimeException('Oriented image could not be written.');
            }
            @chmod($target, 0600);

            return new OrientedImage($target, $width, $height, $orientation, $downscaled);
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /** 1 whenever the tag is absent, unreadable, or the ext is unavailable. */
    public function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        try {
            // Only JPEG/TIFF carry EXIF; exif_read_data warns loudly on a PNG.
            $data = @exif_read_data($path);
        } catch (\Throwable) {
            return 1;
        }
        $orientation = is_array($data) ? ($data['Orientation'] ?? null) : null;

        return is_numeric($orientation) && (int) $orientation >= 1 && (int) $orientation <= 8
            ? (int) $orientation : 1;
    }

    private function decode(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
        if (! $image instanceof \GdImage) {
            throw new \RuntimeException('Image could not be decoded for orientation.');
        }

        return $image;
    }

    /**
     * The eight EXIF orientations as a counter-clockwise rotation plus an
     * optional mirror. imagerotate() takes counter-clockwise degrees, so
     * orientation 6 ("row 0 at the right", i.e. correct by rotating 90 CW)
     * is -90 here. 5 and 7 are the two diagonal reflections, which are a
     * quarter turn followed by a horizontal mirror.
     */
    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        [$degrees, $flip] = match ($orientation) {
            2 => [0, IMG_FLIP_HORIZONTAL],
            3 => [180, null],
            4 => [0, IMG_FLIP_VERTICAL],
            5 => [-90, IMG_FLIP_HORIZONTAL],
            6 => [-90, null],
            7 => [90, IMG_FLIP_HORIZONTAL],
            8 => [90, null],
            default => [0, null],
        };
        if ($degrees !== 0) {
            $rotated = imagerotate($image, $degrees, 0);
            if ($rotated === false) {
                throw new \RuntimeException('Image could not be rotated.');
            }
            imagedestroy($image);
            $image = $rotated;
        }
        if ($flip !== null && ! imageflip($image, $flip)) {
            throw new \RuntimeException('Image could not be mirrored.');
        }

        return $image;
    }
}
