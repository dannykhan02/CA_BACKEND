<?php

namespace App\Services\Extraction;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SpreadsheetTextExtractor
{
    public function extract(string $absolutePath): string
    {
        return $this->extractStructured($absolutePath)->text;
    }

    public function extractStructured(string $absolutePath): StructuredDocument
    {
        app(ArchivePreflight::class)->check($absolutePath);
        $maxSheets = config('document_processing.xlsx_max_sheets');
        $maxRows = config('document_processing.xlsx_max_rows_per_sheet');
        $maxCells = config('document_processing.xlsx_max_cells');
        $maxStructuralNodes = config('document_processing.xlsx_max_structural_nodes');
        if (! is_int($maxSheets) || $maxSheets < 1 || ! is_int($maxRows) || $maxRows < 1
            || ! is_int($maxCells) || $maxCells < 1 || ! is_int($maxStructuralNodes)
            || $maxStructuralNodes < 1 || $maxStructuralNodes > 100000) {
            throw new \RuntimeException('Invalid workbook resource policy.');
        }
        try {
            $reader = new Xlsx;
            // Styles, sheet visibility, merges and native table definitions are source data.
            $reader->setReadDataOnly(false);
            $info = $reader->listWorksheetInfo($absolutePath);
            if (count($info) > $maxSheets) {
                throw new \RuntimeException('Workbook sheet limit exceeded.');
            }
            $plannedCells = 0;
            foreach ($info as $sheet) {
                if ($sheet['totalRows'] > $maxRows) {
                    throw new \RuntimeException('Workbook row limit exceeded.');
                }
                $plannedCells += $sheet['totalRows'] * $sheet['totalColumns'];
                if ($plannedCells > $maxCells) {
                    throw new \RuntimeException('Workbook cell limit exceeded.');
                }
            }
            $spreadsheet = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            $this->rethrowSafeFailure($e);
        }
        $out = [];
        $nodes = [];
        $offset = 0;
        $seenCells = 0;
        $formatFailures = 0;
        $nodesTruncated = false;
        $previousCalendar = ExcelDate::getExcelCalendar();
        ExcelDate::setExcelCalendar($spreadsheet->getExcelCalendar());

        try {
            foreach ($spreadsheet->getAllSheets() as $sheetIndex => $sheet) {
                $sheetId = 'sheet:'.$sheetIndex;
                $sheetName = $sheet->getTitle();
                $sheetState = $sheet->getSheetState();
                $sheetNode = ['id' => $sheetId, 'type' => 'worksheet', 'path' => $sheetId,
                    'sheet' => $sheetName, 'meta' => ['order' => $sheetIndex, 'visibility' => $sheetState,
                        'date_system' => $spreadsheet->getExcelCalendar()]];
                $sheetIncluded = count($nodes) < $maxStructuralNodes;
                if ($sheetIncluded) {
                    $nodes[] = $sheetNode;
                } else {
                    $nodesTruncated = true;
                }
                $line = "=== Sheet: {$sheetName} ===";
                if ($out !== []) {
                    $offset++;
                }
                $out[] = $line;
                $offset += mb_strlen($line);
                $mergeOwners = [];
                foreach ($sheet->getMergeCells() as $range) {
                    $owner = explode(':', $range)[0];
                    $mergeOwners[$owner][] = $range;
                }
                if (count($sheet->getMergeCells()) > 10000) {
                    throw new \RuntimeException('Workbook merge limit exceeded.');
                }
                if (count($sheet->getTableCollection()) > 1000) {
                    throw new \RuntimeException('Workbook table limit exceeded.');
                }
                foreach ($sheet->getTableCollection() as $table) {
                    $tableNode = ['id' => $sheetId.'/table:'.$table->getName(), 'type' => 'table',
                        'parent' => $sheetId, 'sheet' => $sheetName,
                        'path' => $sheetId.'/table:'.$table->getName(),
                        'meta' => ['range' => $table->getRange(), 'header_row' => $table->getShowHeaderRow()]];
                    if ($sheetIncluded && count($nodes) < $maxStructuralNodes) {
                        $nodes[] = $tableNode;
                    } else {
                        $nodesTruncated = true;
                    }
                }
                foreach ($sheet->getRowIterator() as $row) {
                    if ($row->getRowIndex() > $maxRows) {
                        throw new \RuntimeException('Workbook row limit exceeded.');
                    }
                    $cells = [];
                    $rowNodes = [];
                    $rowOffset = 0;
                    foreach ($row->getCellIterator() as $cell) {
                        if (++$seenCells > $maxCells) {
                            throw new \RuntimeException('Workbook cell limit exceeded.');
                        }
                        $value = $cell->getDataType() === DataType::TYPE_FORMULA
                            ? $cell->getOldCalculatedValue() : $cell->getValue();
                        // Formula text is never evaluated or sent as an instruction. A cached result
                        // is source-provided data and must not be mistaken for a fresh calculation.
                        $serialized = is_scalar($value) ? (string) $value : '';
                        $raw = $cell->getValue();
                        if ($serialized !== '' || $raw instanceof RichText
                            || $cell->getDataType() === DataType::TYPE_FORMULA
                            || $cell->getDataType() === DataType::TYPE_ERROR) {
                            $coordinate = $cell->getCoordinate();
                            $numberFormat = $cell->getStyle()->getNumberFormat()->getFormatCode();
                            $formatted = null;
                            if (is_scalar($value) && $cell->getDataType() !== DataType::TYPE_FORMULA) {
                                try {
                                    $formatted = NumberFormat::toFormattedString($value, $numberFormat);
                                } catch (\Throwable) {
                                    $formatFailures++;
                                }
                            }
                            $node = ['id' => $sheetId.'/cell:'.$coordinate, 'type' => 'cell',
                                'parent' => $sheetId, 'sheet' => $sheetName,
                                'path' => $sheetId.'!'.$coordinate,
                                'meta' => ['coordinate' => $coordinate, 'cell_type' => $cell->getDataType(),
                                    'raw' => is_scalar($raw) ? $raw
                                        : ($raw instanceof RichText ? $raw->getPlainText() : null),
                                    'rich_text' => $raw instanceof RichText,
                                    'display' => $formatted, 'number_format' => $numberFormat,
                                    'formula' => $cell->getDataType() === DataType::TYPE_FORMULA ? $raw : null,
                                    'cached_result' => $cell->getDataType() === DataType::TYPE_FORMULA ? $value : null,
                                    'cache_present' => $cell->getDataType() === DataType::TYPE_FORMULA && $value !== null,
                                    'merges' => $mergeOwners[$coordinate] ?? [],
                                    'sheet_visibility' => $sheetState,
                                    'row_visible' => $sheet->getRowDimension($row->getRowIndex())->getVisible(),
                                    'row_visible_after_filter' => $sheet->getRowDimension($row->getRowIndex())->getVisibleAfterFilter(),
                                    'column_visible' => $sheet->getColumnDimension($cell->getColumn())->getVisible()]];
                            if ($serialized !== '') {
                                $node['start'] = $offset + 1 + $rowOffset;
                                $node['end'] = $node['start'] + mb_strlen($serialized);
                                $node['text'] = $serialized;
                            }
                            $rowNodes[] = $node;
                        }
                        $cells[] = $serialized;
                        $rowOffset += mb_strlen($serialized) + 3;
                    }
                    $line = implode(' | ', $cells);
                    $out[] = $line;
                    $offset += 1 + mb_strlen($line);
                    if ($sheetIncluded) {
                        $remaining = $maxStructuralNodes - count($nodes);
                        if (count($rowNodes) > $remaining) {
                            $nodesTruncated = true;
                        }
                        array_push($nodes, ...array_slice($rowNodes, 0, max(0, $remaining)));
                    } elseif ($rowNodes !== []) {
                        $nodesTruncated = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->rethrowSafeFailure($e);
        } finally {
            ExcelDate::setExcelCalendar($previousCalendar);
            $spreadsheet->disconnectWorksheets();
        }

        $text = implode("\n", $out);

        return new StructuredDocument('XLSX', 'phpspreadsheet-xlsx.v2', $text, $nodes,
            ['text' => 'complete', 'sheets' => $nodesTruncated ? 'partial' : 'complete',
                'cells' => $nodesTruncated ? 'partial' : 'complete',
                'nodes_truncated' => $nodesTruncated,
                'merges' => 'complete', 'formulas' => 'cached_only', 'headers' => 'native_tables_only',
                'hidden_content' => 'retained_marked',
                'display_formatting' => $formatFailures === 0 ? 'available' : 'partial',
                'rich_text_projection' => 'legacy_blank']);
    }

    private function rethrowSafeFailure(\Throwable $e): never
    {
        if ($e instanceof \RuntimeException && in_array($e->getMessage(), [
            'Workbook sheet limit exceeded.', 'Workbook row limit exceeded.',
            'Workbook cell limit exceeded.', 'Workbook merge limit exceeded.',
            'Workbook table limit exceeded.',
        ], true)) {
            throw $e;
        }

        throw new \RuntimeException('Invalid workbook.');
    }
}
