<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\OcrResult;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\PdfHybridAssembler;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\SourceProvenanceReader;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Services\Intelligence\StructuralClaimGuard;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StructuredProvenanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $type, string $hash): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Native.'.$type, 'type' => $type,
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => $hash]);
    }

    private function evidence(Document $document, string $quote, string $value, string $period,
        array $fields = []): DocumentEvidence
    {
        $start = mb_strpos($document->extracted_text, $quote);

        return DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', $document->id.$quote.$value.$period.json_encode($fields)), 'kind' => 'metric',
            'data' => array_merge(['value' => $value, 'period' => $period], $fields),
            'sources' => [['quote' => $quote, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen($quote), 'span_id' => 'fixture']]]);
    }

    public function test_native_xlsx_table_binds_its_own_header_and_rejects_other_sheet(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Current');
        $sheet->fromArray([['Metric', 'FY2025'], ['Revenue', 12.4]]);
        $sheet->addTable(new Table('A1:B2', 'CurrentTable'));
        $sheet->setCellValue('D1', 'Metric');
        $sheet->setCellValue('E1', 'FY2023');
        $sheet->setCellValue('D2', 'Other');
        $sheet->setCellValue('E2', 99);
        $sheet->addTable(new Table('D1:E2', 'OtherTable'));
        $other = $book->createSheet();
        $other->setTitle('Other');
        $other->setCellValue('B1', 'FY2024');
        $path = tempnam(sys_get_temp_dir(), 'integration_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            $document = $this->document('XLSX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            app(StructuredExtractionStore::class)->publish($document, $source);
            $row = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2025', ['label' => 'Revenue']);
            $foreignPeriod = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2024');
            $foreignTablePeriod = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2023');
            $substring = $this->evidence($document, 'Revenue | 12.4', '2.4', 'FY2025');
            $producer = app(TextRoleProvenanceProducer::class);
            $id = $producer->publish($document);
            self::assertSame($id, $producer->publish($document));
            self::assertSame(1, DB::table('structured_extractions')->where('document_id', $document->id)->count());
            $proof = app(SourceProvenanceReader::class)->forEvidence($document,
                [$row->id, $foreignPeriod->id, $foreignTablePeriod->id, $substring->id]);
            self::assertSame('12.4', $proof[$row->id]['roles']['value'][0]['text']);
            self::assertSame('FY2025', $proof[$row->id]['roles']['period'][0]['text']);
            self::assertSame('Revenue', $proof[$row->id]['roles']['metric'][0]['text']);
            self::assertTrue($proof[$row->id]['capabilities']['structural']);
            self::assertTrue(DB::table('source_anchors')->where('document_id', $document->id)
                ->where('kind', 'table_row')->where('structural_path', 'sheet:0/table:CurrentTable/row[2]')->exists());
            self::assertTrue(DB::table('source_anchors')->where('document_id', $document->id)
                ->where('kind', 'table_column')->where('structural_path', 'sheet:0/table:CurrentTable/column[B]')->exists());
            self::assertArrayNotHasKey('period', $proof[$foreignPeriod->id]['roles']);
            self::assertArrayNotHasKey('period', $proof[$foreignTablePeriod->id]['roles']);
            self::assertArrayNotHasKey('value', $proof[$substring->id]['roles'] ?? []);
            self::assertFalse(app(StructuralClaimGuard::class)->compatible('FY2024 revenue was 12.4',
                ['data' => $foreignPeriod->data, 'source_provenance' => $proof[$foreignPeriod->id]]));
            self::assertFalse(app(StructuralClaimGuard::class)->compatible(
                'FY2024 revenue was 12.4', ['source_provenance' => $proof[$row->id]]));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_native_docx_cell_and_header_bind_without_prose_value_confusion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'integration_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
<w:p><w:r><w:t>Earlier 12.4</w:t></w:r></w:p>
<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>FY2025</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>Revenue</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>12.4</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
</w:body></w:document>
XML);
        $zip->close();
        try {
            $source = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            $document = $this->document('DOCX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $row = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2025');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$row->id])[$row->id];
            self::assertSame('12.4', $proof['roles']['value'][0]['text']);
            self::assertSame('FY2025', $proof['roles']['period'][0]['text']);
            self::assertStringContainsString('tr[2]/tc[2]', $proof['roles']['value'][0]['path']);
            self::assertTrue(DB::table('source_anchors')->where('document_id', $document->id)
                ->where('kind', 'table_row')->where('structural_path', 'body/tbl[1]/tr[2]')->exists());
        } finally {
            unlink($path);
        }
    }

    public function test_docx_grid_span_and_nested_table_keep_header_ownership(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'merged_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr>
<w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc>
<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>FY2025</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>Revenue</w:t></w:r></w:p></w:tc>
<w:tc><w:p><w:r><w:t>12.4</w:t></w:r></w:p></w:tc>
<w:tc><w:p><w:r><w:t>17.8</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr>
<w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc>
<w:tc><w:p><w:r><w:t>FY2024</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>Expense</w:t></w:r></w:p>
<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Nested 12.4</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:tc>
<w:tc><w:p><w:r><w:t>9.1</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
</w:body></w:document>
XML);
        $zip->close();
        try {
            $source = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            $merged = $source->node('body/tbl[1]/tr[1]/tc[2]');
            self::assertSame(2, $merged['meta']['grid_span']);
            self::assertSame(2, $merged['meta']['grid_column']);
            self::assertSame(3, $source->node('body/tbl[1]/tr[2]/tc[3]')['meta']['grid_column']);
            self::assertNotNull($source->node('body/tbl[2]/tr[2]/tc[1]/tbl[1]/tr[1]/tc[1]'));
            $document = $this->document('DOCX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $correct = $this->evidence($document, 'Revenue | 12.4 | 17.8', '17.8', 'FY2025',
                ['label' => 'Revenue']);
            $wrong = $this->evidence($document, 'Revenue | 12.4 | 17.8', '17.8', 'FY2024');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$correct->id, $wrong->id]);
            self::assertSame('FY2025', $proof[$correct->id]['roles']['period'][0]['text']);
            self::assertSame('Revenue', $proof[$correct->id]['roles']['metric'][0]['text']);
            self::assertArrayNotHasKey('period', $proof[$wrong->id]['roles']);
        } finally {
            unlink($path);
        }
    }

    public function test_xlsx_column_units_formula_and_hidden_row_keep_distinct_status(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Finance');
        $sheet->fromArray([['Metric', 'USD', 'KES'], ['Revenue', 12.4, 15.2],
            ['Hidden', 88, 99], ['Calculated', '=B2*2', 31.2]]);
        $sheet->addTable(new Table('A1:C4', 'FinanceTable'));
        $sheet->getRowDimension(3)->setVisible(false);
        $path = tempnam(sys_get_temp_dir(), 'units_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            // PhpSpreadsheet's table writer reapplies its autofilter and unhides this row.
            // Keep a deterministic OOXML hidden-row fixture after serialization.
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml',
                preg_replace('/<row r="3"/', '<row r="3" hidden="1"', $xml));
            $zip->close();
            $source = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            self::assertSame('B2', $source->node('sheet:0/cell:B2')['meta']['coordinate']);
            self::assertFalse($source->node('sheet:0/cell:B3')['meta']['row_visible'],
                json_encode($source->node('sheet:0/cell:B3')['meta']));
            self::assertSame('=B2*2', $source->node('sheet:0/cell:B4')['meta']['formula']);
            $document = $this->document('XLSX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $correct = $this->evidence($document, 'Revenue | 12.4 | 15.2', '12.4', '',
                ['label' => 'Revenue', 'unit' => 'USD']);
            $wrongUnit = $this->evidence($document, 'Revenue | 12.4 | 15.2', '12.4', '',
                ['unit' => 'KES']);
            $hidden = $this->evidence($document, 'Hidden | 88 | 99', '88', '');
            $formula = $this->evidence($document, 'Calculated | 24.8 | 31.2', '24.8', '');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document,
                [$correct->id, $wrongUnit->id, $hidden->id, $formula->id]);
            self::assertSame('USD', $proof[$correct->id]['roles']['unit'][0]['text']);
            self::assertSame('Revenue', $proof[$correct->id]['roles']['metric'][0]['text']);
            self::assertArrayNotHasKey('unit', $proof[$wrongUnit->id]['roles']);
            self::assertArrayNotHasKey('value', $proof[$hidden->id]['roles'] ?? []);
            self::assertArrayNotHasKey('value', $proof[$formula->id]['roles'] ?? []);
            self::assertFalse(app(StructuralClaimGuard::class)->compatible('Revenue was KES 12.4',
                ['data' => $wrongUnit->data, 'source_provenance' => $proof[$wrongUnit->id]]));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_tesseract_word_location_requires_current_text_and_valid_box(): void
    {
        foreach ([true, false] as $valid) {
            $document = $this->document('PDF', hash('sha256', $valid ? 'valid-ocr' : 'invalid-ocr'));
            $document->forceFill(['extracted_text' => 'Total 12.4'])->save();
            OcrResult::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'page_number' => 1,
                'engine' => 'printed', 'raw_text' => 'Total 12.4', 'confidence' => 0.9,
                'metadata' => ['source' => 'tesseract', 'coordinate_system' => 'image_pixels',
                    'image_width' => 100, 'image_height' => 100,
                    'regions' => [['start' => 6, 'end' => 10, 'left' => $valid ? 60 : 95,
                        'top' => 20, 'width' => 30, 'height' => 12]]]]);
            $row = $this->evidence($document, 'Total 12.4', '12.4', '');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $anchor = DB::table('evidence_role_bindings as b')
                ->join('source_anchors as a', 'a.id', '=', 'b.anchor_id')
                ->where('b.evidence_id', $row->id)->where('b.role', 'value')
                ->first(['a.kind', 'a.modality', 'a.geometry', 'a.page']);
            self::assertSame($valid ? 'ocr_region' : 'text_span', $anchor->kind);
            self::assertSame('ocr', $anchor->modality);
            self::assertSame($valid, $anchor->geometry !== null);
            if ($valid) {
                self::assertSame(1, $anchor->page);
                $row->forceFill(['source_id' => 'evidence:'.$row->id])->save();
                $document->forceFill(['status' => 'Ready'])->save();
                $resolved = app(SourceReferenceResolver::class)->resolve($document->fresh(), $row->source_id);
                self::assertSame('image_pixels_top_left',
                    $resolved['roles']['value'][0]['geometry']['system']);
            }
        }
    }

    public function test_identical_values_need_an_unambiguous_cited_cell_occurrence(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Metric', 'FY2025', 'FY2024'], ['Revenue', 12.4, 12.4]]);
        $sheet->addTable(new Table('A1:C2', 'RepeatedValuesTable'));
        $path = tempnam(sys_get_temp_dir(), 'repeated_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            $document = $this->document('XLSX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $broad = $this->evidence($document, 'Revenue | 12.4 | 12.4', '12.4', 'FY2025');
            $exact = $this->evidence($document, '12.4', '12.4', 'FY2025');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$broad->id, $exact->id]);
            self::assertArrayNotHasKey('value', $proof[$broad->id]['roles'] ?? []);
            self::assertSame('FY2025', $proof[$exact->id]['roles']['period'][0]['text']);
            self::assertStringContainsString('B2', $proof[$exact->id]['roles']['value'][0]['path']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_xlsx_native_table_merged_header_covers_its_declared_columns(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Metric', 'FY2025', null], ['Revenue', 12.4, 17.8]]);
        $sheet->mergeCells('B1:C1');
        $sheet->addTable(new Table('A1:C2', 'MergedHeaderTable'));
        $path = tempnam(sys_get_temp_dir(), 'merged_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            self::assertSame(['B1:C1'], $source->node('sheet:0/cell:B1')['meta']['merges']);
            $document = $this->document('XLSX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $fact = $this->evidence($document, 'Revenue | 12.4 | 17.8', '17.8', 'FY2025');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$fact->id])[$fact->id];
            self::assertSame('FY2025', $proof['roles']['period'][0]['text']);
            self::assertStringContainsString('C2', $proof['roles']['value'][0]['path']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_docx_vertical_merge_uses_only_explicit_restart_row_label(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vmerge_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>
<w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>FY2025</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Revenue</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>12.4</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc><w:p><w:r><w:t>17.8</w:t></w:r></w:p></w:tc></w:tr>
</w:tbl></w:body></w:document>
XML);
        $zip->close();
        try {
            $source = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            self::assertSame('continue', $source->node('body/tbl[1]/tr[3]/tc[1]')['meta']['vertical_merge']);
            $document = $this->document('DOCX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $fact = $this->evidence($document, '17.8', '17.8', 'FY2025', ['label' => 'Revenue']);
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$fact->id])[$fact->id];
            self::assertSame('Revenue', $proof['roles']['metric'][0]['text']);
            self::assertSame('FY2025', $proof['roles']['period'][0]['text']);
        } finally {
            unlink($path);
        }
    }

    public function test_stale_ocr_page_cannot_relabel_current_native_pdf_text(): void
    {
        $document = $this->document('PDF', hash('sha256', 'native-over-ocr'));
        $document->forceFill(['extracted_text' => 'Native 12.4'])->save();
        OcrResult::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'page_number' => 1,
            'engine' => 'printed', 'raw_text' => 'OCR 99.9', 'confidence' => 0.9,
            'metadata' => ['source' => 'tesseract']]);
        $row = $this->evidence($document, 'Native 12.4', '12.4', '');
        app(TextRoleProvenanceProducer::class)->publish($document);
        $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$row->id])[$row->id];
        self::assertSame('embedded_text', $proof['roles']['value'][0]['modality']);
        self::assertSame('text_span', $proof['roles']['value'][0]['kind']);
    }

    public function test_hybrid_page_roles_keep_native_and_ocr_channels_separate(): void
    {
        $document = $this->document('PDF', hash('sha256', 'hybrid-page-fixture'));
        $native = new StructuredDocument('PDF', 'fixture.v1', "Native 12.4\f", [
            ['id' => 'page:1', 'type' => 'page', 'page' => 1, 'path' => 'page:1',
                'start' => 0, 'end' => 11, 'text' => 'Native 12.4',
                'meta' => ['modality' => 'embedded_pdf_text']],
            ['id' => 'page:2', 'type' => 'page', 'page' => 2, 'path' => 'page:2'],
        ], ['empty_text_pages' => [2], 'tables' => 'unavailable']);
        $hybrid = app(PdfHybridAssembler::class)->assemble($native, [
            2 => ['text' => 'OCR 99.9', 'confidence' => 0.87,
                'metadata' => ['geometry' => 'word_boxes']],
        ]);
        app(StructuredExtractionStore::class)->publish($document, $hybrid);
        OcrResult::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'page_number' => 2,
            'engine' => 'printed', 'raw_text' => 'OCR 99.9', 'confidence' => 0.87,
            'metadata' => ['source' => 'tesseract', 'coordinate_system' => 'image_pixels',
                'image_width' => 100, 'image_height' => 100,
                'regions' => [['start' => 4, 'end' => 8, 'left' => 10, 'top' => 20,
                    'width' => 30, 'height' => 10]]]]);
        $nativeFact = $this->evidence($document, 'Native 12.4', '12.4', '');
        $ocrFact = $this->evidence($document, 'OCR 99.9', '99.9', '');
        $nativeFact->update(['sources' => [[...$nativeFact->sources[0], 'page' => 1]]]);
        $ocrFact->update(['sources' => [[...$ocrFact->sources[0], 'page' => 2]]]);
        app(TextRoleProvenanceProducer::class)->publish($document);
        $proofs = app(SourceProvenanceReader::class)->forEvidence($document,
            [$nativeFact->id, $ocrFact->id]);
        self::assertSame('embedded_text', $proofs[$nativeFact->id]['roles']['value'][0]['modality']);
        self::assertSame('ocr', $proofs[$ocrFact->id]['roles']['value'][0]['modality']);
        self::assertSame('ocr_region', $proofs[$ocrFact->id]['roles']['value'][0]['kind']);
        self::assertFalse($proofs[$ocrFact->id]['capabilities']['structural']);
    }

    public function test_header_token_prefix_cannot_prove_a_different_period(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Metric', 'FY20250'], ['Revenue', 12.4]]);
        $sheet->addTable(new Table('A1:B2', 'PrefixYearTable'));
        $path = tempnam(sys_get_temp_dir(), 'prefix_year_xlsx_');
        try {
            (new Xlsx($book))->save($path);
            $source = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            $document = $this->document('XLSX', hash_file('sha256', $path));
            app(StructuredExtractionStore::class)->publish($document, $source);
            $fact = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2025');
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$fact->id])[$fact->id];
            self::assertSame('12.4', $proof['roles']['value'][0]['text']);
            self::assertArrayNotHasKey('period', $proof['roles']);
            self::assertFalse(app(StructuralClaimGuard::class)->compatible('FY2025 revenue was 12.4',
                ['data' => $fact->data, 'source_provenance' => $proof]));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
