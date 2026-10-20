<?php

namespace Tests\Unit;

use App\Services\Intelligence\StructuralSpanOverlap;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * The one rule shared by the XLSX cell window and the DOCX structure window.
 * Everything the span covers outside the nodes it covers whole must be
 * separator or whitespace characters, or it is not a citation of those nodes.
 */
class StructuralSpanOverlapTest extends TestCase
{
    private const TEXT = "Alpha | Beta | Gamma\nDelta |  |  | \nEpsilon";

    /** @return list<array<string, mixed>> */
    private function nodes(): array
    {
        $nodes = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $word) {
            $at = mb_strpos(self::TEXT, $word);
            $nodes[] = ['id' => $word, 'start' => $at, 'end' => $at + mb_strlen($word), 'text' => $word];
        }

        return $nodes;
    }

    private function cover(int $start, int $end): ?array
    {
        return (new StructuralSpanOverlap)->cover($this->nodes(), $start, $end, self::TEXT);
    }

    private function at(string $word): array
    {
        $at = mb_strpos(self::TEXT, $word);

        return [$at, $at + mb_strlen($word)];
    }

    public function test_an_exact_span_covers_its_one_node(): void
    {
        [$start, $end] = $this->at('Beta');
        self::assertSame(['Beta'], array_column((array) $this->cover($start, $end), 'id'));
    }

    public function test_trailing_separators_of_empty_cells_are_not_text_outside_the_cell(): void
    {
        [$start] = $this->at('Delta');
        $line = mb_strpos(self::TEXT, "Delta |  |  | \n");
        $end = $line + mb_strlen(rtrim('Delta |  |  | '));
        self::assertSame(['Delta'], array_column((array) $this->cover($start, $end), 'id'));
    }

    public function test_several_nodes_joined_by_separators_come_back_in_document_order(): void
    {
        $start = $this->at('Alpha')[0];
        $end = $this->at('Gamma')[1];
        self::assertSame(['Alpha', 'Beta', 'Gamma'], array_column((array) $this->cover($start, $end), 'id'));
    }

    public function test_a_row_break_between_nodes_is_a_separator_too(): void
    {
        $start = $this->at('Gamma')[0];
        $end = $this->at('Delta')[1];
        self::assertSame(['Gamma', 'Delta'], array_column((array) $this->cover($start, $end), 'id'));
    }

    public function test_real_text_outside_every_node_is_refused(): void
    {
        $text = "Alpha | Beta and a loose remark\n";
        $nodes = [['id' => 'Alpha', 'start' => 0, 'end' => 5, 'text' => 'Alpha'],
            ['id' => 'Beta', 'start' => 8, 'end' => 12, 'text' => 'Beta']];
        self::assertNull((new StructuralSpanOverlap)->cover($nodes, 0, mb_strlen($text) - 1, $text));
    }

    public function test_a_node_the_span_only_partly_covers_is_refused(): void
    {
        [$start, $end] = $this->at('Beta');
        self::assertNull($this->cover($start + 1, $end));
        self::assertNull($this->cover($start, $end - 1));
        self::assertNull($this->cover($this->at('Alpha')[0] + 2, $end));
    }

    public function test_a_span_that_touches_no_node_is_refused(): void
    {
        $gap = $this->at('Alpha')[1];
        self::assertNull($this->cover($gap, $gap + 3));
        self::assertNull($this->cover(0, 0));
    }

    public function test_overlapping_candidates_are_refused_rather_than_double_counted(): void
    {
        $nodes = [['id' => 'outer', 'start' => 0, 'end' => 20, 'text' => mb_substr(self::TEXT, 0, 20)],
            ['id' => 'inner', 'start' => 8, 'end' => 12, 'text' => 'Beta']];
        self::assertNull((new StructuralSpanOverlap)->cover($nodes, 0, 20, self::TEXT));
    }

    public function test_a_node_whose_projection_moved_is_a_refusal_not_a_quiet_skip(): void
    {
        $nodes = [['id' => 'Beta', 'start' => 8, 'end' => 12, 'text' => 'Beeta']];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_UNAVAILABLE');
        (new StructuralSpanOverlap)->cover($nodes, 8, 12, self::TEXT);
    }

    public function test_a_citation_over_more_nodes_than_the_cap_is_reported_as_too_complex(): void
    {
        $text = str_repeat('x | ', StructuralSpanOverlap::MAX_NODES + 1);
        $nodes = [];
        for ($i = 0; $i <= StructuralSpanOverlap::MAX_NODES; $i++) {
            $nodes[] = ['id' => 'n'.$i, 'start' => $i * 4, 'end' => $i * 4 + 1, 'text' => 'x'];
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_TOO_COMPLEX');
        (new StructuralSpanOverlap)->cover($nodes, 0, mb_strlen($text), $text);
    }
}
