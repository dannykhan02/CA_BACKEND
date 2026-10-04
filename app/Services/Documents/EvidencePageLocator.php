<?php

namespace App\Services\Documents;

/** A page is reported only when an exact excerpt occurs on one known page. */
class EvidencePageLocator
{
    public function locate(?string $text, int $knownPages, ?string $excerpt): ?int
    {
        if ($knownPages < 2 || ! $text || ! $excerpt || ! str_contains($text, "\f")) {
            return null;
        }
        $pages = explode("\f", $text);
        if (count($pages) !== $knownPages) {
            return null;
        }
        $found = null;
        foreach ($pages as $index => $page) {
            if (str_contains($page, $excerpt)) {
                if ($found !== null) return null;
                $found = $index + 1;
            }
        }
        return $found;
    }
}
