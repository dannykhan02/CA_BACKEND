<?php

namespace App\Services\AI\Incremental;

/** Offsets are Unicode characters, never bytes. Text stays on documents. */
class ChunkPlanner
{
    public function estimate(string $text): int
    {
        // Conservative fallback, explicitly labelled as estimated in preflight.
        return max(1, (int) ceil(strlen($text) / 3));
    }

    /**
     * Fewest balanced partitions of at most $partitionTokens each. Balancing avoids
     * a tiny trailing slice; semantic boundaries keep sections and tables together.
     */
    public function partition(string $text, int $partitionTokens, float $tokensPerByte = 1 / 3): array
    {
        $tokensPerByte = min(1, max(0.1, $tokensPerByte));
        $tokens = max(1, (int) ceil(strlen($text) * $tokensPerByte));
        $count = max(1, (int) ceil($tokens / max(1, $partitionTokens)));

        return $this->plan($text, (int) ceil($tokens / $count), tokensPerByte: $tokensPerByte, maximum: $partitionTokens);
    }

    /**
     * Span-aligned partitions: chunk boundaries are always evidence-span boundaries, so an
     * evidence span is never cut in half and every span the model sees is complete.
     *
     * Sizing is the existing token budget, applied to the labeled form the provider actually
     * receives (span IDs included), so adding IDs does not quietly inflate the number of
     * requests. Overlap is carried as whole spans, never as a mid-span slice.
     *
     * @return list<array{start_offset:int,end_offset:int,start_page:int|null,end_page:int|null,token_count:int,input_hash:string,overlap_chars:int}>
     */
    public function planFromSpans(EvidenceSpanSet $spans, string $text, ?int $target = null, bool $overlap = true, float $tokensPerByte = 1 / 3): array
    {
        $all = $spans->all();
        if ($all === []) {
            return [];
        }
        $target ??= app(ExtractionCapacity::class)->partitionTokens();
        $tokensPerByte = min(1, max(0.1, $tokensPerByte));
        $weights = [];
        foreach ($all as $index => $span) {
            $bytes = strlen(mb_substr($text, $span['start_offset'], $span['end_offset'] - $span['start_offset']));
            // "[E001]\n" plus the blank line between spans: the label is part of the request.
            $weights[$index] = max(1, (int) ceil(($bytes + strlen($span['key']) + 5) * $tokensPerByte));
        }
        $total = array_sum($weights);
        // Fewest partitions of at most $target each, balanced so no tiny trailing chunk is created
        // and so labeling the source never costs more provider calls than the packing requires.
        $count = max(1, (int) ceil($total / max(1, $target)));
        $limit = $this->balancedLimit($weights, $count, $target, $overlap);
        $chunks = [];
        $previousEnd = 0;
        foreach ($this->bins($weights, $limit, $overlap) as [$first, $last, $used]) {
            $start = $all[$first]['start_offset'];
            $end = $all[$last]['end_offset'];
            $chunks[] = [
                'start_offset' => $start, 'end_offset' => $end,
                'start_page' => $all[$first]['page'], 'end_page' => $all[$last]['page'],
                'token_count' => $used,
                'input_hash' => hash('sha256', mb_substr($text, $start, $end - $start)),
                'overlap_chars' => max(0, $previousEnd - $start),
            ];
            $previousEnd = $end;
        }

        return $chunks;
    }

    /**
     * Smallest per-chunk budget that still packs the spans into $count chunks.
     *
     * Rounding each span's own weight up, and carrying whole spans as overlap, both mean that a
     * naive ceil($total / $count) can need a chunk more than the packing actually requires. That
     * would turn labeled source into extra provider calls, which this architecture must not do.
     *
     * @param  list<int>  $weights
     */
    private function balancedLimit(array $weights, int $count, int $target, bool $overlap): int
    {
        $low = max(max($weights), (int) ceil(array_sum($weights) / $count));
        $high = max($low, $target);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (count($this->bins($weights, $middle, $overlap)) <= $count) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        // Overlap cost does not have to fall monotonically with the budget, so a search result
        // that still needs more chunks than the packing allows falls back to the full budget.
        return count($this->bins($weights, $low, $overlap)) <= $count ? max(1, $low) : max(1, $high);
    }

