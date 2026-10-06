<?php

namespace App\Services\AI\Incremental;

/**
 * Deterministic segmentation of extracted document text into evidence spans.
 *
 * A span is a half-open character range [start_offset, end_offset) into the document's
 * extracted_text. No text is copied: the exact original characters are always retrieved
 * from the document, so a span can never disagree with its source.
 *
 * Offsets are Unicode characters, never bytes, matching ChunkPlanner and the chunk
 * offsets stored on document_chunks.
 *
 * The segmenter never rewrites, normalizes or corrects source text. OCR output is
 * segmented exactly as extracted; fixing OCR accuracy is out of scope here.
 */
class SourceSpanBuilder
{
    /** A period that ends one of these words is an abbreviation, not a sentence end. */
    private const ABBREVIATIONS = ['no', 'nos', 'mr', 'mrs', 'ms', 'dr', 'prof', 'inc', 'ltd', 'plc', 'llc',
        'corp', 'co', 'jr', 'sr', 'st', 'vs', 'etc', 'approx', 'art', 'sec', 'fig', 'tbl', 'vol', 'ch', 'para',
        'pp', 'p', 'al', 'eg', 'ie', 'cf', 'ca', 'est', 'dept', 'govt', 'univ', 'ref', 'rev', 'ed',
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec'];

    /**
     * Spans in document order.
     *
     * @return list<array{ordinal:int,key:string,page:int|null,start_offset:int,end_offset:int,type:string}>
     */
    public function build(string $text, ?int $firstPage = 1): array
    {
        $spans = $this->group($this->units($text), $text);
        $digits = max(3, strlen((string) count($spans)));
        $out = [];
        foreach ($spans as $index => $span) {
            $out[] = [
                'ordinal' => $index + 1,
                'key' => self::key($index + 1, $digits),
                'page' => $firstPage === null ? null : $firstPage + $span['page'],
                'start_offset' => $span['start'],
                'end_offset' => $span['end'],
                'type' => $span['type'],
            ];
        }

        return $out;
    }

    public static function key(int $ordinal, int $digits = 3): string
    {
        return 'E'.str_pad((string) $ordinal, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Lines with character offsets and a zero-based page index. "\f" is the page separator
     * produced by every extractor (native PDF text, OCR page joins); "\n" separates lines.
     *
     * @return list<array{text:string,start:int,end:int,page:int}>
     */
    private function lines(string $text): array
    {
        $parts = preg_split('/(\f|\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines = [];
        $offset = 0;
        $page = 0;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $offset += mb_strlen($part);
                $page += $part === "\f" ? 1 : 0;

                continue;
            }
            $lines[] = ['text' => $part, 'start' => $offset, 'end' => $offset + mb_strlen($part), 'page' => $page];
            $offset += mb_strlen($part);
        }

        return $lines;
    }

    /**
     * Atomic units: one table row, one list item, one heading or one sentence group each.
     * Blank lines, page changes and type changes all end a block.
     *
     * @return list<array{start:int,end:int,page:int,type:string}>
     */
    private function units(string $text): array
    {
        $units = [];
        $block = [];
        $blockType = null;
        $flush = function () use (&$units, &$block, &$blockType, $text) {
            if ($block !== []) {
                array_push($units, ...$this->segmentBlock($block, $blockType, $text));
            }
            $block = [];
            $blockType = null;
        };
        foreach ($this->lines($text) as $line) {
            if (trim($line['text']) === '') {
                $flush();

                continue;
            }
            $type = $this->classify($line['text']);
            // An unmarked line under a list marker is that item's continuation, not a new unit:
            // splitting it off would separate the item from the rest of its own sentence.
            $continuation = $blockType === 'list_item' && $type === 'prose' && $line['page'] === $block[0]['page'];
            // A row, a heading or a new list marker always starts its own unit, and a page break
            // always ends one, so a span can never straddle two pages.
            if ($blockType !== null && ! $continuation && ($type !== $blockType
                || $line['page'] !== $block[0]['page']
                || in_array($type, ['table_row', 'heading', 'list_item'], true))) {
                $flush();
            }
            $blockType ??= $type;
            $block[] = $line;
        }
        $flush();

        return $units;
    }

    private function classify(string $line): string
    {
        $trimmed = trim($line);
        if ($this->isTableRow($trimmed)) {
            return 'table_row';
        }
        $marker = $this->isListMarker($line);
        $heading = $this->isHeading($trimmed);
        if ($marker && $heading) {
            // A numbered line that reads as a section title ("2. FINANCIAL REVIEW", "2.1 Overview")
            // is a heading; "1. The first obligation of the borrower" is a list item.
            return $this->titleLike($trimmed) ? 'heading' : 'list_item';
        }
        if ($marker) {
            return 'list_item';
        }

        return $heading ? 'heading' : 'prose';
    }

    /** A multi-level section number, or predominantly upper-case words. */
    private function titleLike(string $line): bool
    {
        if (preg_match('/^\d+(?:\.\d+)+/u', $line)) {
            return true;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $line);
        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters);

        return $letters !== '' && mb_strlen($upper) / mb_strlen($letters) >= 0.7;
    }

    /**
     * Tab- or pipe-delimited, or columnar: PDF text extraction preserves table columns as runs of
     * two or more spaces, so a figure-heavy line with real columns is a row. Prose that merely
     * mentions several numbers is single-spaced and stays prose.
     */
    private function isTableRow(string $line): bool
    {
        if ($line === '') {
            return false;
        }
        if (str_contains($line, "\t") || substr_count($line, '|') >= 2) {
            return true;
        }
        $columns = array_map('trim', preg_split('/ {2,}/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if (count($columns) < 3) {
            return false;
        }
        // A column that ends in a figure is a value column, even when it carries its own label
        // ("2024: 6.4"). A label column ("Revenue") does not.
        $figures = count(preg_grep('/[(\-+]?[$€£¥]?\d[\d,.]*%?\)?$/u', $columns));

        return $figures >= 2 && $figures / count($columns) >= 0.4;
    }

    private function isListMarker(string $line): bool
    {
        return (bool) preg_match('/^[ \t]*(?:[-*•·–—‣▪]|\(?(?:\d{1,3}|[ivxlcdm]{1,5}|[a-z])[.)])[ \t]+\S/ui', $line);
    }

    /**
     * A heading is a short line that does not read as a sentence: an all-caps label, a numbered
     * section title, or a title-case line with no terminal punctuation.
     */
    private function isHeading(string $line): bool
    {
        if ($line === '' || mb_strlen($line) > 120 || preg_match('/[.!?;:,][)"\']?$/u', $line)) {
            return false;
        }
        if (preg_match('/^\d+(?:\.\d+)*\.?[ \t]+\S/u', $line)) {
            return true;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $line);
        if ($letters === '' || mb_strlen($letters) < 3) {
            return false;
        }
        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters);

        return mb_strlen($upper) / mb_strlen($letters) >= 0.7;
    }

    /**
     * @param  list<array{text:string,start:int,end:int,page:int}>  $block
     * @return list<array{start:int,end:int,page:int,type:string}>
     */
    private function segmentBlock(array $block, string $type, string $text): array
    {
        $page = $block[0]['page'];
        // A row, a heading or one list item is already the right evidence unit: keeping the row
        // label with its values, or the marker with its continuation lines, is the whole point.
        if ($type !== 'prose') {
            $span = $this->trimRange($text, $block[0]['start'], end($block)['end']);

            return $span === null ? [] : [$span + ['page' => $page, 'type' => $type]];
        }
        $units = [];
        $start = $block[0]['start'];
        $end = end($block)['end'];
        foreach ($this->sentences($text, $start, $end) as $sentence) {
            foreach ($this->splitLong($text, $sentence[0], $sentence[1]) as $piece) {
                $span = $this->trimRange($text, $piece[0], $piece[1]);
                if ($span !== null) {
                    $units[] = $span + ['page' => $page, 'type' => 'sentence'];
                }
            }
        }

        return $units;
    }

    /**
     * Sentence boundaries inside one prose block. Candidates are filtered in PHP rather than with
     * a lookbehind: a period belonging to a decimal, a currency amount, an initial or a known
     * abbreviation is not a boundary, so "$12.4 billion" and "U.S. Treasury" stay whole.
     *
     * @return list<array{0:int,1:int}>
     */
    private function sentences(string $text, int $start, int $end): array
    {
        $body = mb_substr($text, $start, $end - $start);
        $boundaries = [];
        if (preg_match_all('/[.!?]["\')\]]*[ \t\n]+/u', $body, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$match, $byteOffset]) {
                $before = mb_strlen(substr($body, 0, $byteOffset));
                $after = $before + mb_strlen($match);
                if ($this->isSentenceEnd($body, $before, $after)) {
                    $boundaries[] = $after;
                }
            }
        }
        $minimum = (int) config('document_intelligence.span_min_chars');
        $maximum = (int) config('document_intelligence.span_max_chars');
        // Every sentence end, plus the end of the block itself, is a candidate cut.
        $points = [...$boundaries, mb_strlen($body)];
        $groups = [];
        $open = 0;
        foreach ($points as $index => $cut) {
            if ($cut <= $open) {
                continue;
            }
            $next = $points[$index + 1] ?? null;
            // Keep whole sentences together until the span can stand on its own, and stop before
            // a group would grow past the target size. A sentence is never cut here.
            if ($cut - $open < $minimum && $next !== null && $next - $open <= $maximum) {
                continue;
            }
            $groups[] = [$start + $open, $start + $cut];
            $open = $cut;
        }

        return $groups === [] ? [[$start, $end]] : $groups;
    }

