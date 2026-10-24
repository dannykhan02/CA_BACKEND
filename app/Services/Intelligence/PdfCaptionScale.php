<?php

namespace App\Services\Intelligence;

/** Selects a printed table scale only when its exception does not cover the row. */
final class PdfCaptionScale
{
    public static function forRow(string $caption, string $row): ?string
    {
        preg_match_all('/\b(thousands?|millions?|billions?)\b/iu', $caption, $matches);
        $scales = array_unique(array_map(static fn ($scale) => rtrim(mb_strtolower($scale), 's'),
            $matches[1]));
        if (count($scales) === 1) {
            return array_values($scales)[0];
        }
        if (count($scales) !== 2
            || preg_match('/^\s*\(?\s*in\s+(thousands?|millions?|billions?)\s*,\s*except\b/iu',
                $caption, $primary) !== 1
            || preg_match('/\b(?:share|shares|basic|diluted|percent|margin)\b|%/iu', $row) === 1
            || preg_match('/\bshares?\b/iu', $caption) !== 1) {
            return null;
        }

        return rtrim(mb_strtolower($primary[1]), 's');
    }
}