    /**
     * Greedy fill at this budget, as inclusive span index ranges with each chunk's token weight.
     * A span heavier than the budget still occupies a chunk of its own rather than being cut, and
     * whole trailing spans are carried into the next chunk as cross-boundary context.
     *
     * @param  list<int>  $weights
     * @return list<array{0:int,1:int,2:int}>
     */
    private function bins(array $weights, int $limit, bool $overlap): array
    {
        $overlapLimit = $overlap
            ? min((int) config('document_intelligence.chunk_overlap_tokens'), (int) ($limit * 0.15))
            : 0;
        $bins = [];
        $cursor = 0;
        $count = count($weights);
        while ($cursor < $count) {
            $first = $cursor;
            $used = 0;
            while ($cursor < $count && ($used === 0 || $used + $weights[$cursor] <= $limit)) {
                $used += $weights[$cursor];
                $cursor++;
            }
            $bins[] = [$first, $cursor - 1, $used];
            if ($cursor >= $count) {
                break;
            }
            $back = 0;
            // The step back always leaves at least one span of forward progress.
            while ($cursor - 1 > $first && $back + $weights[$cursor - 1] <= $overlapLimit) {
                $back += $weights[$cursor - 1];
                $cursor--;
            }
        }

        return $bins;
    }

    public function plan(string $text, ?int $target = null, int $base = 0, int $firstPage = 1, bool $overlap = true, float $tokensPerByte = 1 / 3, ?int $maximum = null): array
    {
        $target ??= app(ExtractionCapacity::class)->partitionTokens();
        $maximum ??= $target;
        $tokensPerByte = min(1, max(0.1, $tokensPerByte));
        // Aim slightly past the balanced target so a boundary cut lands near it, never past the maximum.
        $window = min($maximum, (int) ceil($target * 1.15));
        $byteLimit = max(1, (int) floor($window / $tokensPerByte));
        $overlapLimit = $overlap ? min((int) (config('document_intelligence.chunk_overlap_tokens') / $tokensPerByte), (int) ($byteLimit * 0.15)) : 0;
        $length = mb_strlen($text);
        $start = 0;
        $previousEnd = 0;
        $chunks = [];
        while ($start < $length) {
            // mb_strcut respects UTF-8 while enforcing a conservative byte budget.
            $window = mb_strcut(mb_substr($text, $start, $byteLimit), 0, $byteLimit, 'UTF-8');
            $end = $start + mb_strlen($window);
            if ($end < $length) {
                $cut = $this->boundary($window);
                if ($cut > 0) {
                    $end = $start + $cut;
                }
            }
            if ($end <= $start) {
                throw new \LogicException('Chunk planner made no progress.');
            }
            $body = mb_substr($text, $start, $end - $start);
            $chunks[] = [
                'start_offset' => $base + $start, 'end_offset' => $base + $end,
                'start_page' => $firstPage + substr_count(mb_substr($text, 0, $start), "\f"),
                'end_page' => $firstPage + substr_count(mb_substr($text, 0, $end), "\f"),
                'token_count' => max(1, (int) ceil(strlen($body) * $tokensPerByte)), 'input_hash' => hash('sha256', $body),
                'overlap_chars' => max(0, $previousEnd - $start),
            ];
            if ($end === $length) {
                break;
            }
            $previousEnd = $end;
            $start = max($start + 1, $end - $overlapLimit);
        }

        return $chunks;
    }

    private function boundary(string $window): int
    {
        $minimum = (int) (mb_strlen($window) * 0.65);
        // Prefer headings, then pages, blocks (paragraphs/table groups), sentences, then words.
        // Do not assume any of them exist.
        foreach (['/\n(?=(?:[A-Z][A-Z \d:.-]{5,100}|\d+(?:\.\d+)*\.?[ \t]+[A-Z][^\n]{2,100})\n)/u', '/\f/u', '/\n[ \t]*\n/u',
            '/[.!?][ \t]+(?=[A-Z])/u', '/\s+/u'] as $pattern) {
            preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE);
            foreach (array_reverse($matches[0]) as [$match, $offset]) {
                $position = mb_strlen(substr($window, 0, $offset + strlen($match)));
                if ($position >= $minimum) {
                    return $position;
                }
            }
        }

        return mb_strlen($window);
    }
}