    /** $before is the offset of the punctuation; $after the offset just past the trailing space. */
    private function isSentenceEnd(string $body, int $before, int $after): bool
    {
        if ($after >= mb_strlen($body)) {
            return false;
        }
        $following = mb_substr($body, $after, 1);
        // A sentence starts with a capital, a digit, or an opening quote/bracket.
        if (! preg_match('/^["\'(\[\p{Lu}\d]/u', $following)) {
            return false;
        }
        $punctuation = mb_substr($body, $before, 1);
        if ($punctuation !== '.') {
            return true;
        }
        $head = mb_substr($body, 0, $before);
        // A digit on both sides of the period is a decimal or a numbered reference.
        if (preg_match('/\d$/u', $head) && preg_match('/^[\d,]/u', $following)) {
            return false;
        }
        if (! preg_match('/([\p{L}.]+)$/u', $head, $word)) {
            return true;
        }
        $last = $word[1];
        // "U.S." / "A." — a single letter, or a dotted initialism, is not a sentence end.
        if (preg_match('/(?:^|\.)\p{L}$/u', $last)) {
            return false;
        }

        return ! in_array(mb_strtolower($last), self::ABBREVIATIONS, true);
    }

    /**
     * A sentence longer than the hard maximum is split at safe internal punctuation
     * (semicolons, then commas, then whitespace) and never inside a number or a word.
     *
     * @return list<array{0:int,1:int}>
     */
    private function splitLong(string $text, int $start, int $end): array
    {
        $hard = (int) config('document_intelligence.span_hard_max_chars');
        if ($end - $start <= $hard) {
            return [[$start, $end]];
        }
        $pieces = [];
        $cursor = $start;
        while ($end - $cursor > $hard) {
            $window = mb_substr($text, $cursor, $hard);
            $cut = 0;
            foreach (['/;[ \t\n]+/u', '/,[ \t\n]+(?![\d])/u', '/[ \t\n]+/u'] as $pattern) {
                if (preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE)) {
                    [$match, $byteOffset] = end($matches[0]);
                    $position = mb_strlen(substr($window, 0, $byteOffset)) + mb_strlen($match);
                    if ($position >= (int) ($hard * 0.5)) {
                        $cut = $position;
                        break;
                    }
                }
            }
            $cut = $cut > 0 ? $cut : $hard;
            $pieces[] = [$cursor, $cursor + $cut];
            $cursor += $cut;
        }
        if ($cursor < $end) {
            $pieces[] = [$cursor, $end];
        }

