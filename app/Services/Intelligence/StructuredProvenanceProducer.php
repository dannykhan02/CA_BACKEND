<?php

namespace App\Services\Intelligence;

use App\Services\Extraction\StructuredDocument;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Converts only parser-native occurrence and explicit table-header paths to Phase 3A edges. */
class StructuredProvenanceProducer
{
    public function build(StructuredDocument $source, iterable $evidence): array
    {
        $evidence = is_array($evidence) ? $evidence : iterator_to_array($evidence);
        $nodes = $this->relevantNodes($source, $evidence);
        if (count($nodes) > 20000) {
            throw new \RuntimeException('Provenance anchor limit exceeded.');
        }
        $anchors = [];
        $relations = [];
        $cells = [];
        $tables = [];
        foreach ($nodes as $node) {
            $key = $this->key($node['id']);
            $path = $node['path'] ?? $node['id'];
            $anchors[$key] = ['key' => $key,
                'kind' => match ($node['type']) {
                    'paragraph' => 'paragraph', 'table' => 'table', 'row' => 'table_row',
                    'footnote' => 'footnote',
                    'section_heading' => 'section_heading',
                    'cell' => $source->format === 'XLSX' ? 'worksheet_cell' : 'cell',
                    default => 'text_span',
                },
                'modality' => match ($source->format) {
                    'XLSX' => 'xlsx', 'DOCX' => 'docx',
                    default => ($node['meta']['modality'] ?? null) === 'ocr' ? 'ocr' : 'structured_pdf',
                },
                'path' => mb_strlen($path) <= 250 ? $path : 'sha256:'.hash('sha256', $path),
                'sheet' => $node['sheet'] ?? null, 'page' => $node['page'] ?? null];
            if ($source->format === 'XLSX' && $node['type'] === 'cell') {
                $meta = $node['meta'] ?? [];
                $anchors[$key]['navigation_metadata'] = [
                    'coordinate' => $meta['coordinate'] ?? null,
                    'merged_ranges' => array_slice($meta['merges'] ?? [], 0, 16),
                    'sheet_visibility' => $meta['sheet_visibility'] ?? 'visible',
                    'row_visible' => $meta['row_visible'] ?? true,
                    'column_visible' => $meta['column_visible'] ?? true,
                    'formula_present' => ($meta['formula'] ?? null) !== null,
                    'cache_present' => $meta['cache_present'] ?? false,
                    'value_origin' => ($meta['formula'] ?? null) !== null ? 'workbook_formula_cache' : 'literal',
                ];
            }
            if ($source->format === 'PDF' && isset($node['meta']['bbox'],
                $node['meta']['page_width'], $node['meta']['page_height'])) {
                [$x0, $y0, $x1, $y1] = $node['meta']['bbox'];
                $width = $node['meta']['page_width'];
                $height = $node['meta']['page_height'];
                if ($width > 0 && $height > 0 && $x1 > $x0 && $y1 > $y0) {
                    $anchors[$key]['geometry'] = ['system' => 'pdf_points_bottom_left',
                        'box' => [$x0, $height - $y1, $x1 - $x0, $y1 - $y0],
                        'width' => $width, 'height' => $height, 'rotation' => 0];
                }
            }
            if (isset($node['start'], $node['end'])) {
                $anchors[$key] += ['start_offset' => $node['start'], 'end_offset' => $node['end'],
                    'text' => $node['text']];
            } elseif ($node['type'] === 'footnote' && isset($node['text'])) {
                $anchors[$key]['text'] = $node['text'];
            }
            if (isset($node['parent'])) {
                $this->edge($relations, $key, $this->key($node['parent']), 'contained_by');
            }
            if (isset($node['meta']['section_heading_id'])) {
                $this->edge($relations, $key, $this->key($node['meta']['section_heading_id']), 'section_parent');
            }
            if (in_array($node['type'], ['paragraph', 'section_heading'], true)) {
                foreach ($node['meta']['footnote_ids'] ?? [] as $footnoteId) {
                    if (! isset($nodes['footnote:'.$footnoteId])) {
                        continue;
                    }
                    $this->edge($relations, $key, $this->key('footnote:'.$footnoteId), 'footnote');
                    if (($nodes[$node['parent']]['type'] ?? null) === 'cell') {
                        $this->edge($relations, $this->key($node['parent']),
                            $this->key('footnote:'.$footnoteId), 'footnote');
                    }
                }
            }
            if ($node['type'] === 'cell') {
                $cells[$node['id']] = $node;
            }
            if ($node['type'] === 'table') {
                $tables[$node['id']] = $node;
            }
        }
        if ($source->format === 'PDF') {
            foreach ($tables as $tableId => $table) {
                if (isset($table['meta']['unit_heading_id'], $nodes[$table['meta']['unit_heading_id']])) {
                    $this->edge($relations, $this->key($tableId),
                        $this->key($table['meta']['unit_heading_id']), 'caption');
                }
                if (isset($table['meta']['entity_heading_id'], $nodes[$table['meta']['entity_heading_id']])) {
                    $this->edge($relations, $this->key($tableId),
                        $this->key($table['meta']['entity_heading_id']), 'section_parent');
                }
            }
        }
        $headerEdges = [];
        $docxHeaders = [];
        $docxRowLabels = [];
        $xlsxMergeOwners = [];
        if ($source->format === 'XLSX') {
            foreach ($cells as $id => $candidate) {
                foreach ($candidate['meta']['merges'] ?? [] as $range) {
                    $bounds = Coordinate::rangeBoundaries($range);
                    if ($bounds[0][1] !== $bounds[1][1] || $bounds[1][0] - $bounds[0][0] > 255) {
                        continue;
                    }
                    for ($column = $bounds[0][0]; $column <= $bounds[1][0]; $column++) {
                        $coordinate = Coordinate::stringFromColumnIndex($column).$bounds[0][1];
                        $xlsxMergeOwners[$candidate['parent']][$coordinate] = $id;
                    }
                }
            }
        }
        if ($source->format === 'DOCX') {
            foreach ($cells as $headerId => $header) {
                $rowId = $header['parent'] ?? '';
                if (! ($nodes[$rowId]['meta']['explicit_header'] ?? false)) {
                    continue;
                }
                $tableId = $this->nearestTable($headerId, $nodes);
                $column = $header['meta']['grid_column'] ?? null;
                if ($tableId !== null && $column !== null) {
                    for ($covered = $column; $covered < $column + max(1, $header['meta']['grid_span'] ?? 1); $covered++) {
                        $docxHeaders[$tableId][$covered][] = $headerId;
                    }
                    if ($column === 1 && $this->isRowLabelHeading($header['text'] ?? '')) {
                        $docxRowLabels[$tableId] = true;
                    }
                }
            }
        }
        foreach ($cells as $id => $cell) {
            if ($source->format === 'PDF') {
                $tableId = $this->nearestTable($id, $nodes);
                if ($tableId === null) {
                    continue;
                }
                $this->edge($relations, $this->key($id), $this->key($tableId), 'table_parent');
                if (($cell['meta']['header'] ?? false) || ! is_int($cell['meta']['grid_column'] ?? null)
                    || $cell['meta']['grid_column'] < 2) {
                    continue;
                }
                $labelId = ($cell['parent'] ?? '').'/cell[0]';
                $path = $cell['meta']['header_path_ids'] ?? [];
                if (! isset($cells[$labelId]) || trim($cells[$labelId]['text'] ?? '') === ''
                    || ! is_array($path) || $path === []
                    || count(array_filter($path, static fn ($headerId) => isset($cells[$headerId]))) !== count($path)) {
                    continue;
                }
                $this->edge($relations, $this->key($id), $this->key($labelId), 'row_header');
                $headerEdges[$id]['row'][] = $labelId;
                $anchors[$this->key($labelId)]['kind'] = 'row_header';
                foreach ($path as $order => $headerId) {
                    $this->edge($relations, $this->key($id), $this->key($headerId), 'column_header', $order + 1);
                    $headerEdges[$id]['column'][] = $headerId;
                    $anchors[$this->key($headerId)]['kind'] = 'column_header';
                }
                foreach ($cell['meta']['currency_marker_ids'] ?? [] as $order => $markerId) {
                    if (! isset($cells[$markerId])) {
                        continue;
                    }
                    $this->edge($relations, $this->key($id), $this->key($markerId),
                        'column_header', 10 + $order);
                    $anchors[$this->key($markerId)]['kind'] = 'column_header';
                }

                continue;
            }
            if ($source->format === 'DOCX') {
                $tableId = $this->nearestTable($id, $nodes);
                if ($tableId === null) {
                    continue;
                }
                $this->edge($relations, $this->key($id), $this->key($tableId), 'table_parent');
                $column = $cell['meta']['grid_column'] ?? null;
                if (is_int($column)) {
                    for ($covered = $column; $covered < $column + max(1, $cell['meta']['grid_span'] ?? 1); $covered++) {
                        $columnPath = $tableId.'/column['.$covered.']';
                        $this->dimension($anchors, $relations, $id, $columnPath,
                            $tableId, 'table_column', 'docx', null);
                    }
                }
                if (! ($nodes[$cell['parent']]['meta']['explicit_header'] ?? false)) {
                    foreach ($docxHeaders[$tableId][$column] ?? [] as $headerId) {
                        if ($headerId !== $id) {
                            $this->edge($relations, $this->key($id), $this->key($headerId), 'column_header');
                            $headerEdges[$id]['column'][] = $headerId;
                            $anchors[$this->key($headerId)]['kind'] = 'column_header';
                        }
                    }
                    if ($column > 1 && isset($docxRowLabels[$tableId])) {
                        $rowLabelId = $this->docxRowLabel($cell['parent'], $cells);
                        if ($rowLabelId !== null) {
                            $this->edge($relations, $this->key($id), $this->key($rowLabelId), 'row_header');
                            $headerEdges[$id]['row'][] = $rowLabelId;
                            $anchors[$this->key($rowLabelId)]['kind'] = 'row_header';
                        }
                    }
                }

                continue;
            }
            $owners = array_filter($tables, fn (array $table): bool => $source->format === 'XLSX'
                && ($table['sheet'] ?? null) === ($cell['sheet'] ?? null)
                && $this->inRange($cell['meta']['coordinate'], $table['meta']['range']));
            if (count($owners) !== 1) {
                continue;
            }
            $tableId = array_key_first($owners);
            $table = $owners[$tableId];
            $this->edge($relations, $this->key($id), $this->key($tableId), 'table_parent');
            [$column, $row] = Coordinate::coordinateFromString($cell['meta']['coordinate']);
            $this->dimension($anchors, $relations, $id, $tableId.'/row['.$row.']',
                $tableId, 'table_row', 'xlsx', $cell['sheet'] ?? null);
            $this->dimension($anchors, $relations, $id, $tableId.'/column['.$column.']',
                $tableId, 'table_column', 'xlsx', $cell['sheet'] ?? null);
            if ($table['meta']['header_row'] ?? false) {
                $bounds = Coordinate::rangeBoundaries($table['meta']['range']);
                $headerCoordinate = $column.$bounds[0][1];
                $headerId = $xlsxMergeOwners[$table['parent']][$headerCoordinate]
                    ?? $table['parent'].'/cell:'.$headerCoordinate;
                if ($row > $bounds[0][1] && isset($cells[$headerId])) {
                    $this->edge($relations, $this->key($id), $this->key($headerId), 'column_header');
                    $headerEdges[$id]['column'][] = $headerId;
                    $anchors[$this->key($headerId)]['kind'] = 'column_header';
                }
                $firstColumn = Coordinate::stringFromColumnIndex($bounds[0][0]);
                $topLeftId = $table['parent'].'/cell:'.$firstColumn.$bounds[0][1];
                $rowLabelId = $table['parent'].'/cell:'.$firstColumn.$row;
                if ($row > $bounds[0][1] && $column !== $firstColumn
                    && $this->isRowLabelHeading($cells[$topLeftId]['text'] ?? '')
                    && isset($cells[$rowLabelId]) && trim($cells[$rowLabelId]['text'] ?? '') !== '') {
                    $this->edge($relations, $this->key($id), $this->key($rowLabelId), 'row_header');
                    $headerEdges[$id]['row'][] = $rowLabelId;
                    $anchors[$this->key($rowLabelId)]['kind'] = 'row_header';
                }
            }
        }
        $bindings = [];
        $bound = [];
        $normalizer = app(MatchingTextNormalizer::class);
        foreach ($evidence as $row) {
            $value = $row->data['value'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $foldedValue = $normalizer->fold($value);
            if ($foldedValue === '') {
                continue;
            }
            $candidates = [];
            foreach ($row->sources ?? [] as $citation) {
                if (($citation['source_graph_derived'] ?? false) === true) {
                    continue;
                }
                $from = $citation['start_offset'] ?? null;
                $to = $citation['end_offset'] ?? null;
                if (! is_int($from) || ! is_int($to) || $to <= $from) {
                    continue;
                }
                foreach ($cells as $id => $cell) {
                    if (! isset($cell['start'], $cell['end']) || $cell['start'] < $from || $cell['end'] > $to
                        || ! $this->visible($cell)
                        || $normalizer->fold($cell['text']) !== $foldedValue) {
                        continue;
                    }
                    $candidates[$id] = $cell;
                }
            }
            if (count($candidates) !== 1) {
                continue;
            }
            $id = array_key_first($candidates);
            $cell = $candidates[$id];
            if (preg_match_all('/(?<!\d)\d+(?:[,.]\d+)*/u', $cell['text']) > 1) {
                continue;
            }
            $bindings[] = ['evidence_id' => $row->id, 'anchor' => $this->key($id), 'role' => 'value'];
            foreach (['period' => ['column', 'period'], 'metric' => ['row', 'label'],
                'subject' => ['row', 'subject'], 'unit' => ['column', 'unit']] as $role => [$direction, $fieldKey]) {
                $field = $row->data[$fieldKey] ?? null;
                if (! is_string($field) || $field === '') {
                    continue;
                }
                $matches = array_values(array_filter($headerEdges[$id][$direction] ?? [],
                    static fn (string $candidate): bool => isset($cells[$candidate]['text'])
                        && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($normalizer->fold($field), '/').'(?![\p{L}\p{N}])/u',
                            $normalizer->fold($cells[$candidate]['text'])) === 1));
                if (count($matches) === 1) {
                    $bindings[] = ['evidence_id' => $row->id,
                        'anchor' => $this->key($matches[0]), 'role' => $role];
                }
            }
            $bound[$row->id] = true;
        }

        return [array_values($anchors), array_values($relations), $bindings, $bound];
    }

