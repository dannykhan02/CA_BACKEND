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
