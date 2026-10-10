<?php

namespace App\Services\Extraction;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class SpreadsheetTextExtractor
{
    public function extract(string $absolutePath): string
    {
        app(ArchivePreflight::class)->check($absolutePath);
        $maxSheets = config('document_processing.xlsx_max_sheets');
        $maxRows = config('document_processing.xlsx_max_rows_per_sheet');
        $maxCells = config('document_processing.xlsx_max_cells');
        if (! is_int($maxSheets) || $maxSheets < 1 || ! is_int($maxRows) || $maxRows < 1
            || ! is_int($maxCells) || $maxCells < 1) {
            throw new \RuntimeException('Invalid workbook resource policy.');
        }
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
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
        $seenCells = 0;

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $out[] = "=== Sheet: {$sheet->getTitle()} ===";
                foreach ($sheet->getRowIterator() as $row) {
                    if ($row->getRowIndex() > $maxRows) {
                        throw new \RuntimeException('Workbook row limit exceeded.');
                    }
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        if (++$seenCells > $maxCells) {
                            throw new \RuntimeException('Workbook cell limit exceeded.');
                        }
                        $value = $cell->getDataType() === DataType::TYPE_FORMULA
                            ? $cell->getOldCalculatedValue() : $cell->getValue();
                        // Formula text is never evaluated or sent as an instruction. A cached result
                        // is source-provided data and must not be mistaken for a fresh calculation.
                        $cells[] = is_scalar($value) ? (string) $value : '';
                    }
                    $out[] = implode(' | ', $cells);
                }
            }
        } catch (\Throwable $e) {
            $this->rethrowSafeFailure($e);
        }

        return implode("\n", $out);
    }

    private function rethrowSafeFailure(\Throwable $e): never
    {
        if ($e instanceof \RuntimeException && in_array($e->getMessage(), [
            'Workbook sheet limit exceeded.', 'Workbook row limit exceeded.',
            'Workbook cell limit exceeded.',
        ], true)) {
            throw $e;
        }

        throw new \RuntimeException('Invalid workbook.');
    }
}