    private function key(string $id): string
    {
        return 'node:'.hash('sha256', $id);
    }

    private function edge(array &$edges, string $from, string $to, string $type, int $pathOrder = 0): void
    {
        $edge = compact('from', 'to', 'type');
        if ($pathOrder > 0) {
            $edge['path_order'] = $pathOrder;
        }
        $edges[$from.'|'.$to.'|'.$type] = $edge;
        if (count($edges) > 100000) {
            throw new \RuntimeException('Provenance relationship limit exceeded.');
        }
    }

    private function dimension(array &$anchors, array &$relations, string $cellId, string $path,
        string $tableId, string $kind, string $modality, ?string $sheet): void
    {
        $key = $this->key($path);
        $anchors[$key] ??= ['key' => $key, 'kind' => $kind, 'modality' => $modality,
            'path' => $path, 'sheet' => $sheet];
        $this->edge($relations, $this->key($cellId), $key, 'contained_by');
        $this->edge($relations, $key, $this->key($tableId), 'table_parent');
    }

    private function docxRowLabel(string $rowId, array $cells): ?string
    {
        $id = $rowId.'/tc[1]';
        $cell = $cells[$id] ?? null;
        if ($cell === null || ($cell['meta']['grid_column'] ?? null) !== 1) {
            return null;
        }
        if (($cell['meta']['vertical_merge'] ?? null) !== 'continue') {
            return trim($cell['text'] ?? '') === '' ? null : $id;
        }
        if (! preg_match('~^(.*?/tr\[)(\d+)(\])$~', $rowId, $match)) {
            return null;
        }
        for ($row = (int) $match[2] - 1, $depth = 0; $row >= 1 && $depth < 64; $row--, $depth++) {
            $candidateId = $match[1].$row.$match[3].'/tc[1]';
            $candidate = $cells[$candidateId] ?? null;
            if ($candidate === null || ($candidate['meta']['grid_column'] ?? null) !== 1) {
                return null;
            }
            $merge = $candidate['meta']['vertical_merge'] ?? null;
            if ($merge === 'continue') {
                continue;
            }

            return $merge === 'restart' && trim($candidate['text'] ?? '') !== '' ? $candidateId : null;
        }

        return null;
    }