        return $pieces;
    }

    /**
     * Groups units that are too small to stand alone with their neighbour: a heading with the
     * short content that depends on it, or a stray prose fragment with the sentence next to it.
     * Table rows and list items are never merged, and a group never crosses a page boundary.
     *
     * @param  list<array{start:int,end:int,page:int,type:string}>  $units
     * @return list<array{start:int,end:int,page:int,type:string}>
     */
    private function group(array $units, string $text): array
    {
        $minimum = (int) config('document_intelligence.span_min_chars');
        $maximum = (int) config('document_intelligence.span_max_chars');
        $spans = [];
        foreach ($units as $unit) {
            $previous = $spans === [] ? null : $spans[count($spans) - 1];
            // Only a unit that is too short to stand on its own is absorbed. A heading therefore
            // keeps the one dependent line under it, and never swallows a whole paragraph.
            $mergeable = $previous !== null
                && $previous['page'] === $unit['page']
                && $maximum >= $unit['end'] - $previous['start']
                && $minimum > $unit['end'] - $unit['start']
                && $this->groupable($previous['type'], $unit['type']);
            if ($mergeable) {
                $spans[count($spans) - 1] = ['start' => $previous['start'], 'end' => $unit['end'],
                    'page' => $previous['page'], 'type' => $previous['type'] === 'heading' ? 'section' : $previous['type']];

                continue;
            }
            $spans[] = $unit;
        }

        return $spans;
    }

    private function groupable(string $previous, string $next): bool
    {
        if (in_array($previous, ['table_row', 'list_item'], true) || in_array($next, ['table_row', 'list_item'], true)) {
            return false;
        }

        // A heading absorbs the short content under it; prose fragments absorb each other.
        return in_array($previous, ['heading', 'section'], true) || $previous === $next;
    }

    /** @return array{start:int,end:int}|null */
    private function trimRange(string $text, int $start, int $end): ?array
    {
        $body = mb_substr($text, $start, $end - $start);
        $trimmed = ltrim($body);
        $start += mb_strlen($body) - mb_strlen($trimmed);
        $end = $start + mb_strlen(rtrim($trimmed));

        return $end > $start ? ['start' => $start, 'end' => $end] : null;
    }
}
