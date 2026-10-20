<?php

namespace App\Services\Intelligence;

use DomainException;
use JsonException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** A small, literal window over already extracted XLSX nodes; never opens a workbook. */
class XlsxCellWindowBuilder
{
    private const RADIUS = 3;

    private const MAX_RESPONSE_BYTES = 65536;

    private const MAX_FIELD_BYTES = 1024;

    private const MAX_MERGES = 16;

    public function build(array $nodes, array $source, string $text): array
    {
        $anchor = $this->citedCell($source);
        // No cell anchor owns this citation exactly. Resolve the cited span to
        // the cells it covers instead, when the only thing it covers outside
        // them is the extractor's separators.
        $covered = $anchor === null ? $this->coveredCells($nodes, $source, $text) : null;
        [$sheetId, $coordinate, $column, $row] = $this->path($anchor['path'] ?? $covered[0]['path'] ?? null);
        $sheet = null;
        $focus = null;
        $cells = [];
        $sheetName = $anchor['sheet'] ?? $covered[0]['sheet'] ?? null;
        if (! is_string($sheetName) || $sheetName === '') {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        $startRow = max(1, $row - self::RADIUS);
        $endRow = min(1048576, $row + self::RADIUS);
        $startColumn = max(1, $column - self::RADIUS);
        $endColumn = min(16384, $column + self::RADIUS);
        $merges = [];
        $hiddenRows = [];
        $hiddenColumns = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            if (($node['id'] ?? null) === $sheetId && ($node['type'] ?? null) === 'worksheet') {
                if ($sheet !== null || ($node['sheet'] ?? null) !== $sheetName) {
                    throw new DomainException('SOURCE_AMBIGUOUS');
                }
                $sheet = $node;
            }
            if (($node['type'] ?? null) !== 'cell' || ($node['parent'] ?? null) !== $sheetId) {
                continue;
            }
            if (($node['sheet'] ?? null) !== $sheetName) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $meta = $node['meta'] ?? null;
            if (! is_array($meta) || ! is_array($meta['merges'] ?? null)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $cellCoordinate = $meta['coordinate'] ?? null;
            if (! is_string($cellCoordinate) || ($node['id'] ?? null) !== $sheetId.'/cell:'.$cellCoordinate
                || ($node['path'] ?? null) !== $sheetId.'!'.$cellCoordinate) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            [$cellColumn, $cellRow] = $this->coordinate($cellCoordinate);
            if ($cellCoordinate === $coordinate) {
                if ($focus !== null) {
                    throw new DomainException('SOURCE_AMBIGUOUS');
                }
                $focus = $node;
            }
            foreach ($meta['merges'] as $range) {
                if (! is_string($range) || strlen($range) > 32) {
                    throw new DomainException('SOURCE_UNAVAILABLE');
                }
                [$first, $last] = array_pad(explode(':', $range, 2), 2, null);
                if ($last === null) {
                    throw new DomainException('SOURCE_UNAVAILABLE');
                }
                [$c1, $r1] = $this->coordinate($first);
                [$c2, $r2] = $this->coordinate($last);
                if ($c1 > $c2 || $r1 > $r2) {
                    throw new DomainException('SOURCE_UNAVAILABLE');
                }
                if ($c1 <= $endColumn && $c2 >= $startColumn && $r1 <= $endRow && $r2 >= $startRow) {
                    $merges[$range] = true;
                    if (count($merges) > self::MAX_MERGES) {
                        throw new DomainException('SOURCE_TOO_COMPLEX');
                    }
                }
            }
            if ($cellRow < $startRow || $cellRow > $endRow
                || $cellColumn < $startColumn || $cellColumn > $endColumn) {
                continue;
            }
            if (isset($cells[$cellCoordinate])) {
                throw new DomainException('SOURCE_AMBIGUOUS');
            }
            $this->verifyProjection($node, $text);
            $cells[$cellCoordinate] = $this->cell($node);
            if (($node['meta']['row_visible'] ?? null) === false
                || ($node['meta']['row_visible_after_filter'] ?? null) === false) {
                $hiddenRows[$cellRow] = true;
            }
            if (($node['meta']['column_visible'] ?? null) === false) {
                $hiddenColumns[$cellColumn] = true;
            }
        }
        if ($sheet === null || $focus === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $this->verifyProjection($focus, $text);
        if ($anchor !== null) {
            $range = $anchor['range'] ?? null;
            if (! is_array($range) || ($focus['start'] ?? null) !== ($range['start'] ?? null)
                || ($focus['end'] ?? null) !== ($range['end'] ?? null)
                || ($focus['meta']['coordinate'] ?? null) !== ($anchor['metadata']['coordinate'] ?? null)
                || ($focus['meta']['sheet_visibility'] ?? null) !== ($sheet['meta']['visibility'] ?? null)
                || ($anchor['metadata']['sheet_visibility'] ?? null) !== ($sheet['meta']['visibility'] ?? null)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
        } elseif (($focus['meta']['sheet_visibility'] ?? null) !== ($sheet['meta']['visibility'] ?? null)) {
            // The window centre came from the pinned extraction rather than
            // from an anchor, so the cell and its worksheet must still agree.
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        $rows = [];
        for ($r = $startRow; $r <= $endRow; $r++) {
            $rowCells = [];
            for ($c = $startColumn; $c <= $endColumn; $c++) {
                $at = Coordinate::stringFromColumnIndex($c).$r;
                $rowCells[] = $cells[$at] ?? ['coordinate' => $at, 'present' => false];
            }
            $rows[] = ['row' => $r, 'cells' => $rowCells];
        }
        $hiddenRows = array_keys($hiddenRows);
        $hiddenColumns = array_keys($hiddenColumns);
        sort($hiddenRows);
        sort($hiddenColumns);
        $result = [
            'sheet' => ['id' => $sheetId, 'name' => $sheetName,
                'visibility' => $sheet['meta']['visibility'] ?? null,
                'dateSystem' => $sheet['meta']['date_system'] ?? null],
            'focus' => ['coordinate' => $coordinate, 'row' => $row, 'column' => $column],
            'window' => ['startRow' => $startRow, 'endRow' => $endRow,
                'startColumn' => $startColumn, 'endColumn' => $endColumn,
                'truncated' => ['top' => $startRow > $row - self::RADIUS,
                    'bottom' => $endRow < $row + self::RADIUS,
                    'left' => $startColumn > $column - self::RADIUS,
                    'right' => $endColumn < $column + self::RADIUS],
                'rows' => $rows],
            'mergedRanges' => array_keys($merges),
            'hiddenRows' => $hiddenRows, 'hiddenColumns' => $hiddenColumns,
            'citation' => ['matchState' => $source['matchState'],
                'conflictStatus' => $source['quality']['conflictStatus'] ?? null,
                'resolution' => $covered === null ? 'EXACT_CELL_ANCHOR' : 'OVERLAPPED_CELLS',
                'coversMultipleCells' => $covered !== null && count($covered) > 1,
                // Every cell the citation covers, in document order, the focus
                // first. A reader may highlight all of them; the window is
                // centred on the first, so a wide citation can reach past it.
                'coveredCells' => $covered === null ? [$coordinate]
                    : array_map(static fn (array $cell): string => $cell['meta']['coordinate'], $covered)],
        ];
        try {
            if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > self::MAX_RESPONSE_BYTES) {
                throw new DomainException('SOURCE_TOO_COMPLEX');
            }
        } catch (JsonException) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        return $result;
    }

    /** @return array<string, mixed>|null the one cell anchor that owns this citation, or null if no anchor does */
    private function citedCell(array $source): ?array
    {
        $range = $source['source']['range'] ?? null;
        $values = $source['roles']['value'] ?? [];
        if (! is_array($range) || ! is_int($range['start'] ?? null) || ! is_int($range['end'] ?? null)
            || $range['end'] <= $range['start'] || ! is_array($values)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $candidates = [];
        foreach ([$source['location'] ?? null, ...$values] as $anchor) {
            if (! is_array($anchor) || ! in_array($anchor['type'] ?? null, ['cell', 'worksheet_cell'], true)
                || ($anchor['modality'] ?? null) !== 'xlsx') {
                continue;
            }
            $anchorRange = $anchor['range'] ?? null;
            if (! is_array($anchorRange) || ! is_int($anchorRange['start'] ?? null)
                || ! is_int($anchorRange['end'] ?? null)
                || $anchorRange['start'] < $range['start'] || $anchorRange['end'] > $range['end']) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $path = $anchor['path'] ?? null;
            if (! is_string($path)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            if (isset($candidates[$path]) && ($candidates[$path]['id'] !== ($anchor['id'] ?? null)
                || $candidates[$path]['range'] !== $anchorRange
                || $candidates[$path]['sheet'] !== ($anchor['sheet'] ?? null))) {
                throw new DomainException('SOURCE_AMBIGUOUS');
            }
            $candidates[$path] = $anchor;
        }
        if (count($candidates) > 1) {
            throw new DomainException('SOURCE_AMBIGUOUS');
        }

        return $candidates === [] ? null : array_values($candidates)[0];
    }

    /**
     * The cells of one sheet that the cited span covers whole, in document
     * order. Separator and whitespace characters may sit between and around
     * them; one real character of text outside a cell, one cell cut in half,
     * or two sheets in the same span, and there is no verified window.
     *
     * The cells come from the structured extraction the revision pins by
     * primary key — the same rows the published `worksheet_cell` anchors were
     * produced from, and complete, because the reader has already refused a
     * truncated or partial extraction.
     *
     * @return list<array<string, mixed>>
     */
    private function coveredCells(array $nodes, array $source, string $text): array
    {
        $range = $source['source']['range'] ?? null;
        if (! is_array($range) || ! is_int($range['start'] ?? null) || ! is_int($range['end'] ?? null)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $candidates = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            if (($node['type'] ?? null) === 'cell' && isset($node['start'], $node['end'])
                && is_string($node['sheet'] ?? null) && is_string($node['meta']['coordinate'] ?? null)) {
                $candidates[] = $node;
            }
        }
        $covered = app(StructuralSpanOverlap::class)->cover($candidates, $range['start'], $range['end'], $text);
        if ($covered === null
            || count(array_unique(array_column($covered, 'sheet'))) !== 1
            || count(array_unique(array_column($covered, 'parent'))) !== 1) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        return $covered;
    }

    private function path(mixed $path): array
    {
        if (! is_string($path) || ! preg_match('/^(sheet:(?:0|[1-9][0-9]{0,2}))!([A-Z]{1,3}[1-9][0-9]{0,6})$/D', $path, $matches)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        [$column, $row] = $this->coordinate($matches[2]);

        return [$matches[1], $matches[2], $column, $row];
    }

    private function coordinate(string $coordinate): array
    {
        if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $coordinate, $matches)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        try {
            $column = Coordinate::columnIndexFromString($matches[1]);
        } catch (\Throwable) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $row = (int) $matches[2];
        if ($column > 16384 || $row > 1048576) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        return [$column, $row];
    }

    private function verifyProjection(array $node, string $text): void
    {
        if (! isset($node['start'], $node['end'])) {
            return;
        }
        if (! is_int($node['start']) || ! is_int($node['end']) || $node['end'] <= $node['start']
            || mb_substr($text, $node['start'], $node['end'] - $node['start']) !== ($node['text'] ?? null)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
    }

    private function cell(array $node): array
    {
        $meta = $node['meta'] ?? [];
        if (! is_array($meta) || ! is_array($meta['merges'] ?? [])) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $fields = ['rawValue' => $meta['raw'] ?? null, 'displayValue' => $meta['display'] ?? null,
            'formula' => $meta['formula'] ?? null, 'cachedResult' => $meta['cached_result'] ?? null,
            'numberFormat' => $meta['number_format'] ?? null];
        foreach ($fields as $value) {
            if (! is_null($value) && ! is_scalar($value)) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            if (is_string($value) && strlen($value) > self::MAX_FIELD_BYTES) {
                throw new DomainException('SOURCE_TOO_COMPLEX');
            }
        }
        if (count($meta['merges']) > self::MAX_MERGES) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }

        return ['coordinate' => $meta['coordinate'], 'present' => true,
            ...$fields, 'cellType' => $meta['cell_type'] ?? null,
            'cachePresent' => $meta['cache_present'] ?? false,
            'richText' => $meta['rich_text'] ?? false,
            'rowVisible' => $meta['row_visible'] ?? null,
            'rowVisibleAfterFilter' => $meta['row_visible_after_filter'] ?? null,
            'columnVisible' => $meta['column_visible'] ?? null,
            'mergedRanges' => $meta['merges']];
    }
}
