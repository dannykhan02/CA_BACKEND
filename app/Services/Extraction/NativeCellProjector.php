<?php

namespace App\Services\Extraction;

use App\Models\Document;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Read-only common cell projection from the active, revision-bound native extraction. */
class NativeCellProjector
{
    public function project(Document $document): array
    {
        $source = app(StructuredExtractionStore::class)->active($document);
        if ($source === null || ! in_array($source->format, ['DOCX', 'XLSX'], true)
            || $source->format !== $document->type
            || $source->text !== (string) $document->extracted_text) {
            return [];
        }
        $nodes = collect($source->nodes)->keyBy('id');
        $tables = collect($source->nodes)->filter(fn ($node) => ($node['type'] ?? null) === 'table');
        $rows = collect($source->nodes)->filter(fn ($node) => ($node['type'] ?? null) === 'row');
        $cells = [];
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) !== 'cell' || ! isset($node['start'], $node['end'])
                || mb_substr($source->text, $node['start'], $node['end'] - $node['start'])
                    !== ($node['text'] ?? null)) {
                continue;
            }
            if ($source->format === 'DOCX') {
                $row = $nodes->get($node['parent'] ?? '');
                $table = $nodes->get($row['parent'] ?? '');
                if (($row['type'] ?? null) !== 'row' || ($table['type'] ?? null) !== 'table') {
                    continue;
                }
                $siblings = $rows->filter(fn ($other) => ($other['parent'] ?? null) === $table['id'])->values();
                $rowIndex = $siblings->search(fn ($other) => $other['id'] === $row['id']);
                if ($rowIndex === false) {
                    continue;
                }
                $column = (int) ($node['meta']['grid_column'] ?? 0);
                $rowSpan = 1;
                if (($node['meta']['vertical_merge'] ?? null) === 'restart') {
                    foreach ($siblings->slice($rowIndex + 1) as $followingRow) {
                        $continuation = collect($source->nodes)->first(fn ($item) => ($item['type'] ?? null) === 'cell'
                            && ($item['parent'] ?? null) === $followingRow['id']
                            && (int) ($item['meta']['grid_column'] ?? 0) === $column
                            && ($item['meta']['vertical_merge'] ?? null) === 'continue');
                        if ($continuation === null) {
                            break;
                        }
                        $rowSpan++;
                    }
                }
                $columnSpan = max(1, (int) ($node['meta']['grid_span'] ?? 1));
                $tableId = $table['id'];
                $sheet = null;
                $isColumnHeader = ($row['meta']['explicit_header'] ?? false) === true;
                $isRowHeader = ! $isColumnHeader && $column === 1;
            } else {
                $coordinate = $node['meta']['coordinate'] ?? null;
                if (! is_string($coordinate)) {
                    continue;
                }
                [$letter, $rowIndex] = Coordinate::coordinateFromString($coordinate);
                $rowIndex = (int) $rowIndex;
                $column = Coordinate::columnIndexFromString($letter);
                $owners = $tables->filter(fn ($table) => ($table['sheet'] ?? null) === ($node['sheet'] ?? null)
                    && isset($table['meta']['range'])
                    && $this->inRange($coordinate, $table['meta']['range']));
                if ($owners->count() !== 1) {
                    continue;
                }
                $table = $owners->first();
                $bounds = Coordinate::rangeBoundaries($table['meta']['range']);
                $tableId = $table['id'];
                $sheet = $node['sheet'] ?? null;
                $isColumnHeader = ($table['meta']['header_row'] ?? false) === true
                    && $rowIndex === $bounds[0][1];
                $isRowHeader = ! $isColumnHeader && $column === $bounds[0][0];
                $rowSpan = 1;
                $columnSpan = 1;
                foreach ($node['meta']['merges'] ?? [] as $merge) {
                    $range = Coordinate::rangeBoundaries($merge);
                    if ($column === $range[0][0] && $rowIndex === $range[0][1]) {
                        $columnSpan = $range[1][0] - $range[0][0] + 1;
                        $rowSpan = $range[1][1] - $range[0][1] + 1;
                    }
                }
            }
            if ($column < 1) {
                continue;
            }
            $cells[] = ['document_id' => $document->id,
                'source_hash' => $document->file_hash,
                'text_revision' => hash('sha256', $source->text),
                'parser_version' => $source->parserVersion,
                'format' => $source->format, 'page' => null, 'sheet' => $sheet,
                'table_id' => $tableId, 'source_node_id' => $node['id'],
                'row' => $source->format === 'DOCX' ? $rowIndex + 1 : $rowIndex,
                'column' => $column, 'row_span' => $rowSpan, 'column_span' => $columnSpan,
                'vertical_merge' => $source->format === 'DOCX'
                    ? ($node['meta']['vertical_merge'] ?? null) : null,
                'text' => $node['text'], 'start_offset' => $node['start'],
                'end_offset' => $node['end'], 'row_header' => $isRowHeader,
                'column_header' => $isColumnHeader, 'caption_node_id' => null,
                'bbox' => null, 'extraction_confidence' => 1.0];
        }
        return $cells;
    }

    private function inRange(string $coordinate, string $range): bool
    {
        $bounds = Coordinate::rangeBoundaries($range);
        [$letter, $row] = Coordinate::coordinateFromString($coordinate);
        $column = Coordinate::columnIndexFromString($letter);
        return $column >= $bounds[0][0] && $column <= $bounds[1][0]
            && $row >= $bounds[0][1] && $row <= $bounds[1][1];
    }
}
