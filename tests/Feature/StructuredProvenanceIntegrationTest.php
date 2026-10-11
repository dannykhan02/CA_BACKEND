<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\SourceProvenanceReader;
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

    private function evidence(Document $document, string $quote, string $value, string $period): DocumentEvidence
    {
        $start = mb_strpos($document->extracted_text, $quote);

        return DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', $document->id.$quote.$value.$period), 'kind' => 'metric',
            'data' => ['value' => $value, 'period' => $period],
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
            $row = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2025');
            $foreignPeriod = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2024');
            $foreignTablePeriod = $this->evidence($document, 'Revenue | 12.4', '12.4', 'FY2023');
            $producer = app(TextRoleProvenanceProducer::class);
            $id = $producer->publish($document);
            self::assertSame($id, $producer->publish($document));
            self::assertSame(1, DB::table('structured_extractions')->where('document_id', $document->id)->count());
            $proof = app(SourceProvenanceReader::class)->forEvidence($document,
                [$row->id, $foreignPeriod->id, $foreignTablePeriod->id]);
            self::assertSame('12.4', $proof[$row->id]['roles']['value'][0]['text']);
            self::assertSame('FY2025', $proof[$row->id]['roles']['period'][0]['text']);
            self::assertTrue($proof[$row->id]['capabilities']['structural']);
            self::assertArrayNotHasKey('period', $proof[$foreignPeriod->id]['roles']);
            self::assertArrayNotHasKey('period', $proof[$foreignTablePeriod->id]['roles']);
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
        } finally {
            unlink($path);
        }
    }
}
