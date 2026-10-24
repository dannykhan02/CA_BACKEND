<?php

namespace App\Services\Documents;

/**
 * A page is reported only when the excerpt occurs on exactly one known page.
 *
 * An exact match wins. Only when the excerpt is not found verbatim does the
 * locator retry with both sides folded the same way (case, spacing, punctuation),
 * because OCR and model-quoted excerpts routinely differ from the stored text in
 * exactly those. An excerpt found on several pages, or too short to be
 * distinctive once folded, still gets no page.
 */
class EvidencePageLocator
{
    private const MIN_FOLDED_LENGTH = 12;

    public function locate(?string $text, int $knownPages, ?string $excerpt): ?int
    {
        if ($knownPages < 2 || ! $text || ! $excerpt || ! str_contains($text, "\f")) {
            return null;
        }
        $pages = explode("\f", $text);
        if (count($pages) !== $knownPages) {
            return null;
        }
        $found = $this->unique($pages, $excerpt);
        if ($found !== false) {
            return $found;
        }
        $folded = $this->fold($excerpt);
        if (mb_strlen($folded) < self::MIN_FOLDED_LENGTH) {
            return null;
        }

        return $this->unique(array_map(fn (string $page) => $this->fold($page), $pages), $folded) ?: null;
    }

    /** The unique 1-based page containing $needle, null when ambiguous, false when absent. */
    private function unique(array $pages, string $needle): int|false|null
    {
        $found = false;
        foreach ($pages as $index => $page) {
            if (str_contains($page, $needle)) {
                if ($found !== false) {
                    return null;
                }
                $found = $index + 1;
            }
        }

        return $found;
    }

    private function fold(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}
