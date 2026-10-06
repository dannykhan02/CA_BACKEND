<?php

namespace Tests\Unit;

use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\SourceSpanBuilder;
use Tests\TestCase;

/**
 * Span-aligned partitioning. The guarantee under test is twofold: an evidence span is never cut,
 * and labeling the source never costs more provider calls than the packing itself requires.
 */
class SpanChunkPlannerTest extends TestCase
{
    private function source(int $pages = 20): string
    {
        $page = fn (int $n) => "{$n}. OPERATIONAL REVIEW\n\n{$n}.1 Commitments\n\n"
            .'The Bank approved thirty-seven sovereign operations during the 2024 financial year, a modest increase. '
            .'Total commitments reached USD 12.4 billion, compared with USD 10.1 billion in 2023. '
            ."Private-sector financing increased substantially against a demanding external backdrop.\n\n"
            ."Item                 2024        2023\nRevenue              6.4         5.9\n"
            ."Total assets         42.1        39.8\nNet income           0.9         0.7\n\n"
            ."- The borrower shall deliver audited financial statements within one hundred and eighty days.\n"
            ."- The facility matures on 2025-03-31 unless extended by the Board.\n\f";
        $text = '';
        for ($index = 1; $index <= $pages; $index++) {
            $text .= $page($index);
        }

        return $text;
    }

    private function spans(string $text): EvidenceSpanSet
    {
        return new EvidenceSpanSet('version', $text, app(SourceSpanBuilder::class)->build($text, 1));
    }

    /** Token weight of the labeled form the provider actually receives. */
    private function weight(EvidenceSpanSet $spans, string $text): int
    {
        $total = 0;
        foreach ($spans->all() as $span) {
            $bytes = strlen(mb_substr($text, $span['start_offset'], $span['end_offset'] - $span['start_offset']));
            $total += max(1, (int) ceil(($bytes + strlen($span['key']) + 5) / 3));
        }

        return $total;
    }

    public function test_chunk_count_is_the_fewest_partitions_of_the_labeled_source(): void
    {
        $text = $this->source();
        $spans = $this->spans($text);
        $planner = app(ChunkPlanner::class);

        foreach ([2000, 4000, 8000, 14000, 60000] as $target) {
            $chunks = $planner->planFromSpans($spans, $text, $target);
            $fewest = (int) ceil($this->weight($spans, $text) / $target);
            // Carrying overlap and rounding each span up must not cost an extra provider call.
            self::assertSame($fewest, count($chunks), 'target '.$target.' inflated the chunk count');
        }
    }

    public function test_no_chunk_boundary_falls_inside_an_evidence_span(): void
    {
        $text = $this->source();
        $spans = $this->spans($text);
        $starts = array_column($spans->all(), 'start_offset');
        $ends = array_column($spans->all(), 'end_offset');

        foreach (app(ChunkPlanner::class)->planFromSpans($spans, $text, 4000) as $chunk) {
            self::assertContains($chunk['start_offset'], $starts);
            self::assertContains($chunk['end_offset'], $ends);
        }
    }

    public function test_every_span_is_planned_exactly_once_when_overlap_is_disabled(): void
    {
        $text = $this->source();
        $spans = $this->spans($text);
        $seen = [];
        foreach (app(ChunkPlanner::class)->planFromSpans($spans, $text, 3000, false) as $chunk) {
            $seen = [...$seen, ...$spans->forRange($chunk['start_offset'], $chunk['end_offset'])->keys()];
        }

        self::assertSame($spans->keys(), $seen);
    }

    public function test_overlap_is_carried_as_whole_spans_only(): void
    {
        $text = $this->source();
        $spans = $this->spans($text);
        $chunks = app(ChunkPlanner::class)->planFromSpans($spans, $text, 3000);
        self::assertGreaterThan(1, count($chunks));

        $overlapped = 0;
        for ($index = 1; $index < count($chunks); $index++) {
            $overlapped += $chunks[$index]['overlap_chars'] > 0 ? 1 : 0;
            // An overlapping start is still a span start, never a mid-span offset.
            self::assertContains($chunks[$index]['start_offset'], array_column($spans->all(), 'start_offset'));
        }
        self::assertGreaterThan(0, $overlapped, 'cross-boundary context was dropped entirely');
    }

    public function test_the_input_hash_describes_the_raw_slice_not_the_labeled_form(): void
    {
        $text = $this->source(4);
        $spans = $this->spans($text);

        foreach (app(ChunkPlanner::class)->planFromSpans($spans, $text, 3000) as $chunk) {
            $raw = mb_substr($text, $chunk['start_offset'], $chunk['end_offset'] - $chunk['start_offset']);
            self::assertSame(hash('sha256', $raw), $chunk['input_hash']);
        }
    }

    public function test_a_single_oversized_span_still_forms_one_chunk_rather_than_being_cut(): void
    {
        // One table row far larger than the budget: cutting it would split a row label from its
        // values, which is exactly what span alignment exists to prevent.
        $text = 'Revenue | '.str_repeat('2024: 6.4 | ', 400)."end\n";
        $spans = $this->spans($text);
        self::assertCount(1, $spans->all());

        $chunks = app(ChunkPlanner::class)->planFromSpans($spans, $text, 100);
        self::assertCount(1, $chunks);
        self::assertSame($spans->all()[0]['start_offset'], $chunks[0]['start_offset']);
        self::assertSame($spans->all()[0]['end_offset'], $chunks[0]['end_offset']);
    }

    public function test_pages_are_carried_from_the_first_and_last_span_of_each_chunk(): void
    {
        $text = $this->source();
        $spans = $this->spans($text);

        foreach (app(ChunkPlanner::class)->planFromSpans($spans, $text, 3000) as $chunk) {
            $covered = $spans->forRange($chunk['start_offset'], $chunk['end_offset'])->all();
            self::assertSame($covered[0]['page'], $chunk['start_page']);
            self::assertSame(end($covered)['page'], $chunk['end_page']);
        }
    }

    public function test_an_empty_span_set_plans_nothing(): void
    {
        self::assertSame([], app(ChunkPlanner::class)->planFromSpans($this->spans('   '), '   ', 1000));
    }
}
