<?php

namespace Tests\Unit\Services;

use App\Services\Intelligence\DocxWindowBuilder;
use DomainException;
use PHPUnit\Framework\TestCase;

class DocxWindowBuilderTest extends TestCase
{
    private function table(): array
    {
        $nodes = [['id' => 'body', 'type' => 'document', 'path' => 'body'],
            ['id' => 'body/tbl[1]', 'parent' => 'body', 'path' => 'body/tbl[1]', 'type' => 'table']];
        $text = '';
        for ($row = 1; $row <= 12; $row++) {
            $id = 'body/tbl[1]/tr['.$row.']';
            $nodes[] = ['id' => $id, 'parent' => 'body/tbl[1]', 'path' => $id, 'type' => 'row',
                'meta' => ['explicit_header' => $row === 1]];
            for ($col = 1; $col <= 2; $col++) {
                $value = "R{$row}C{$col}";
                $start = mb_strlen($text);
                $text .= $value.($col === 1 ? ' | ' : "\n");
                $cellId = $id.'/tc['.$col.']';
                $nodes[] = ['id' => $cellId, 'parent' => $id, 'path' => $cellId, 'type' => 'cell',
                    'text' => $value, 'start' => $start, 'end' => $start + mb_strlen($value),
                    'meta' => ['grid_column' => $col, 'grid_span' => 1, 'vertical_merge' => null]];
            }
        }

        return [$nodes, $text];
    }

    private function source(string $text, string $quote): array
    {
        $start = mb_strpos($text, $quote);

        return ['source' => ['text' => $quote, 'range' => ['start' => $start,
            'end' => $start + mb_strlen($quote)]], 'roles' => []];
    }

    public function test_table_window_has_header_one_neighbor_each_side_and_no_random_paragraphs(): void
    {
        [$nodes, $text] = $this->table();
        $result = (new DocxWindowBuilder)->build($nodes, $this->source($text, 'R8C2'), $text);
        self::assertSame([0, 6, 7, 8], array_column($result['table']['rows'], 'index'));
        self::assertSame([], $result['context']['before']);
        self::assertSame([], $result['context']['after']);
        self::assertSame('R8C2', $result['context']['target']['text']);
    }

    public function test_row_without_a_value_binding_remains_a_row(): void
    {
        [$nodes, $text] = $this->table();
        $result = (new DocxWindowBuilder)->build($nodes, $this->source($text, 'R8C1 | R8C2'), $text);
        self::assertSame('table_row', $result['context']['target']['type']);
        self::assertSame('body/tbl[1]/tr[8]', $result['context']['target']['id']);
    }

    public function test_multi_row_quote_is_not_arbitrarily_assigned_to_one_row(): void
    {
        [$nodes, $text] = $this->table();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_UNAVAILABLE');
        (new DocxWindowBuilder)->build($nodes, $this->source($text, "R8C1 | R8C2\nR9C1"), $text);
    }

    public function test_unprojected_empty_context_cell_is_explicitly_unavailable(): void
    {
        [$nodes, $text] = $this->table();
        foreach ($nodes as &$node) {
            if ($node['id'] === 'body/tbl[1]/tr[7]/tc[1]') {
                unset($node['text'], $node['start'], $node['end']);
            }
        }
        unset($node);
        $result = (new DocxWindowBuilder)->build($nodes, $this->source($text, 'R8C2'), $text);
        self::assertNull($result['table']['rows'][1]['cells'][0]['range']);
    }

    public function test_nested_table_is_refused_instead_of_flattened_into_context(): void
    {
        [$nodes, $text] = $this->table();
        $id = 'body/tbl[1]/tr[7]/tc[1]/tbl[1]';
        $nodes[] = ['id' => $id, 'path' => $id, 'parent' => 'body/tbl[1]/tr[7]/tc[1]', 'type' => 'table'];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_UNAVAILABLE');
        (new DocxWindowBuilder)->build($nodes, $this->source($text, 'R8C2'), $text);
    }
}
