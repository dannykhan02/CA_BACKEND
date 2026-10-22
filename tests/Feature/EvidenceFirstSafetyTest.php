<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EvidenceFirstSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_docx_native_grid_rejects_wrong_period_and_accepts_correct_cell(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $xml = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $xml .= '<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach (['Measure', 'Q1 2026', 'Q2 2026', 'Q3 2026'] as $cell) {
            $xml .= '<w:tc><w:p><w:r><w:t>'.$cell.'</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr><w:tr>';
        foreach (['ACME revenue % Q1 2026', '42.0', '46.0', '51.0'] as $cell) {
            $xml .= '<w:tc><w:p><w:r><w:t>'.$cell.'</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr></w:tbl></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        try {
            $source = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            $document = $this->document($source->text, 'DOCX');
            app(StructuredExtractionStore::class)->publish($document, $source);
            $set = app(EvidenceGrounding::class)->spans($document);
            $header = $set->all()[0]['key'];
            $row = $set->all()[1]['key'];
            foreach ([['Q1 2026', '51.0', 'value_column_mismatch'],
                ['Q3 2026', '51.0', null]] as [$period, $value, $expected]) {
                $kpi = $this->kpi($document, 'Revenue', $value, '%', $period, 'ACME', 'revenue');
                $result = app(NormalKpiGrounder::class)->record($document, $kpi,
                    ['source_context' => ['value_span_id' => $row, 'unit_span_id' => $row,
                        'period_span_id' => $period === 'Q1 2026' ? $row : $header,
                        'subject_span_id' => $row]]);
                self::assertSame($expected, $result);
                self::assertSame($expected === null,
                    DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_parent_banner_cannot_own_a_subsidiary_captioned_table(): void
    {
        $document = $this->document("PARENT HOLDINGS PLC | FY2025 | Page 1\n"
            ."SUBSIDIARY DISTRIBUTION LTD financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0", 'PDF');
        $spans = app(EvidenceGrounding::class)->spans($document)->all();
        self::assertCount(4, $spans);
        $kpi = $this->kpi($document, 'Revenue', '51.0', 'USD million', 'FY2025',
            'PARENT HOLDINGS PLC', 'revenue');
        self::assertSame('subject_relationship_unverified', app(NormalKpiGrounder::class)->record(
            $document, $kpi, ['source_context' => ['value_span_id' => 'E004',
                'unit_span_id' => 'E004', 'period_span_id' => 'E003', 'subject_span_id' => 'E001']]));
        self::assertFalse(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
    }

    private function document(string $text, string $type): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'safety-fixture.'.$type, 'type' => $type,
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', $text),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:safety',
            'normal_span_grounding' => true]])->save();
        return $document;
    }

    private function kpi(Document $document, string $label, string $value, string $unit,
        string $period, string $scope, string $concept): DocumentKpi
    {
        return DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => $label,
            'value' => $value, 'unit' => $unit, 'period' => $period,
            'identity_metadata' => ['concept' => $concept, 'scope' => $scope,
                'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => $period]]);
    }
}
