<?php

namespace App\Services\Intelligence;

use DomainException;

/** Builds a small structural window from the stored WordprocessingML projection. */
class DocxWindowBuilder
{
    private const RADIUS = 3;

    private const MAX_BLOCK_CHARS = 4096;

    private const MAX_COLUMNS = 16;

    public function build(array $nodes, array $source, string $text): array
    {
        $range = $source['source']['range'];
        $start = $range['start'];
        $end = $range['end'];
        $byId = [];
        $body = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)
                || isset($byId[$node['id']]) || ! is_string($node['type'] ?? null)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $byId[$node['id']] = $node;
            if (in_array($node['type'], ['paragraph', 'section_heading', 'table', 'row', 'cell'], true)) {
                $part = match ($node['type']) {
                    'paragraph', 'section_heading' => 'p', 'table' => 'tbl', 'row' => 'tr', 'cell' => 'tc',
                };
                if (($node['path'] ?? null) !== $node['id']
                    || ! preg_match('~^body(?:/(?:tbl|tr|tc)\[[1-9][0-9]*\])*/'.$part.'\[[1-9][0-9]*\]$~D', $node['id'])
                    || ($node['parent'] ?? null) !== substr($node['id'], 0, strrpos($node['id'], '/'))) {
                    throw new DomainException('SOURCE_UNAVAILABLE');
                }
            }
            if (($node['parent'] ?? null) === 'body'
                && in_array($node['type'], ['paragraph', 'section_heading', 'table'], true)) {
                $body[] = $node['id'];
            }
        }
        $containing = [];
        foreach ($byId as $node) {
            if (! in_array($node['type'], ['paragraph', 'section_heading', 'cell'], true)
                || ! isset($node['start'], $node['end'])
                || $node['start'] > $start || $node['end'] < $end) {
                continue;
            }
            $this->verify($node, $text);
            $containing[] = $node;
        }
        // A cell is the meaningful unit for a table citation, even when its
        // contained paragraph has the same projection. Otherwise choose the
        // smallest exact enclosing paragraph or heading.
        usort($containing, static fn (array $a, array $b): int => (($a['type'] === 'cell' ? 0 : 1) <=> ($b['type'] === 'cell' ? 0 : 1))
            ?: (($a['end'] - $a['start']) <=> ($b['end'] - $b['start'])));
        $target = $containing[0] ?? null;
        if (isset($containing[1]) && $target['type'] === $containing[1]['type']
            && $target['start'] === $containing[1]['start'] && $target['end'] === $containing[1]['end']) {
            throw new DomainException('SOURCE_AMBIGUOUS');
        }
        $rowQuote = false;
        $covered = null;
        if ($target === null) {
            // No single block contains the citation exactly. Resolve the cited
            // span to the blocks it covers whole instead, when the only thing
            // it covers outside them is the extractor's separators: " | "
            // after each cell, "\n" after each row and paragraph.
            [$target, $rowQuote, $covered] = $this->overlapped($byId, $source, $text, $start, $end);
        }
        $targetBlock = $rowQuote
            ? ['id' => $target['id'], 'type' => 'table_row', 'text' => $source['source']['text'],
                'range' => $range]
            : $this->block($target, $text);
        $tableId = $this->ancestor($target, 'table', $byId);
        if ($tableId !== null) {
            $rowId = $target['type'] === 'row' ? $target['id'] : $this->ancestor($target, 'row', $byId);
            if ($rowId === null) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $table = $this->table($tableId, $rowId, $target['id'], $byId, $text);
            $bodyIndex = array_search($tableId, $body, true);
            $heading = $this->heading($byId[$tableId], $body, $bodyIndex, $byId, $text);

            return ['context' => ['sectionHeading' => $heading, 'before' => [],
                'target' => $targetBlock, 'after' => []], 'table' => $table,
                'citation' => $this->citation($covered, $target['id'])];
        }
        $index = array_search($target['id'], $body, true);
        if ($index === false) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $heading = $this->heading($target, $body, $index, $byId, $text);
        $before = [];
        $after = [];
        $list = $this->listId($target);
        for ($i = $index - 1; $i >= 0 && count($before) < self::RADIUS; $i--) {
            $neighbor = $byId[$body[$i]];
            if ($neighbor['id'] === ($heading['id'] ?? null)) {
                break;
            }
            if ($list !== null && $this->listId($neighbor) !== $list) {
                break;
            }
            if ($neighbor['type'] === 'table') {
                break;
            }
            if (! isset($neighbor['text'])) {
                continue;
            }
            array_unshift($before, $this->block($neighbor, $text));
        }
        for ($i = $index + 1; $i < count($body) && count($after) < self::RADIUS; $i++) {
            $neighbor = $byId[$body[$i]];
            if ($list !== null && $this->listId($neighbor) !== $list) {
                break;
            }
            if ($neighbor['type'] === 'table') {
                break;
            }
            if (! isset($neighbor['text'])) {
                continue;
            }
            $after[] = $this->block($neighbor, $text);
        }

        return ['context' => ['sectionHeading' => $heading, 'before' => $before,
            'target' => $targetBlock, 'after' => $after], 'table' => null,
            'citation' => $this->citation($covered, $target['id'])];
    }

    /**
     * The blocks the cited span covers, when no single block contains it.
     *
     * Table cells are tried first, because a cell is the meaningful unit for a
     * table citation and a row quote is cells plus their separators. Body
     * blocks are tried next, which is how a span that ends on the "\n" after
     * its paragraph, or that covers a heading and the short paragraphs under
     * it, reaches the structure it was cut from (SourceSpanBuilder groups a
     * whole prose block, so one normal-route span routinely spans several).
     *
     * A paragraph inside a cell is never a candidate here: a table citation is
     * owned by its cells, not by the paragraph the cell happens to hold.
     *
     * @return array{array<string, mixed>, bool, list<array<string, mixed>>}
     */
    private function overlapped(array $byId, array $source, string $text, int $start, int $end): array
    {
        $overlap = app(StructuralSpanOverlap::class);
        $cells = $overlap->cover(array_values(array_filter($byId,
            static fn (array $node): bool => $node['type'] === 'cell' && isset($node['start'], $node['end']))),
            $start, $end, $text);
        if ($cells !== null) {
            if (count(array_unique(array_column($cells, 'parent'))) !== 1) {
                throw new DomainException('SOURCE_UNAVAILABLE'); // Cells of more than one row.
            }
            if (count($cells) === 1) {
                return [$cells[0], false, $cells];
            }
            $row = $byId[$cells[0]['parent']] ?? null;
            if (($row['type'] ?? null) !== 'row') {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $values = $source['roles']['value'] ?? [];
            if (count($values) === 1 && ($values[0]['type'] ?? null) === 'cell') {
                $cell = $byId[$values[0]['path'] ?? ''] ?? null;
                if ($cell !== null && ($cell['parent'] ?? null) === $row['id']) {
                    return [$cell, false, $cells];
                }
            }

            return [$row, true, $cells];
        }
        $blocks = $overlap->cover(array_values(array_filter($byId,
            static fn (array $node): bool => in_array($node['type'], ['paragraph', 'section_heading'], true)
                && ($node['parent'] ?? null) === 'body' && isset($node['start'], $node['end']))),
            $start, $end, $text);
        if ($blocks === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        return [$blocks[0], false, $blocks];
    }

    /**
     * How the citation reached its target, and every block it covers. The
     * window is centred on the first, so a wide citation can cover blocks
     * outside `before`/`after`; the ids say which ones it claims.
     *
     * @param  list<array<string, mixed>>|null  $covered
     * @return array<string, mixed>
     */
    private function citation(?array $covered, string $targetId): array
    {
        return ['resolution' => $covered === null ? 'EXACT_BLOCK' : 'OVERLAPPED_BLOCKS',
            'coversMultipleBlocks' => $covered !== null && count($covered) > 1,
            'coveredBlockIds' => $covered === null ? [$targetId]
                : array_map(static fn (array $node): string => $node['id'], $covered)];
    }

    private function verify(array $node, string $text): void
    {
        if (! is_int($node['start'] ?? null) || ! is_int($node['end'] ?? null)
            || $node['start'] < 0 || $node['end'] <= $node['start'] || ! is_string($node['text'] ?? null)
            || mb_substr($text, $node['start'], $node['end'] - $node['start']) !== $node['text']) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
    }

    private function block(array $node, string $text): array
    {
        $this->verify($node, $text);
        if (mb_strlen($node['text']) > self::MAX_BLOCK_CHARS) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        $type = $node['type'] === 'section_heading' ? 'heading'
            : ($this->listId($node) !== null ? 'list_item' : $node['type']);

        return ['id' => $node['id'], 'type' => $type, 'text' => $node['text'],
            'range' => ['start' => $node['start'], 'end' => $node['end']],
            'headingLevel' => $type === 'heading' ? ($node['meta']['heading_level'] ?? null) : null,
            'listId' => $this->listId($node),
            'listLevel' => $this->listId($node) !== null ? $node['meta']['list_level'] : null];
    }

    private function listId(array $node): ?string
    {
        if (($node['type'] ?? null) !== 'paragraph') {
            return null;
        }
        $id = $node['meta']['list_id'] ?? null;
        $level = $node['meta']['list_level'] ?? null;

        return is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/D', $id)
            && is_int($level) && $level >= 0 && $level <= 8 ? $id : null;
    }

    private function ancestor(array $node, string $type, array $byId): ?string
    {
        $parent = $node['parent'] ?? null;
        for ($depth = 0; is_string($parent) && $depth < 16; $depth++) {
            $owner = $byId[$parent] ?? null;
            if ($owner === null) {
                return null;
            }
            if ($owner['type'] === $type) {
                return $parent;
            }
            $parent = $owner['parent'] ?? null;
        }

        return null;
    }

    private function heading(array $target, array $body, int|false $index, array $byId, string $text): ?array
    {
        $id = $target['meta']['section_heading_id'] ?? null;
        if (! is_string($id) && $index !== false && $target['type'] !== 'section_heading') {
            for ($i = $index - 1; $i >= 0; $i--) {
                if ($byId[$body[$i]]['type'] === 'section_heading') {
                    $id = $body[$i];
                    break;
                }
            }
        }
        if (! is_string($id) || ! isset($byId[$id]) || $byId[$id]['type'] !== 'section_heading') {
            return null;
        }

        return $this->block($byId[$id], $text);
    }

    private function table(string $tableId, string $rowId, string $targetId, array $byId, string $text): array
    {
        $rows = array_values(array_filter($byId, static fn (array $node): bool => $node['type'] === 'row' && ($node['parent'] ?? null) === $tableId));
        $focus = array_search($rowId, array_column($rows, 'id'), true);
        if ($focus === false) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $indices = [];
        foreach ($rows as $i => $row) {
            if (($row['meta']['explicit_header'] ?? false) && $i < $focus && count($indices) < 2) {
                $indices[] = $i;
            }
        }
        for ($i = max(0, $focus - 1); $i <= min(count($rows) - 1, $focus + 1); $i++) {
            $indices[] = $i;
        }
        $indices = array_values(array_unique($indices));
        sort($indices);
        $window = [];
        foreach ($indices as $i) {
            $row = $rows[$i];
            $cells = array_values(array_filter($byId, static fn (array $node): bool => $node['type'] === 'cell' && ($node['parent'] ?? null) === $row['id']));
            if (count($cells) > self::MAX_COLUMNS) {
                throw new DomainException('SOURCE_TOO_COMPLEX');
            }
            foreach ($byId as $node) {
                if ($node['type'] === 'table' && in_array($node['parent'] ?? null, array_column($cells, 'id'), true)) {
                    throw new DomainException('SOURCE_UNAVAILABLE'); // Do not flatten a nested table into a cell.
                }
            }
            $window[] = ['id' => $row['id'], 'index' => $i,
                'header' => (bool) ($row['meta']['explicit_header'] ?? false),
                'cited' => $row['id'] === $rowId,
                'cells' => array_map(function (array $cell) use ($text, $targetId): array {
                    $block = isset($cell['text']) ? $this->block($cell, $text)
                        : ['id' => $cell['id'], 'type' => 'cell', 'text' => '', 'range' => null];
                    $block['column'] = $cell['meta']['grid_column'] ?? null;
                    $block['columnSpan'] = $cell['meta']['grid_span'] ?? 1;
                    if (! is_int($block['column']) || $block['column'] < 1
                        || ! is_int($block['columnSpan']) || $block['columnSpan'] < 1
                        || $block['column'] + $block['columnSpan'] - 1 > self::MAX_COLUMNS) {
                        throw new DomainException('SOURCE_TOO_COMPLEX');
                    }
                    $block['verticalMerge'] = $cell['meta']['vertical_merge'] ?? null;
                    $block['cited'] = $cell['id'] === $targetId;

                    return $block;
                }, $cells)];
        }

        return ['id' => $tableId, 'focusRowId' => $rowId, 'rows' => $window];
    }
}