    private function isRowLabelHeading(string $text): bool
    {
        return preg_match('/^(?:metric|item|line item|account|description|indicator)$/iu', trim($text)) === 1;
    }

    /** Keep the source graph bounded to cited occurrences and their actual parser parents/headers. */
    private function relevantNodes(StructuredDocument $source, array $evidence): array
    {
        $all = array_column($source->nodes, null, 'id');
        $ranges = [];
        foreach ($evidence as $row) {
            foreach ($row->sources ?? [] as $citation) {
                if (($citation['source_graph_derived'] ?? false) === true) {
                    continue;
                }
                $start = $citation['start_offset'] ?? null;
                $end = $citation['end_offset'] ?? null;
                if (is_int($start) && is_int($end) && $end > $start) {
                    $ranges[] = [$start, $end];
                }
            }
        }
        if ($ranges === [] && count($all) <= 20000) {
            return $all;
        }
        $selected = [];
        foreach ($source->nodes as $node) {
            if (! isset($node['start'], $node['end'])) {
                continue;
            }
            foreach ($ranges as [$start, $end]) {
                if ($node['start'] >= $start && $node['end'] <= $end) {
                    $selected[$node['id']] = true;
                    break;
                }
            }
        }
        $tables = array_filter($all, static fn (array $node): bool => $node['type'] === 'table');
        foreach (array_keys($selected) as $id) {
            $cell = $all[$id];
            if ($cell['type'] !== 'cell') {
                continue;
            }
            if ($source->format === 'DOCX') {
                $table = $this->nearestTable($id, $all);
                $mergedLabel = $this->docxRowLabel($cell['parent'], $all);
                if ($mergedLabel !== null) {
                    $selected[$mergedLabel] = true;
                }
                foreach ($all as $candidate) {
                    if ($candidate['type'] !== 'cell') {
                        continue;
                    }
                    $sameRow = ($candidate['parent'] ?? null) === ($cell['parent'] ?? null);
                    $headerRow = ($all[$candidate['parent'] ?? '']['meta']['explicit_header'] ?? false)
                        && $this->nearestTable($candidate['id'], $all) === $table;
                    if ($sameRow || $headerRow) {
                        $selected[$candidate['id']] = true;
                    }
                }
            } elseif ($source->format === 'PDF') {
                $selected[$cell['parent'].'/cell[0]'] = true;
                foreach (is_array($cell['meta']['header_path_ids'] ?? null)
                    ? $cell['meta']['header_path_ids'] : [] as $headerId) {
                    $selected[$headerId] = true;
                }
                foreach ($cell['meta']['currency_marker_ids'] ?? [] as $markerId) {
                    $selected[$markerId] = true;
                }
                foreach (['unit_heading_id', 'entity_heading_id'] as $field) {
                    if (isset($cell['meta'][$field])) {
                        $selected[$cell['meta'][$field]] = true;
                    }
                }
            } elseif ($source->format === 'XLSX') {
                foreach ($tables as $table) {
                    if (($table['sheet'] ?? null) !== ($cell['sheet'] ?? null)
                        || ! $this->inRange($cell['meta']['coordinate'], $table['meta']['range'])) {
                        continue;
                    }
                    $selected[$table['id']] = true;
                    $bounds = Coordinate::rangeBoundaries($table['meta']['range']);
                    $firstColumn = Coordinate::stringFromColumnIndex($bounds[0][0]);
                    $row = Coordinate::coordinateFromString($cell['meta']['coordinate'])[1];
                    $selected[$table['parent'].'/cell:'.$firstColumn.$row] = true;
                    if ($table['meta']['header_row'] ?? false) {
                        for ($column = $bounds[0][0]; $column <= $bounds[1][0]; $column++) {
                            $selected[$table['parent'].'/cell:'
                                .Coordinate::stringFromColumnIndex($column).$bounds[0][1]] = true;
                        }
                    }
                }
            }
        }
        foreach (array_keys($selected) as $id) {
            if (isset($all[$id]['meta']['section_heading_id'])) {
                $selected[$all[$id]['meta']['section_heading_id']] = true;
            }
            foreach ($all[$id]['meta']['footnote_ids'] ?? [] as $footnoteId) {
                $selected['footnote:'.$footnoteId] = true;
            }
            $parent = $all[$id]['parent'] ?? null;
            for ($depth = 0; $parent !== null && $depth < 16; $depth++) {
                $selected[$parent] = true;
                $parent = $all[$parent]['parent'] ?? null;
            }
        }

        return array_filter($all, static fn (array $node): bool => isset($selected[$node['id']]));
    }

    private function nearestTable(string $id, array $nodes): ?string
    {
        $parent = $nodes[$id]['parent'] ?? null;
        for ($depth = 0; $parent !== null && $depth < 13; $depth++) {
            if (($nodes[$parent]['type'] ?? null) === 'table') {
                return $parent;
            }
            $parent = $nodes[$parent]['parent'] ?? null;
        }

        return null;
    }

    private function inRange(string $coordinate, string $range): bool
    {
        $bounds = Coordinate::rangeBoundaries($range);
        [$col, $row] = Coordinate::coordinateFromString($coordinate);
        $index = Coordinate::columnIndexFromString($col);

        return $index >= $bounds[0][0] && $index <= $bounds[1][0]
            && $row >= $bounds[0][1] && $row <= $bounds[1][1];
    }

    private function visible(array $cell): bool
    {
        $meta = $cell['meta'] ?? [];

        return ($meta['sheet_visibility'] ?? 'visible') === 'visible'
            && ($meta['row_visible'] ?? true) && ($meta['column_visible'] ?? true)
            && ($meta['row_visible_after_filter'] ?? true)
            && ($meta['formula'] ?? null) === null;
    }
}
