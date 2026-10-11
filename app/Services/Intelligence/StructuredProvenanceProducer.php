<?php

namespace App\Services\Intelligence;

use App\Services\Extraction\StructuredDocument;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Converts only parser-native occurrence and explicit table-header paths to Phase 3A edges. */
class StructuredProvenanceProducer
{
    public function build(StructuredDocument $source, iterable $evidence): array
    {
        if (count($source->nodes) > 20000) {
            throw new \RuntimeException('Provenance anchor limit exceeded.');
        }
        $nodes = array_column($source->nodes, null, 'id');
        $anchors = [];
        $relations = [];
        $cells = [];
        $tables = [];
        foreach ($source->nodes as $node) {
            $key = $this->key($node['id']);
            $path = $node['path'] ?? $node['id'];
            $anchors[$key] = ['key' => $key,
                'kind' => match ($node['type']) {
                    'paragraph' => 'paragraph', 'table' => 'table',
                    'cell' => $source->format === 'XLSX' ? 'worksheet_cell' : 'cell',
                    default => 'text_span',
                },
                'modality' => match ($source->format) {
                    'XLSX' => 'xlsx', 'DOCX' => 'docx', default => 'structured_pdf',
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
            if (isset($node['start'], $node['end'])) {
                $anchors[$key] += ['start_offset' => $node['start'], 'end_offset' => $node['end'],
                    'text' => $node['text']];
            }
            if (isset($node['parent'])) {
                $this->edge($relations, $key, $this->key($node['parent']), 'contained_by');
            }
            if ($node['type'] === 'cell') {
                $cells[$node['id']] = $node;
            }
            if ($node['type'] === 'table') {
                $tables[$node['id']] = $node;
            }
        }
        $headerEdges = [];
        $docxHeaders = [];
        if ($source->format === 'DOCX') {
            foreach ($cells as $headerId => $header) {
                $rowId = $header['parent'] ?? '';
                if (! ($nodes[$rowId]['meta']['explicit_header'] ?? false)) {
                    continue;
                }
                $tableId = $this->nearestTable($headerId, $nodes);
                $column = $this->docxColumn($headerId);
                if ($tableId !== null && $column !== null) {
                    $docxHeaders[$tableId][$column][] = $headerId;
                }
            }
        }
        foreach ($cells as $id => $cell) {
            if ($source->format === 'DOCX') {
                $tableId = $this->nearestTable($id, $nodes);
                if ($tableId === null) {
                    continue;
                }
                $this->edge($relations, $this->key($id), $this->key($tableId), 'table_parent');
                $column = $this->docxColumn($id);
                if (! ($nodes[$cell['parent']]['meta']['explicit_header'] ?? false)) {
                    foreach ($docxHeaders[$tableId][$column] ?? [] as $headerId) {
                        if ($headerId !== $id) {
                            $this->edge($relations, $this->key($id), $this->key($headerId), 'column_header');
                            $headerEdges[$id]['column'][] = $headerId;
                        }
                    }
                }

                continue;
            }
            foreach ($tables as $tableId => $table) {
                if ($source->format === 'XLSX' && ($table['sheet'] ?? null) === ($cell['sheet'] ?? null)
                    && $this->inRange($cell['meta']['coordinate'], $table['meta']['range'])) {
                    $this->edge($relations, $this->key($id), $this->key($tableId), 'table_parent');
                    if ($table['meta']['header_row'] ?? false) {
                        $bounds = Coordinate::rangeBoundaries($table['meta']['range']);
                        $column = Coordinate::coordinateFromString($cell['meta']['coordinate'])[0];
                        $headerCoordinate = $column.$bounds[0][1];
                        $headerId = $table['parent'].'/cell:'.$headerCoordinate;
                        if ($headerId !== $id && isset($cells[$headerId])) {
                            $this->edge($relations, $this->key($id), $this->key($headerId), 'column_header');
                            $headerEdges[$id]['column'][] = $headerId;
                        }
                    }
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
                $from = $citation['start_offset'] ?? null;
                $to = $citation['end_offset'] ?? null;
                if (! is_int($from) || ! is_int($to) || $to <= $from) {
                    continue;
                }
                foreach ($cells as $id => $cell) {
                    if (! isset($cell['start'], $cell['end']) || $cell['start'] >= $to || $cell['end'] <= $from
                        || ! $this->visible($cell)
                        || ! str_contains($normalizer->fold($cell['text']), $foldedValue)) {
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
            foreach (['period' => 'column'] as $role => $direction) {
                $field = $row->data[$role] ?? null;
                if (! is_string($field) || $field === '') {
                    continue;
                }
                $matches = array_values(array_filter($headerEdges[$id][$direction] ?? [],
                    static fn (string $candidate): bool => isset($cells[$candidate]['text'])
                        && str_contains($normalizer->fold($cells[$candidate]['text']), $normalizer->fold($field))));
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

    private function edge(array &$edges, string $from, string $to, string $type): void
    {
        $edges[$from.'|'.$to.'|'.$type] = compact('from', 'to', 'type');
        if (count($edges) > 100000) {
            throw new \RuntimeException('Provenance relationship limit exceeded.');
        }
    }

    private function docxColumn(string $id): ?int
    {
        return preg_match('~/tc\[(\d+)\]$~', $id, $m) ? (int) $m[1] : null;
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
