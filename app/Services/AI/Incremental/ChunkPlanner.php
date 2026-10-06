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
        // Balanced partitions, exactly as partition() does, so no tiny trailing chunk is created.
        $count = max(1, (int) ceil($total / max(1, $target)));
        $limit = max(1, (int) ceil($total / $count));
        $overlapLimit = $overlap
            ? min((int) (config('document_intelligence.chunk_overlap_tokens') * 1), (int) ($limit * 0.15))
            : 0;
        $chunks = [];
        $cursor = 0;
        $previousEnd = 0;
        $spanCount = count($all);
        while ($cursor < $spanCount) {
            $first = $cursor;
            $used = 0;
            // A single span heavier than the budget still forms one chunk: splitting it would
            // break the one guarantee this planner exists to provide.
            while ($cursor < $spanCount && ($used === 0 || $used + $weights[$cursor] <= $limit)) {
                $used += $weights[$cursor];
                $cursor++;
            }
            $last = $cursor - 1;
            $start = $all[$first]['start_offset'];
            $end = $all[$last]['end_offset'];
            $chunks[] = [
                'start_offset' => $start, 'end_offset' => $end,
                'start_page' => $all[$first]['page'], 'end_page' => $all[$last]['page'],
                'token_count' => $used,
                'input_hash' => hash('sha256', mb_substr($text, $start, $end - $start)),
                'overlap_chars' => max(0, $previousEnd - $start),
            ];
            if ($cursor >= $spanCount) {
                break;
            }
            $previousEnd = $end;
            // Carry whole trailing spans into the next chunk for cross-boundary context.
            $back = 0;
            while ($cursor - 1 > $first && $back + $weights[$cursor - 1] <= $overlapLimit) {
                $back += $weights[$cursor - 1];
                $cursor--;
            }
        }

        return $chunks;
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
