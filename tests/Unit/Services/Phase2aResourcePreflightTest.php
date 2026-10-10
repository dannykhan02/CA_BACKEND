<?php

namespace Tests\Unit\Services;

use App\Services\Extraction\ArchivePreflight;
use App\Services\Extraction\SpreadsheetTextExtractor;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class Phase2aResourcePreflightTest extends TestCase
{
    private function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docintel_zip_');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_malformed_archive_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad_zip_');
        try {
            file_put_contents($path, 'not a workbook');
            $this->expectException(\RuntimeException::class);
            app(ArchivePreflight::class)->check($path);
        } finally {
            unlink($path);
        }
    }

    public function test_entry_count_expansion_and_ratio_limits_are_checked_before_decompression(): void
    {
        $path = $this->archive(['a.txt' => str_repeat('A', 60000), 'b.txt' => str_repeat('B', 60000)]);
        try {
            $baseline = config('document_processing.archive_limits');
            config(['document_processing.max_upload_size_kb' => 1]);
            foreach ([['max_entries' => 1], ['max_entry_bytes' => 100000,
                'max_expanded_bytes' => 100000], ['max_ratio' => 10]] as $override) {
                config(['document_processing.archive_limits' => array_replace($baseline, $override)]);
                try {
                    app(ArchivePreflight::class)->check($path);
                    $this->fail('The hostile archive must be refused.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('exceeded', $e->getMessage());
                }
            }
        } finally {
            unlink($path);
        }
    }

    public function test_normal_xlsx_extracts_values_without_evaluating_or_exposing_formula_text(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Amounts');
        $sheet->setCellValue('A1', 'Revenue');
        $sheet->setCellValue('B1', 12.4);
        $sheet->setCellValueExplicit('C1', '=WEBSERVICE("https://example.invalid/secret")', DataType::TYPE_FORMULA);
        $path = tempnam(sys_get_temp_dir(), 'valid_xlsx_');
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $text = app(SpreadsheetTextExtractor::class)->extract($path);
            $this->assertStringContainsString('Revenue | 12.4', $text);
            $this->assertStringNotContainsString('WEBSERVICE', $text);
            $this->assertStringNotContainsString('example.invalid', $text);
            config(['document_processing.xlsx_max_cells' => 2]);
            $this->expectExceptionMessage('Workbook cell limit exceeded');
            app(SpreadsheetTextExtractor::class)->extract($path);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_archive_with_broken_workbook_xml_fails_without_emitting_source(): void
    {
        $path = $this->archive(['[Content_Types].xml' => '<Types/>',
            'xl/workbook.xml' => '<workbook><broken>']);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid workbook.');
            app(SpreadsheetTextExtractor::class)->extract($path);
        } finally {
            unlink($path);
        }
    }
}
