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
            $projection = $this->attachReportOwner($projection, $pages, $page, $table);
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
        $splitHeader = false;
        if ($header === null) {
            $header = $this->splitHeaderSpan($text, $table);
            $splitHeader = true;
            if ($header === null) {
                return [];
            }
        }
        [$headerText, $headerStart] = $header;
        $tableId = $page['id'].'/table['.($ordinal + 1).']';
        $staged = [];
        $headerRow = $tableId.'/row[0]';
        $staged[] = ['id' => $headerRow, 'type' => 'row', 'parent' => $tableId,
            'path' => $headerRow, 'page' => $page['page'], 'meta' => ['explicit_header' => true]];
        $headerIds = [];
        $headerOffsets = [];
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
            $headerOffsets[$column] = $headerStart + $at;
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
                if ($splitHeader) {
                    $yearAt = $headerOffsets[$column];
                    $partAt = mb_strrpos(mb_substr($text, 0, $yearAt), $part['text']);
                    if ($partAt === false || $yearAt - $partAt > 250) {
                        return [];
                    }
                } else {
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
        $rowSearchEnd = $headerStart + mb_strlen($headerText);
        $markerRows = [];
        $markerTexts = [];
        foreach ($table['rows'] as $rowNumber => $cells) {
            $found = $splitHeader
                ? $this->orderedRowSpan($text, $cells, $rowSearchEnd)
                : $this->rowLine($lines, $cells);
            if ($found === null) {
                continue; // A layout row without an exact text projection is not evidence.
            }
            [$lineText, $lineStart] = $found;
            if ($splitHeader) {
                $rowSearchEnd = $lineStart + mb_strlen($lineText);
            }
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

    /** Header words may be separate Smalot lines even when Poppler aligns them. */
    private function splitHeaderSpan(string $text, array $table): ?array
    {
        $headers = array_slice($table['header'], 1);
        if ($headers === [] || count($headers) > 8) {
            return null;
        }
        $scope = $headers[0]['source_path'][0]['text'] ?? null;
        if (! is_string($scope)) {
            return null;
        }
        $start = mb_strpos($text, $scope);
        if ($start === false || mb_strpos($text, $scope, $start + 1) !== false) {
            return null;
        }
        $cursor = $start;
        foreach ($headers as $cell) {
            $path = $cell['source_path'] ?? [];
            foreach (array_slice($path, -2) as $part) {
                $at = mb_strpos($text, $part['text'], $cursor);
                if ($at === false || $at - $start > 400) {
                    return null;
                }
                $cursor = $at + mb_strlen($part['text']);
            }
        }
        $firstYear = mb_strpos($text, $headers[0]['text'], $start);
        if ($firstYear === false || $firstYear >= $cursor) {
            return null;
        }

        return [mb_substr($text, $firstYear, $cursor - $firstYear), $firstYear];
    }

    /** Keep the original word order and reject a jump across more than one short row. */
    private function orderedRowSpan(string $text, array $cells, int $after): ?array
    {
        $label = $cells[0]['text'] ?? '';
        $firstWord = preg_split('/\s+/u', $label)[0] ?? '';
        if ($firstWord === '') {
            return null;
        }
        $search = $after;
        while (($at = mb_strpos($text, $firstWord, $search)) !== false && $at - $after <= 500) {
            $end = $at;
            $window = mb_substr($text, $at, 500);
            foreach ($cells as $index => $cell) {
                $found = $this->findInLine($window, $cell['text'], $end - $at);
                if ($found === null) {
                    $end = $at;
                    break;
                }
                if ($index > 0 && preg_match('/\p{L}/u', mb_substr($window,
                    $end - $at, $found[0] - ($end - $at))) === 1) {
                    $end = $at;
                    break;
                }
                $end = $at + $found[1];
            }
            if ($end > $at) {
                return [mb_substr($text, $at, $end - $at), $at];
            }
            $search = $at + mb_strlen($firstWord);
        }

        return null;
    }

    /** A cover identity and the same printed page footer jointly name a report. */
    private function attachReportOwner(array $projection, array $pages, array $page,
        array $table): array
    {
        $cover = $pages[1] ?? null;
        if ($cover === null || $page['page'] === 1
            || ($table['entity_source'] ?? null) !== null
            || preg_match('/\b(?:financial|statement|results)\b/iu',
                (string) ($table['unit_source'] ?? '')) !== 1) {
            return $projection;
        }
        preg_match_all('/^([\p{L}\p{N}&., -]{3,100}\b(?:Ltd\.?|Limited|Inc\.?|Corporation|PLC|LLC))\s*\n(?=[^\n]*(?:report|review|statement|earnings))/imu',
            $cover['text'], $identities, PREG_OFFSET_CAPTURE);
        if (count($identities[1]) !== 1) {
            return $projection;
        }
        $name = trim($identities[1][0][0]);
        $footerGeometry = $table['report_footer'] ?? null;
        preg_match_all('/^\s*'.preg_quote($name, '/').'\s*\|[^\n]*\bPage\s+'
            .$page['page'].'\s*$/mu', $page['text'], $footers, PREG_OFFSET_CAPTURE);
        if (mb_substr_count($cover['text'], $name) < 2
            || ! is_array($footerGeometry)
            || ($footerGeometry['bbox'][1] ?? 0) <= ($table['bbox'][3] ?? 0) + 20
            || ! str_starts_with($footerGeometry['text'] ?? '', $name)
            || count($footers[0]) !== 1) {
            return $projection;
        }
        $footerByte = $footers[0][0][1];
        $footerAt = mb_strlen(substr($page['text'], 0, $footerByte));
        $coverAt = mb_strlen(substr($cover['text'], 0, $identities[1][0][1]));
        $tableId = $projection[0]['id'];
        $coverId = $tableId.'/heading:report_cover';
        $footerId = $tableId.'/heading:report_footer';
        $projection[0]['meta']['report_owner_cover_id'] = $coverId;
        $projection[0]['meta']['report_owner_footer_id'] = $footerId;
        foreach ($projection as &$node) {
            if (($node['type'] ?? null) === 'cell'
                && ! ($node['meta']['header'] ?? false)
                && ($node['meta']['grid_column'] ?? 0) >= 2) {
                $node['meta']['report_owner_cover_id'] = $coverId;
                $node['meta']['report_owner_footer_id'] = $footerId;
            }
        }
        unset($node);
        $projection[] = $this->textNode($cover, $coverId, 'section_heading', $cover['id'],
            $coverAt, $coverAt + mb_strlen($name), null, ['heading_kind' => 'report_cover']);
        $nameAt = mb_strpos($page['text'], $name, $footerAt);
        $projection[] = $this->textNode($page, $footerId, 'section_heading', $page['id'],
            $nameAt, $nameAt + mb_strlen($name), $footerGeometry['bbox'],
            ['heading_kind' => 'report_footer']);

        return $projection;
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
        $pattern = '/(?<![\p{L}\p{N}])'.str_replace(' ', '\\s+', preg_quote($part, '/'))
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
