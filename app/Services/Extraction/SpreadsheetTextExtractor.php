<?php

namespace App\Services\Extraction;

use PhpOffice\PhpSpreadsheet\IOFactory;

class SpreadsheetTextExtractor
{
    public function extract(string $absolutePath): string
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $out = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $out[] = "=== Sheet: {$sheet->getTitle()} ===";
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $out[] = implode(' | ', array_map(fn ($c) => (string) ($c ?? ''), $row));
            }
        }

        return implode("\n", $out);
    }
}
