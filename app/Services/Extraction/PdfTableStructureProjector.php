<?php

namespace App\Services\Extraction;

use App\Services\Intelligence\PeriodParser;

/** Projects a geometry candidate onto the exact smalot text revision. */
final class PdfTableStructureProjector
{
    public function __construct(private PeriodParser $periods) {}

    public function augment(StructuredDocument $source, array $tables): StructuredDocument
    {
        if ($source->format !== 'PDF') {
            throw new \InvalidArgumentException('PDF structure required.');
        }
        $nodes = $source->nodes;
        $pages = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'page' && isset($node['start'], $node['page'])) {
                $pages[$node['page']] = $node;
            }
        }
        $accepted = 0;
        foreach ($tables as $ordinal => $table) {
            $page = $pages[$table['page'] ?? 0] ?? null;
            if ($page === null || ! isset($table['rows'], $table['header'])
                || ($table['status'] ?? null) !== 'layout_candidate_only') {
                continue;
            }
            $projection = $this->project($page, $table, $ordinal);
            if ($projection === []) {
                continue;
            }
            array_push($nodes, ...$projection);
            $accepted++;
        }

        return new StructuredDocument('PDF', $source->parserVersion.'+poppler-grid.v1', $source->text,
            $nodes, [...$source->capabilities, 'tables' => $accepted > 0
                ? 'projected_geometry_candidates' : 'unavailable',
                'geometry' => $accepted > 0 ? 'poppler_points_top_left' : 'unavailable',
                'projected_tables' => $accepted]);
    }

    private function project(array $page, array $table, int $ordinal): array
    {
        $text = $page['text'];
        $lines = $this->lines($text);
        $header = $this->headerLine($lines, $table['header']);
        if ($header === null) {
            return [];
        }
        [$headerText, $headerStart] = $header;
        $tableId = $page['id'].'/table['.($ordinal + 1).']';
        $staged = [];
        $headerRow = $tableId.'/row[0]';
        $staged[] = ['id' => $headerRow, 'type' => 'row', 'parent' => $tableId,
            'path' => $headerRow, 'page' => $page['page'], 'meta' => ['explicit_header' => true]];
        $headerIds = [];
        $cursor = 0;
        foreach ($table['header'] as $column => $cell) {
            if ($column === 0 && $cell['text'] === '') {
                continue;
            }
            if (! $this->validBox($cell['bbox'] ?? null)) {
                return [];
            }
            $found = $this->findInLine($headerText, $cell['text'], $cursor);
            if ($found === null) {
                return [];
            }
            [$at, $end] = $found;
            $cursor = $end;
            $id = $headerRow.'/cell['.$column.']';
            $headerIds[$column] = $id;
            $staged[] = $this->textNode($page, $id, 'cell', $headerRow,
                $headerStart + $at, $headerStart + $end, $cell['bbox'],
                ['grid_column' => $column + 1, 'grid_span' => 1, 'header' => true,
                    'page_width' => $table['page_width'] ?? null,
                    'page_height' => $table['page_height'] ?? null]);
        }
        $pathIds = [];
        foreach ($table['header'] as $column => $cell) {
            if ($column === 0 || ! isset($headerIds[$column])) {
                continue;
            }
            $path = $cell['source_path'] ?? [];
            if ($path === []) {
                return [];
            }
            $parsed = $this->periods->parse(implode(' ', array_column($path, 'text')));
            if ($parsed === null || $parsed->label !== ($table['periods'][$column] ?? null)) {
                return [];
            }
            $yearCenter = ($cell['bbox'][0] + $cell['bbox'][2]) / 2;
            foreach (array_slice($path, 0, -1) as $part) {
                if ($yearCenter < $part['bbox'][0] - 20 || $yearCenter > $part['bbox'][2] + 20) {
                    return [];
                }
            }
            $ids = [];
            foreach ($path as $part) {
                if (! $this->validBox($part['bbox'] ?? null)) {
                    return [];
                }
                if ($part['text'] === $cell['text'] && $part['bbox'] === $cell['bbox']) {
                    $ids[] = $headerIds[$column];

                    continue;
                }
                $scopeText = $path[0]['text'];
                $scope = mb_strpos($text, $scopeText, max(0, $headerStart - 250));
                if ($scope === false || $scope >= $headerStart) {
                    return [];
                }
                $partAt = $part['text'] === $scopeText ? $scope
                    : mb_strpos($text, $part['text'], $scope + mb_strlen($scopeText));
                if ($partAt === false || $partAt >= $headerStart) {
                    return [];
                }
                $id = $headerRow.'/fragment:'.substr(hash('sha256', json_encode($part['bbox']).$part['text']), 0, 16);
                if (! isset($pathIds[$id])) {
                    $staged[] = $this->textNode($page, $id, 'cell', $headerRow,
                        $partAt, $partAt + mb_strlen($part['text']), $part['bbox'],
                        ['header' => true, 'spanning' => true,
                            'page_width' => $table['page_width'] ?? null,
                            'page_height' => $table['page_height'] ?? null]);
                    $pathIds[$id] = true;
                }
                $ids[] = $id;
            }
            $headerIds[$column] = $ids;
        }
        $unitId = $this->headingNode($staged, $page, $tableId, 'unit',
            $table['unit_source'] ?? null, $headerStart);
        $entityId = $this->headingNode($staged, $page, $tableId, 'entity',
            $table['entity_source'] ?? null, $headerStart);
        $rows = [];
        $markerRows = [];
        $markerTexts = [];
        foreach ($table['rows'] as $rowNumber => $cells) {
            $found = $this->rowLine($lines, $cells);
            if ($found === null) {
                continue; // A layout row without an exact text projection is not evidence.
            }
            [$lineText, $lineStart] = $found;
            $rowId = $tableId.'/row['.($rowNumber + 1).']';
            $rowNodes = [['id' => $rowId, 'type' => 'row', 'parent' => $tableId,
                'path' => $rowId, 'page' => $page['page']]];
            $cursor = 0;
            $rowMarkers = [];
            foreach ($cells as $column => $cell) {
                if (! $this->validBox($cell['bbox'] ?? null)) {
                    $rowNodes = [];
                    break;
                }
                $previous = $cursor;
                $position = $this->findInLine($lineText, $cell['text'], $cursor);
                if ($position === null) {
                    $rowNodes = [];
                    break;
                }
                [$at, $end] = $position;
                $cursor = $end;
                $id = $rowId.'/cell['.$column.']';
                $rowNodes[] = $this->textNode($page, $id, 'cell', $rowId,
                    $lineStart + $at, $lineStart + $end, $cell['bbox'],
                    ['grid_column' => $column + 1, 'grid_span' => 1,
                        'header_path_ids' => $column === 0 ? [] : ($headerIds[$column] ?? []),
                        'unit_heading_id' => $unitId, 'entity_heading_id' => $entityId,
                        'page_width' => $table['page_width'] ?? null,
                        'page_height' => $table['page_height'] ?? null]);
                $marker = $cell['currency_marker'] ?? null;
                if ($column > 0 && is_array($marker)
                    && $this->validBox($marker['bbox'] ?? null)) {
                    $markerAt = $this->findInLine($lineText, $marker['text'], $previous);
                    if ($markerAt !== null && $markerAt[1] <= $at
                        && $at - $markerAt[1] <= 20) {
                        $markerId = $rowId.'/currency['.$column.']';
                        $rowNodes[] = $this->textNode($page, $markerId, 'cell', $rowId,
                            $lineStart + $markerAt[0], $lineStart + $markerAt[1],
                            $marker['bbox'], ['header' => true, 'grid_column' => $column + 1,
                                'page_width' => $table['page_width'] ?? null,
                                'page_height' => $table['page_height'] ?? null]);
                        $rowMarkers[$column] = $markerId;
                        $markerTexts[$column][$marker['text']] = true;
                    }
                }
            }
            if ($rowNodes !== []) {
                array_push($rows, ...$rowNodes);
                if (count($rowMarkers) === count($table['header']) - 1) {
                    $markerRows[] = ['row' => $rowNumber, 'markers' => $rowMarkers];
                }
            }
        }
        // Currency marks repeated across complete, separated rows are a column
        // notation. A single nearby symbol, or any contradictory mark, proves none.
        $firstMarkerRow = $markerRows[0] ?? null;
        $secondMarkerRow = $firstMarkerRow === null ? null : collect($markerRows)
            ->first(static fn ($entry) => $entry['row'] - $firstMarkerRow['row'] >= 2);
        if ($secondMarkerRow !== null
            && count(array_filter($markerTexts, static fn ($texts) => count($texts) === 1))
                === count($table['header']) - 1) {
            foreach ($rows as &$node) {
                if ($node['type'] !== 'cell' || ($node['meta']['header'] ?? false)
                    || ($node['meta']['grid_column'] ?? 0) < 2) {
                    continue;
                }
                $column = $node['meta']['grid_column'] - 1;
                $node['meta']['currency_marker_ids'] = [$firstMarkerRow['markers'][$column],
                    $secondMarkerRow['markers'][$column]];
            }
            unset($node);
        }
        if (count($rows) < 2 * (count($table['header']) + 1)) {
            return [];
        }
        $tableNode = ['id' => $tableId, 'type' => 'table', 'parent' => $page['id'],
            'path' => $tableId, 'page' => $page['page'],
            'meta' => ['bbox' => $table['bbox'], 'unit_heading_id' => $unitId,
                'entity_heading_id' => $entityId, 'layout_status' => 'candidate',
                'page_width' => $table['page_width'] ?? null,
                'page_height' => $table['page_height'] ?? null]];

        return [$tableNode, ...$staged, ...$rows];
    }

    private function lines(string $text): array
    {
        $lines = [];
        $start = 0;
        foreach (explode("\n", $text) as $line) {
            $lines[] = [$line, $start];
            $start += mb_strlen($line) + 1;
        }

        return $lines;
    }

    private function headerLine(array $lines, array $cells): ?array
    {
        $wanted = array_values(array_filter(array_column($cells, 'text'), static fn ($s) => $s !== ''));
        $found = [];
        foreach ($lines as [$line, $start]) {
            $cursor = 0;
            foreach ($wanted as $part) {
                $position = $this->findInLine($line, $part, $cursor);
                if ($position === null) {
                    continue 2;
                }
                $cursor = $position[1];
            }
            $found[] = [$line, $start];
        }

        return count($found) === 1 ? $found[0] : null;
    }

    private function rowLine(array $lines, array $cells): ?array
    {
        $wanted = array_column($cells, 'text');
        $found = [];
        foreach ($lines as [$line, $start]) {
            $cursor = 0;
            foreach ($wanted as $part) {
                $position = $this->findInLine($line, $part, $cursor);
                if ($position === null) {
                    continue 2;
                }
                $cursor = $position[1];
            }
            $found[] = [$line, $start];
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /** Returns Unicode offsets within one line, and enforces token boundaries. */
    private function findInLine(string $line, string $part, int $after): ?array
    {
        $pattern = '/(?<![\p{L}\p{N}])'.str_replace('\\ ', '\\s+', preg_quote($part, '/'))
            .'(?![\p{L}\p{N}])/u';
        if (! preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE,
            strlen(mb_substr($line, 0, $after)))) {
            return null;
        }
        $byte = $match[0][1];
        $start = mb_strlen(substr($line, 0, $byte));

        return [$start, $start + mb_strlen($match[0][0])];
    }

    private function headingNode(array &$nodes, array $page, string $tableId, string $kind,
        ?string $source, int $headerStart): ?string
    {
        if ($source === null) {
            return null;
        }
        $at = mb_strrpos(mb_substr($page['text'], 0, $headerStart), $source);
        if ($at === false || $headerStart - $at > 250) {
            return null;
        }
        $id = $tableId.'/heading:'.$kind;
        $nodes[] = $this->textNode($page, $id, 'section_heading', $page['id'],
            $at, $at + mb_strlen($source), null, ['heading_kind' => $kind]);

        return $id;
    }

    private function textNode(array $page, string $id, string $type, string $parent,
        int $from, int $to, ?array $bbox, array $meta): array
    {
        $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'path' => $id,
            'page' => $page['page'], 'start' => $page['start'] + $from,
            'end' => $page['start'] + $to,
            'text' => mb_substr($page['text'], $from, $to - $from),
            'meta' => $meta];
        if ($bbox !== null) {
            $node['meta']['bbox'] = $bbox;
        }

        return $node;
    }

    private function validBox(mixed $box): bool
    {
        return is_array($box) && count($box) === 4
            && ! array_filter($box, static fn ($item) => ! is_int($item) && ! is_float($item))
            && $box[2] > $box[0] && $box[3] > $box[1];
    }
}
