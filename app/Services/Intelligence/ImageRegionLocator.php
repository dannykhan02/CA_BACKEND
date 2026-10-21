<?php

namespace App\Services\Intelligence;

/**
 * Locates a cited quote among an image's stored OCR words.
 *
 * The quote comes from the claude_vision reading; the boxes come from
 * tesseract's independent reading of the same pixels. The two readings will
 * not agree character for character, so matching is per-word and tolerant:
 * case, punctuation and whitespace are folded away, and a short edit distance
 * is allowed within each word.
 *
 * What is *not* tolerated is guessing. A highlight is offered only when one
 * unambiguous run of words carries the whole quote. Zero matches, or more than
 * one candidate run, both mean "not located" — a second candidate means the
 * phrase occurs more than once on the image and marking either one would be
 * picking an occurrence the reader did not ask for.
 */
class ImageRegionLocator
{
    /** Guard against a pathological quote scanning a dense page forever. */
    private const MAX_QUOTE_WORDS = 120;

    public function __construct(private MatchingTextNormalizer $normalizer) {}

    /**
     * @param  list<array{text:string,geometry:array}>  $words  Stored words, in reading order.
     * @return list<array{x:float,y:float,width:float,height:float}> Empty when not located.
     */
    public function locate(string $quote, array $words, int $minimumQuoteWords): array
    {
        $needle = $this->tokenize($quote);
        $haystack = [];
        foreach ($words as $word) {
            $token = $this->foldWord($word['text']);
            if ($token !== '' && $this->usableGeometry($word['geometry'])) {
                $haystack[] = ['token' => $token, 'geometry' => $word['geometry']];
            }
        }
        if ($needle === [] || count($needle) < max(1, $minimumQuoteWords)
            || count($needle) > self::MAX_QUOTE_WORDS || count($needle) > count($haystack)) {
            return [];
        }

        $match = null;
        $limit = count($haystack) - count($needle);
        for ($offset = 0; $offset <= $limit; $offset++) {
            if (! $this->matchesAt($needle, $haystack, $offset)) {
                continue;
            }
            if ($match !== null) {
                return []; // Ambiguous: the quote appears more than once.
            }
            $match = array_slice($haystack, $offset, count($needle));
        }

        return $match === null ? [] : $this->mergeByLine($match);
    }

    /** Whole words only: a quote never half-matches a word it merely sits inside. */
    private function matchesAt(array $needle, array $haystack, int $offset): bool
    {
        foreach ($needle as $index => $token) {
            if (! $this->wordsMatch($token, $haystack[$offset + $index]['token'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Allowance scales with length, because a one-character slip in "VAT" changes
     * the word while the same slip in "Memorandum" plainly does not. Digits are
     * matched exactly: "12.4" and "12.9" are different numbers, not an OCR slip.
     */
    private function wordsMatch(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }
        if (preg_match('/\d/u', $expected) === 1 || preg_match('/\d/u', $actual) === 1) {
            return false;
        }
        $length = mb_strlen($expected);
        $allowance = match (true) {
            $length <= 4 => 0,
            $length <= 7 => 1,
            default => 2,
        };
        if ($allowance === 0 || abs($length - mb_strlen($actual)) > $allowance) {
            return false;
        }

        // levenshtein() is byte-based; fold() has already lowercased, and a
        // multibyte word simply gets a stricter comparison than a latin one.
        return levenshtein($expected, $actual) <= $allowance;
    }

    /**
     * One rectangle per text line, not one per word: a highlight that draws a
     * box around every word looks like a redaction, and a single box spanning
     * two lines covers text that was never cited.
     *
     * @return list<array{x:float,y:float,width:float,height:float}>
     */
    private function mergeByLine(array $matched): array
    {
        $lines = [];
        $order = [];
        foreach ($matched as $word) {
            $geometry = $word['geometry'];
            $key = (string) ($geometry['line'] ?? 'unknown');
            [$left, $top, $width, $height] = $geometry['box'];
            $right = $left + $width;
            $bottom = $top + $height;
            if (! isset($lines[$key])) {
                $order[] = $key;
                $lines[$key] = ['left' => $left, 'top' => $top, 'right' => $right,
                    'bottom' => $bottom, 'width' => $geometry['width'], 'height' => $geometry['height']];

                continue;
            }
            $lines[$key]['left'] = min($lines[$key]['left'], $left);
            $lines[$key]['top'] = min($lines[$key]['top'], $top);
            $lines[$key]['right'] = max($lines[$key]['right'], $right);
            $lines[$key]['bottom'] = max($lines[$key]['bottom'], $bottom);
        }

        $regions = [];
        foreach ($order as $key) {
            $line = $lines[$key];
            $imageWidth = (float) $line['width'];
            $imageHeight = (float) $line['height'];
            if ($imageWidth <= 0 || $imageHeight <= 0) {
                return [];
            }
            $x = $this->clamp($line['left'] / $imageWidth);
            $y = $this->clamp($line['top'] / $imageHeight);
            $regions[] = [
                'x' => $x,
                'y' => $y,
                'width' => $this->clamp($line['right'] / $imageWidth - $x),
                'height' => $this->clamp($line['bottom'] / $imageHeight - $y),
            ];
        }

        return $regions;
    }

    /**
     * Whitespace-separated words with their outer punctuation trimmed.
     *
     * Punctuation *inside* a word is kept, because a tesseract word is a run of
     * glyphs: "12.4" is one token on both sides, and splitting it into "12" and
     * "4" would stop every figure in a document from ever matching. Trailing
     * punctuation is dropped so a quoted "Total" still matches a printed
     * "Total:".
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $folded = $this->fold($text);
        if ($folded === '') {
            return [];
        }
        $tokens = [];
        foreach (preg_split('/\s+/u', $folded) ?: [] as $piece) {
            $token = $this->trimPunctuation($piece);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** A stored word folds to exactly one token, or to nothing. */
    private function foldWord(string $text): string
    {
        $tokens = $this->tokenize($text);

        return count($tokens) === 1 ? $tokens[0] : '';
    }

    private function trimPunctuation(string $token): string
    {
        return (string) preg_replace(['/^[^\p{L}\p{N}]+/u', '/[^\p{L}\p{N}]+$/u'], '', $token);
    }

    private function fold(string $text): string
    {
        return $this->normalizer->fold($text);
    }

    private function usableGeometry(mixed $geometry): bool
    {
        return is_array($geometry)
            && ($geometry['system'] ?? null) === 'image_pixels_top_left'
            && is_array($geometry['box'] ?? null) && count($geometry['box']) === 4
            && count(array_filter($geometry['box'], 'is_numeric')) === 4
            && is_numeric($geometry['width'] ?? null) && is_numeric($geometry['height'] ?? null)
            && (float) $geometry['width'] > 0 && (float) $geometry['height'] > 0;
    }

    private function clamp(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 6);
    }
}
