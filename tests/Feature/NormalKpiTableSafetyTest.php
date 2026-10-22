<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\RowExpander;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NormalKpiTableSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_delimiters_and_column_alignment_are_verified_without_prose_fallback(): void
    {
        $cases = [
            ['tab aligned', "Measure\tFY2023\tFY2024\tFY2025", "Revenue\t42.0\t46.0\t51.0", 'FY2023', '42.0', 'actual', null],
            ['pipe aligned', '| Measure | FY2023 | FY2024 | FY2025 |', '| Revenue | 42.0 | 46.0 | 51.0 |', 'FY2024', '46.0', 'actual', null],
            ['space aligned', 'Measure        FY2023   FY2024   FY2025', 'Revenue        42.0     46.0     51.0', 'FY2025', '51.0', 'actual', null],
            ['repeated numerical values', 'Measure        FY2023   FY2024   FY2025', 'Revenue        42.0     42.0     51.0', 'FY2024', '42.0', 'actual', null],
            ['wrong period cell', 'Measure        FY2023   FY2024   FY2025', 'Revenue        42.0     46.0     51.0', 'FY2023', '51.0', 'actual', 'value_column_mismatch'],
            ['adjacent column', '| Measure | FY2023 | FY2024 | FY2025 |', '| Revenue | 42.0 | 46.0 | 51.0 |', 'FY2024', '51.0', 'actual', 'value_column_mismatch'],
            ['actual using target value', '| Measure | FY2023 | FY2024 | FY2025 | FY2025 target |', '| Revenue | 42.0 | 46.0 | 51.0 | 55.0 |', 'FY2025', '55.0', 'actual', 'value_column_mismatch'],
            ['valid target', '| Measure | FY2023 | FY2024 | FY2025 | FY2025 target |', '| Revenue | 42.0 | 46.0 | 51.0 | 55.0 |', 'FY2025', '55.0', 'target', null],
            ['ceiling operator missing', '| Measure | FY2023 | FY2024 | FY2025 | FY2025 target |', '| Revenue | 42.0 | 46.0 | 51.0 | ≤ 55.0 |', 'FY2025', '55.0', 'target', 'value_column_mismatch'],
            ['ceiling operator retained', '| Measure | FY2023 | FY2024 | FY2025 | FY2025 target |', '| Revenue | 42.0 | 46.0 | 51.0 | ≤ 55.0 |', 'FY2025', '≤ 55.0', 'target', null],
            ['ambiguous header', '| Measure | FY2023 | FY2023 | FY2025 |', '| Revenue | 42.0 | 46.0 | 51.0 |', 'FY2023', '42.0', 'actual', 'period_column_ambiguous'],
            ['inconsistent widths', '| Measure | FY2023 | FY2024 | FY2025 |', '| Revenue | 42.0 | 51.0 |', 'FY2023', '42.0', 'actual', 'table_column_mismatch'],
            ['unsplittable row', '| Measure | FY2023 | FY2024 | FY2025 |', '| Revenue | 51.0 |', 'FY2023', '51.0', 'actual', 'table_column_mismatch'],
        ];
        foreach ($cases as [$name, $header, $row, $period, $value, $basis, $expected]) {
            $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\n"
                ."Financial results (USD million) | Actual | FY2025\n".$header."\n".$row);
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
                'value' => $value, 'unit' => 'USD million', 'period' => $period,
                'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC',
                    'metric_type' => 'amount', 'value_basis' => $basis, 'period' => $period]]);
            $rejection = app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => 'E004', 'unit_span_id' => 'E002',
                    'period_span_id' => 'E003', 'subject_span_id' => 'E001']]);
            self::assertSame($expected, $rejection, $name);
            $evidence = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->first();
            if ($expected === null) {
                self::assertNotNull($evidence, $name);
                $start = $evidence->data['source_cell_start_offset'];
                $end = $evidence->data['source_cell_end_offset'];
                self::assertSame($evidence->data['source_cell_value'],
                    mb_substr($document->extracted_text, $start, $end - $start), $name);
            } else {
                self::assertNull($evidence, $name);
            }
        }
    }

    public function test_stale_evidence_is_ineligible_after_source_revision_changes(): void
    {
        $text = "ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\n"
            ."Financial results (USD million) | Actual | FY2025\n"
            ."Measure        FY2023   FY2024   FY2025\n"
            ."Revenue        42.0     46.0     51.0";
        $document = $this->document($text);
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '51.0', 'unit' => 'USD million', 'period' => 'FY2025',
            'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC',
                'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => 'FY2025']]);
        self::assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => ['value_span_id' => 'E004', 'unit_span_id' => 'E002',
                'period_span_id' => 'E003', 'subject_span_id' => 'E001']]));
        $document->forceFill(['extracted_text' => str_replace('51.0', '52.0', $text)])->save();
        $observation = collect(app(MetricCollector::class)->collect($document->fresh())['observations'])
            ->firstWhere('sourceId', 'kpi:'.$kpi->id);
        self::assertFalse($observation->integrity['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $observation->integrity['reasons']);
    }

    public function test_model_citations_cannot_be_replayed_against_a_different_text_revision(): void
    {
        $document = $this->document("Measure\tFY2023\tFY2024\nRevenue\t42.0\t46.0");
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
            'text_hash' => hash('sha256', 'different source document')]])->save();
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '46.0', 'unit' => null, 'period' => 'FY2024',
            'identity_metadata' => ['concept' => 'revenue', 'metric_type' => 'amount',
                'value_basis' => 'actual', 'period' => 'FY2024']]);
        foreach ([false, true] as $enabled) {
            config()->set('document_intelligence.server_evidence_resolution', $enabled);
            self::assertSame('missing_source_reference', app(NormalKpiGrounder::class)->record(
                $document, $kpi, ['source_context' => ['value_span_id' => 'E002',
                    'period_span_id' => 'E001']]));
            self::assertFalse(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
        }
    }

    public function test_shared_period_and_unit_header_proves_row_basis_without_confusing_columns(): void
    {
        $cases = [
            ['Q3 2026', 'Revenue | 51.0 | Actual', '51.0', 'actual', null],
            ['Q3 2026', 'Revenue | 55.0 | Target', '55.0', 'target', null],
            ['Q3 2026', 'Revenue | 55.0 | Target', '55.0', 'actual', 'period_column_ambiguous'],
            ['Q3 2025 and Q3 2026', 'Revenue | 51.0 | Actual', '51.0', 'actual', 'period_ambiguous'],
            ['Q3 2026', 'Revenue | USD 51.0 | Actual', '51.0', 'actual', 'quantity_mismatch'],
        ];
        foreach ($cases as [$headerPeriod, $row, $value, $basis, $expected]) {
            $document = $this->document("Measure | {$headerPeriod} | KES million\n".$row);
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
                'value' => $value, 'unit' => 'KES million', 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => 'revenue', 'metric_type' => 'amount',
                    'value_basis' => $basis, 'period' => 'Q3 2026']]);
            self::assertSame($expected, app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001',
                    'period_span_id' => 'E001']]));
            self::assertSame($expected === null,
                DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
        }
        $document = $this->document("Measure | Q1 2026 | Q3 2026 | KES million\n"
            ."Revenue | 42.0 | 51.0 | Actual");
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '51.0', 'unit' => 'KES million', 'period' => 'Q1 2026',
            'identity_metadata' => ['concept' => 'revenue', 'metric_type' => 'amount',
                'value_basis' => 'actual', 'period' => 'Q1 2026']]);
        self::assertSame('value_column_mismatch', app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001',
                'period_span_id' => 'E001']]));
        self::assertFalse(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
    }

    public function test_row_expansion_requires_a_proven_anchor_and_unique_explicit_period_columns(): void
    {
        config()->set('document_intelligence.server_evidence_resolution', true);
        $candidate = ['label' => 'Revenue', 'value' => '46.0', 'unit' => 'KES million',
            'period' => 'Q2 2026', 'identity' => ['concept' => 'revenue',
                'scope' => 'ACME HOLDINGS PLC', 'metric_type' => 'amount',
                'value_basis' => 'actual', 'period' => 'Q2 2026'],
            'source_context' => ['value_span_id' => 'E003', 'period_span_id' => 'E002',
                'unit_span_id' => 'E001', 'subject_span_id' => 'E001']];
        foreach ([
            ["Measure\tQ1 2026\tQ2 2026\nRevenue\t42.0\t46.0", 1],
            ["Measure\tEast\tWest\nRevenue\t42.0\t46.0", 0],
            ["Measure\tQ1 2026\tQ1 2026\nRevenue\t42.0\t46.0", 0],
            ["Measure\tQ1\tQ2\nRevenue\t42.0\t46.0", 0],
            ["Measure\tQ1 2026\tQ2 2026\n\nRevenue\t42.0\t46.0", 0],
        ] as [$table, $count]) {
            $document = $this->document("ACME HOLDINGS PLC | REPORT | Q2 2026\n".$table);
            $expanded = app(RowExpander::class)->expand($document, [$candidate]);
            self::assertCount($count, $expanded);
            if ($count === 1) {
                self::assertSame('Q1 2026', $expanded[0]['identity']['period']);
                self::assertSame('42.0', $expanded[0]['value']);
            }
        }
        $document = $this->document("ACME HOLDINGS PLC | REPORT | Q2 2026\n"
            ."Measure\tQ1 2026\tQ2 2026\nRevenue\t42.0\t46.0");
        self::assertSame([], app(RowExpander::class)->expand($document,
            [[...$candidate, 'value' => '999.0']]));
        $conflict = [...$candidate, 'value' => '999.0', 'period' => 'Q1 2026',
            'identity' => [...$candidate['identity'], 'period' => 'Q1 2026']];
        self::assertSame([], app(RowExpander::class)->expand($document, [$candidate, $conflict]));
    }

    public function test_genuine_prose_still_uses_the_non_table_proof_path(): void
    {
        $document = $this->document('ACME HOLDINGS PLC reported revenue of USD million 51.0 in FY2025.');
        self::assertNotSame('table_row', app(EvidenceGrounding::class)->spans($document)->resolve('E001')['type']);
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '51.0', 'unit' => 'USD million', 'period' => 'FY2025',
            'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC',
                'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => 'FY2025']]);
        self::assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => ['value_span_id' => 'E001', 'unit_span_id' => 'E001',
                'period_span_id' => 'E001', 'subject_span_id' => 'E001']]));
        self::assertNotNull(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->first());
    }

    public function test_compact_monetary_prose_requires_the_exact_printed_value_currency_and_scale(): void
    {
        foreach ([
            ['ACME reported revenue of KES 51.0m in FY2025.', 'KES 51.0m', 'KES million', null],
            ['ACME reported revenue of KES 51.0m in FY2025.', 'KES 51.0', 'KES million', 'source_mismatch'],
            ['ACME reported revenue of KES 51.0m in FY2025.', 'KES 51.0m', 'USD million', 'quantity_mismatch'],
            ['ACME reported revenue of -KES 51.0m in FY2025.', 'KES 51.0m', 'KES million', 'source_mismatch'],
            ['ACME reported revenue of KES 51.0m and again KES 51.0m in FY2025.',
                'KES 51.0m', 'KES million', 'source_mismatch'],
        ] as [$text, $value, $unit, $expected]) {
            $document = $this->document($text);
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
                'value' => $value, 'unit' => $unit, 'period' => 'FY2025',
                'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME',
                    'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => 'FY2025']]);
            self::assertSame($expected, app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => 'E001', 'unit_span_id' => 'E001',
                    'period_span_id' => 'E001', 'subject_span_id' => 'E001']]));
            self::assertSame($expected === null,
                DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
        }
    }

    public function test_northstar_financial_cells_keep_exact_offsets_and_all_twelve_are_eligible(): void
    {
        $path = base_path('tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf');
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 81,
            'year' => 2026, 'pages' => 7, 'file_hash' => hash_file('sha256', $path),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:financial-cells',
            'normal_span_grounding' => true]])->save();
        self::assertSame(126, app(EvidenceGrounding::class)->spans($document)->count());
        $metrics = [
            ['Revenue', 'E029', ['42.0', '46.0', '51.0'], 'KES million'],
            ['Operating costs', 'E030', ['33.0', '36.0', '41.0'], 'KES million'],
            ['Operating profit', 'E031', ['9.0', '10.0', '10.0'], 'KES million'],
            ['Operating margin', 'E032', ['21.4%', '21.7%', '19.6%'], '%'],
        ];
        foreach ($metrics as [$label, $rowId, $values, $unit]) {
            foreach ($values as $index => $value) {
                $period = 'Q'.($index + 1).' 2026';
                $kpi = DocumentKpi::create(['document_id' => $document->id,
                    'workspace_id' => $document->workspace_id, 'label' => $label,
                    'value' => $value, 'unit' => $unit, 'period' => $period,
                    'identity_metadata' => ['concept' => mb_strtolower($label),
                        'scope' => 'Northstar Distribution Ltd',
                        'metric_type' => $unit === '%' ? 'percentage' : 'amount',
                        'quantity_kind' => 'absolute', 'aggregation' => $unit === '%' ? null : 'total',
                        'value_basis' => 'actual', 'period' => $period]]);
                self::assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
                    ['source_context' => ['value_span_id' => $rowId,
                        'unit_span_id' => $unit === '%' ? $rowId : 'E027',
                        'period_span_id' => 'E028', 'subject_span_id' => 'E025']]), $label.' '.$period);
                $evidence = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
                $start = $evidence->data['source_cell_start_offset'];
                $end = $evidence->data['source_cell_end_offset'];
                self::assertSame($evidence->data['source_cell_value'], mb_substr($text, $start, $end - $start));
                self::assertSame(['value', 'unit', 'period', 'subject'], $evidence->data['source_fields_verified']);
            }
        }
        self::assertSame(12, app(MetricCollector::class)->collect($document)['stats']['chartable']);
    }

    private function document(string $text): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'synthetic-table.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'pages' => 1, 'file_hash' => hash('sha256', $text),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:table-safety',
            'normal_span_grounding' => true]])->save();

        return $document;
    }

    public function test_wrong_space_aligned_column_cannot_persist_verified_evidence(): void
    {
        $text = "ACME HOLDINGS PLC  |  ANNUAL REPORT  |  FY2025\n"
            ."Financial results (USD million) | Actual | FY2025\n"
            ."Measure        FY2023   FY2024   FY2025\n"
            ."Revenue        42.0     46.0     51.0";
        $document = $this->document($text);
        $spans = app(EvidenceGrounding::class)->spans($document);
        self::assertSame(['table_row', 'table_row', 'table_row', 'table_row'],
            array_column($spans->all(), 'type'));
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '51.0', 'unit' => 'USD million', 'period' => 'FY2023',
            'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC',
                'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => 'FY2023']]);
        $rejection = app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => ['value_span_id' => 'E004', 'unit_span_id' => 'E002',
                'period_span_id' => 'E003', 'subject_span_id' => 'E001']]);
        $evidence = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->first();
        if ($rejection === null) {
            // Before the correction this confirms the complete persistence exploit,
            // including all four roles and downstream analytical eligibility.
            self::assertSame(['value', 'unit', 'period', 'subject'], $evidence->data['source_fields_verified']);
            self::assertTrue(collect(app(MetricCollector::class)->collect($document)['observations'])
                ->firstWhere('sourceId', 'kpi:'.$kpi->id)->integrity['analyticalEligible']);
        }
        self::assertSame('value_column_mismatch', $rejection);
        self::assertNull($evidence);
    }
}
