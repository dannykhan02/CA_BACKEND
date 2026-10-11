<?php

namespace Tests\Unit\Services;

use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\StructuredChunkPlanner;
use App\Services\Extraction\StructuredDocument;
use Tests\TestCase;

class StructuredChunkPlannerTest extends TestCase
{
    private function source(): array
    {
        $lines = ['Preamble text with enough length to fill a chunk.',
            'Metric | FY2024 | FY2025', 'Revenue | 10.2 | 12.4', 'Costs | 7.1 | 8.0'];
        $text = implode("\n", $lines);
        $nodes = [['id' => 'body', 'type' => 'document'],
            ['id' => 'body/tbl[1]', 'type' => 'table', 'parent' => 'body']];
        $spans = [];
        $at = 0;
        foreach ($lines as $i => $line) {
            $spans[] = ['ordinal' => $i, 'key' => 'E'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'page' => null, 'start_offset' => $at, 'end_offset' => $at + mb_strlen($line),
                'type' => $i === 0 ? 'paragraph' : 'table_row'];
            if ($i > 0) {
                $nodes[] = ['id' => 'body/tbl[1]/tr['.$i.']/tc[1]', 'type' => 'cell',
                    'parent' => 'body/tbl[1]', 'start' => $at, 'end' => $at + mb_strlen($line),
                    'text' => $line];
            }
            $at += mb_strlen($line) + 1;
        }

        return [new StructuredDocument('DOCX', 'fixture.v1', $text, $nodes, []),
            new EvidenceSpanSet('fixture.v1', $text, $spans)];
    }

    public function test_small_table_stays_in_one_source_backed_chunk(): void
    {
        [$source, $spans] = $this->source();
        $chunks = app(StructuredChunkPlanner::class)->plan($source, $spans, 35);
        self::assertCount(2, $chunks);
        self::assertSame($spans->all()[1]['start_offset'], $chunks[1]['start_offset']);
        self::assertSame($spans->all()[3]['end_offset'], $chunks[1]['end_offset']);
        self::assertSame(hash('sha256', mb_substr($source->text, $chunks[1]['start_offset'],
            $chunks[1]['end_offset'] - $chunks[1]['start_offset'])), $chunks[1]['input_hash']);
        self::assertSame(0, $chunks[1]['overlap_chars']);
    }

    public function test_large_table_splits_deterministically_at_source_span_boundaries(): void
    {
        [$source, $spans] = $this->source();
        $planner = app(StructuredChunkPlanner::class);
        $first = $planner->plan($source, $spans, 20);
        self::assertSame($first, $planner->plan($source, $spans, 20));
        self::assertGreaterThan(2, count($first));
        foreach ($first as $chunk) {
            self::assertLessThanOrEqual(20, $chunk['token_count']);
            self::assertContains($chunk['start_offset'], array_column($spans->all(), 'start_offset'));
            self::assertContains($chunk['end_offset'], array_column($spans->all(), 'end_offset'));
        }
    }

    public function test_native_xlsx_table_keeps_rows_together_and_splits_without_duplicate_offsets(): void
    {
        $lines = ['=== Sheet: Results ===', 'Metric | FY2025', 'Revenue | 12.4', 'Costs | 8.0'];
        $text = implode("\n", $lines);
        $nodes = [['id' => 'sheet:0', 'type' => 'worksheet', 'sheet' => 'Results'],
            ['id' => 'sheet:0/table:Main', 'type' => 'table', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'meta' => ['range' => 'A1:B3', 'header_row' => true]]];
        $spans = [];
        $cursor = 0;
        foreach ($lines as $i => $line) {
            $spans[] = ['ordinal' => $i, 'key' => 'E'.($i + 1), 'page' => null,
                'start_offset' => $cursor, 'end_offset' => $cursor + mb_strlen($line),
                'type' => $i === 0 ? 'heading' : 'table_row'];
            if ($i > 0) {
                $cell = 'A'.$i;
                $nodes[] = ['id' => 'sheet:0/cell:'.$cell, 'type' => 'cell',
                    'parent' => 'sheet:0', 'sheet' => 'Results',
                    'start' => $cursor, 'end' => $cursor + mb_strlen($line),
                    'text' => $line, 'meta' => ['coordinate' => $cell,
                        'merges' => $i === 1 ? ['A1:B1'] : []]];
            }
            $cursor += mb_strlen($line) + 1;
        }
        $source = new StructuredDocument('XLSX', 'fixture.v1', $text, $nodes, []);
        $set = new EvidenceSpanSet('fixture.v1', $text, $spans);
        $planner = app(StructuredChunkPlanner::class);
        $small = $planner->plan($source, $set, 25);
        self::assertSame($spans[1]['start_offset'], $small[1]['start_offset']);
        self::assertSame($spans[3]['end_offset'], $small[1]['end_offset']);
        $split = $planner->plan($source, $set, 17);
        self::assertSame(0, array_sum(array_column($split, 'overlap_chars')));
        self::assertSame($split, $planner->plan($source, $set, 17));
        foreach ($split as $chunk) {
            self::assertLessThanOrEqual(17, $chunk['token_count']);
        }
    }
}
