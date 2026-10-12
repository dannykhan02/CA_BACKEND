<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Intelligence\StructuredProvenanceProducer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StructuredExtractionTest extends TestCase
{
    private function legacyXlsxText(string $path): string
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $out = [];
        try {
            foreach ($book->getAllSheets() as $sheet) {
                $out[] = "=== Sheet: {$sheet->getTitle()} ===";
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        $value = $cell->getDataType() === DataType::TYPE_FORMULA
                            ? $cell->getOldCalculatedValue() : $cell->getValue();
                        $cells[] = is_scalar($value) ? (string) $value : '';
                    }
                    $out[] = implode(' | ', $cells);
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return implode("\n", $out);
    }

    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'structure_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
<w:p><w:r><w:t>Before 12.4</w:t></w:r></w:p>
<w:tbl>
<w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>FY2025</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>Revenue</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>12.4</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>Expenses</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>12.4</w:t></w:r></w:p></w:tc></w:tr>
</w:tbl>
<w:p><w:r><w:t>After 12.4</w:t></w:r></w:p>
<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Merged</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Nested</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:tc></w:tr></w:tbl>
</w:body></w:document>
XML);
        $zip->close();

        return $path;
    }

    public function test_docx_paths_repeated_occurrences_and_explicit_header_edges(): void
    {
        $path = $this->docx();
        try {
            $parser = new DocumentTextExtractor;
            $source = $parser->extractDocxStructured($path);
            self::assertSame($parser->extractDocxText($path), $source->text);
            $first = $source->node('body/tbl[1]/tr[2]/tc[2]');
            $second = $source->node('body/tbl[1]/tr[3]/tc[2]');
            self::assertSame('12.4', $first['text']);
            self::assertSame('12.4', $second['text']);
            self::assertNotSame($first['start'], $second['start']);
            self::assertContains($first['id'], array_column($source->nodesAt($first['start'], $first['end']), 'id'));
            self::assertSame(2, $source->node('body/tbl[2]/tr[1]/tc[1]')['meta']['grid_span']);
            self::assertNotNull($source->node('body/tbl[2]/tr[1]/tc[1]/tbl[1]/tr[1]/tc[1]'));
            [$anchors, $relations] = app(StructuredProvenanceProducer::class)->build($source, []);
            self::assertNotEmpty($anchors);
            self::assertContains('column_header', array_column($relations, 'type'));
        } finally {
            unlink($path);
        }
    }

    public function test_xlsx_preserves_cache_formula_visibility_merge_and_sheet_identity(): void
    {
        $book = new Spreadsheet;
        $first = $book->getActiveSheet();
        $first->setTitle('Results');
        $first->fromArray([['Metric', 'FY2025'], ['Revenue', 12.4], ['Expenses', 12.4]]);
        $first->addTable(new Table('A1:B3', 'ResultsTable'));
        $first->mergeCells('C1:D1');
        $first->setCellValue('C1', 'Merged');
        $first->setCellValueExplicit('E2', '=WEBSERVICE("https://example.invalid")', DataType::TYPE_FORMULA);
        $first->getRowDimension(3)->setVisible(false);
        $first->getColumnDimension('D')->setVisible(false);
        $second = new Worksheet($book, 'Archive');
        $book->addSheet($second);
        $second->setCellValue('B2', 12.4);
        $second->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $path = tempnam(sys_get_temp_dir(), 'structure_xlsx_');
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            // PhpSpreadsheet's writer omits the row hidden attribute for this table fixture.
            // Patch the OOXML itself so the fixture exercises native reader behavior.
            $zip = new \ZipArchive;
            $zip->open($path);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml', preg_replace('/<row r="3"/', '<row r="3" hidden="1"', $sheetXml));
            $zip->close();
            $parser = new SpreadsheetTextExtractor;
            $source = $parser->extractStructured($path);
            self::assertSame($parser->extract($path), $source->text);
            self::assertSame($this->legacyXlsxText($path), $source->text);
            self::assertSame('12.4', $source->node('sheet:0/cell:B2')['text']);
            self::assertNotSame($source->node('sheet:0/cell:B2')['id'], $source->node('sheet:1/cell:B2')['id']);
            self::assertSame(['C1:D1'], $source->node('sheet:0/cell:C1')['meta']['merges']);
            self::assertFalse($source->node('sheet:0/cell:A3')['meta']['row_visible']);
            self::assertSame('veryHidden', $source->node('sheet:1')['meta']['visibility']);
            self::assertSame('=WEBSERVICE("https://example.invalid")', $source->node('sheet:0/cell:E2')['meta']['formula']);
            self::assertArrayHasKey('cache_present', $source->node('sheet:0/cell:E2')['meta']);
            self::assertStringNotContainsString('WEBSERVICE', $source->text);
            [$anchors, $relations] = app(StructuredProvenanceProducer::class)->build($source, []);
            self::assertNotEmpty($anchors);
            self::assertContains('column_header', array_column($relations, 'type'));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_pdf_only_projects_parser_pages(): void
    {
        $path = base_path('tests/Fixtures/test_5page.pdf');
        $source = (new DocumentTextExtractor)->extractPdfStructured($path);
        self::assertCount(5, $source->nodes);
        self::assertSame(4, substr_count($source->text, "\f"));
        self::assertSame('unavailable', $source->capabilities['tables']);
        self::assertSame([], array_filter($source->nodes, static fn ($node) => $node['type'] === 'table'));
    }

    public function test_xlsx_raw_display_date_system_and_special_types_remain_distinct(): void
    {
        $book = new Spreadsheet;
        $book->setExcelCalendar(1904);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 0.155);
        $sheet->getStyle('A1')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->setCellValue('B1', 45292);
        $sheet->getStyle('B1')->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
        $sheet->setCellValueExplicit('C1', '00123', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D1', '#DIV/0!', DataType::TYPE_ERROR);
        $sheet->setCellValue('E1', true);
        $rich = new RichText;
        $rich->createText('North | South');
        $sheet->setCellValue('F1', $rich);
        $path = tempnam(sys_get_temp_dir(), 'special_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = (new SpreadsheetTextExtractor)->extractStructured($path);
            self::assertSame(0.155, $source->node('sheet:0/cell:A1')['meta']['raw']);
            self::assertSame('15.5%', $source->node('sheet:0/cell:A1')['meta']['display']);
            self::assertSame(1904, $source->node('sheet:0')['meta']['date_system']);
            self::assertNotSame('45292', $source->node('sheet:0/cell:B1')['meta']['display']);
            self::assertSame('00123', $source->node('sheet:0/cell:C1')['meta']['raw']);
            self::assertSame('#DIV/0!', $source->node('sheet:0/cell:D1')['text']);
            self::assertSame('b', $source->node('sheet:0/cell:E1')['meta']['cell_type']);
            self::assertSame('North | South', $source->node('sheet:0/cell:F1')['meta']['raw']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_xlsx_structural_node_ceiling_preserves_complete_text_and_marks_partial_proof(): void
    {
        config(['document_processing.xlsx_max_structural_nodes' => 3]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Metric', 'FY2025'], ['Revenue', 12.4], ['Expense', 8.1]]);
        $sheet->addTable(new Table('A1:B3', 'BoundedTable'));
        $path = tempnam(sys_get_temp_dir(), 'bounded_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = (new SpreadsheetTextExtractor)->extractStructured($path);
            self::assertCount(3, $source->nodes);
            self::assertTrue($source->capabilities['nodes_truncated']);
            self::assertSame('partial', $source->capabilities['cells']);
            self::assertNull($source->node('sheet:0/cell:B2'));
            self::assertStringContainsString('Revenue | 12.4', $source->text);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
