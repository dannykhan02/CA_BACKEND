<?php

namespace Tests\Feature;

use App\Http\Resources\KpiResource;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Extraction\NativeCellProjector;
use App\Services\Intelligence\AnalyticalComparisonBuilder;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV8;
use Database\Seeders\DocumentInsightsPromptSeederV9;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class NormalRouteProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_v8_is_versioned_and_requires_explicit_activation(): void
    {
        (new DocumentInsightsPromptSeederV8)->run();
        $prompt = AiPrompt::where('name', 'document_insights')->where('version', 8)->sole();
        $this->assertFalse($prompt->active);
        $this->assertStringContainsString('source_context', $prompt->template);
        $this->assertStringContainsString('value_span_id', $prompt->template);
        $this->assertStringContainsString('"proposed"', $prompt->template);
    }

    public function test_v9_successor_clarifies_scope_period_and_proposal_without_activating_it(): void
    {
        (new DocumentInsightsPromptSeederV8)->run();
        AiPrompt::activate('document_insights', 8);
        (new DocumentInsightsPromptSeederV9)->run();
        $prompt = AiPrompt::where('name', 'document_insights')->where('version', 9)->sole();
        self::assertFalse($prompt->active);
        self::assertTrue(AiPrompt::where('name', 'document_insights')->where('version', 8)->sole()->active);
        self::assertStringContainsString('scope is only its source-supported entity or population', $prompt->template);
        self::assertStringContainsString('column says only Q3', $prompt->template);
        self::assertStringContainsString('leave period null', $prompt->template);
        self::assertStringNotContainsString('value_basis is actual or target.', $prompt->template);
        self::assertStringContainsString('value_basis is actual, target, budget, forecast, proposed', $prompt->template);
    }

    public function test_normal_kpi_requires_a_field_supported_value_and_governing_unit_heading(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $text = "ACME HOLDINGS PLC Metric | Q3 2026 | KES million\nRevenue | 51.0 | Actual\nCosts | 41.0 | Actual";
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Quarterly report.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => hash('sha256', $text), 'extracted_text' => $text,
            'pages' => 1]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:fixture',
            'normal_span_grounding' => true]])->save();
        $verified = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue', 'value' => '51.0',
            'unit' => 'KES million', 'period' => 'Q3 2026',
            'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC', 'metric_type' => 'amount',
                'value_basis' => 'actual']]);
        $limited = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Costs', 'value' => '41.0',
            'unit' => 'KES million', 'period' => 'Q3 2026']);
        $grounder = app(NormalKpiGrounder::class);
        $this->assertSame('unit_source_mismatch', $grounder->record($document, $verified,
            ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E003',
                'period_span_id' => 'E001']]));
        $this->assertSame(0, DocumentEvidence::where('document_id', $document->id)->count());
        $this->assertNull($grounder->record($document, $verified,
            ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001',
                'period_span_id' => 'E001', 'subject_span_id' => 'E001']]));
        $evidence = DocumentEvidence::where('source_id', 'kpi:'.$verified->id)->sole();
        $this->assertSame(['value', 'unit', 'period', 'subject', 'governing_owner'],
            $evidence->data['source_fields_verified']);
        $this->assertCount(2, $evidence->sources);
        $document->forceFill(['status' => 'Ready'])->save();
        $resolved = app(SourceReferenceResolver::class)->resolve($document->fresh(), 'kpi:'.$verified->id);
        $this->assertSame('CURRENT', $resolved['state']);
        $this->assertSame('EXACT', $resolved['matchState']);
        $this->assertCount(2, $resolved['fieldSources']);
        $observations = app(MetricCollector::class)->collect($document)['observations'];
        $this->assertCount(2, $observations);
        $bySource = collect($observations)->keyBy('sourceId');
        $this->assertTrue($bySource['kpi:'.$verified->id]->integrity['analyticalEligible']);
        $this->assertFalse($bySource['kpi:'.$limited->id]->integrity['analyticalEligible']);

        // An explicit rejection always wins, even if a stale evidence row remains linked.
        $verified->forceFill(['identity_metadata' => [...$verified->identity_metadata, 'grounding_rejection' => 'period_source_mismatch']])->save();
        $afterRejection = collect(app(MetricCollector::class)->collect($document)['observations'])
            ->keyBy('sourceId')['kpi:'.$verified->id];
        $this->assertFalse($afterRejection->integrity['analyticalEligible']);
        $this->assertContains('period_source_mismatch', $afterRejection->integrity['reasons']);
        $request = Request::create('/api/documents/'.$document->id);
        $request->attributes->set('kpi_evidence', collect(['kpi:'.$verified->id => $evidence]));
        $api = (new KpiResource($verified->refresh()))->toArray($request);
        $this->assertFalse($api['analyticalEligible']);
        $this->assertContains('period_source_mismatch', $api['limitationReasons']);
    }

    public function test_conflicting_currency_and_scale_in_value_row_reject_table_heading_claim(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        foreach ([['USD 51.0', 'quantity_mismatch'], ['KES USD 51.0', 'quantity_mismatch'],
            ['51.0 billion', 'scale_mismatch']] as [$written, $reason]) {
            $text = "Metric | Q3 2026 | KES million\nRevenue | {$written} | Actual";
            $document = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => 'Report.pdf', 'type' => 'PDF',
                'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
                'year' => 2026, 'file_hash' => hash('sha256', $text), 'extracted_text' => $text, 'pages' => 1]);
            $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:conflict',
                'normal_span_grounding' => true]])->save();
            $kpi = DocumentKpi::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'label' => 'Revenue', 'value' => '51.0', 'unit' => 'KES million', 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => 'revenue', 'metric_type' => 'amount',
                    'value_basis' => 'actual']]);
            $this->assertSame($reason, app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001',
                    'period_span_id' => 'E001']]));
            $this->assertSame(0, DocumentEvidence::where('document_id', $document->id)->count());
        }
    }

    public function test_normal_grounding_resolves_a_partial_quarter_only_from_its_verified_heading(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        foreach ([['ACME HOLDINGS PLC Metric | Q3 2026 | KES million', null, 'Q3 2026'],
            ['ACME HOLDINGS PLC Metric | Q3 2025 and Q3 2026 | KES million', 'period_ambiguous', 'Q3']] as [$heading, $rejection, $expectedPeriod]) {
            $text = $heading."\nRevenue | 51.0 | Actual";
            $document = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => 'Quarterly report.pdf', 'type' => 'PDF',
                'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
                'year' => 2026, 'file_hash' => hash('sha256', $text), 'extracted_text' => $text,
                'pages' => 1]);
            $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:partial-quarter',
                'normal_span_grounding' => true]])->save();
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Revenue', 'value' => '51.0',
                'unit' => 'KES million', 'period' => 'Q3',
                'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC', 'metric_type' => 'amount',
                    'value_basis' => 'actual']]);
            $this->assertSame($rejection, app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001',
                    'period_span_id' => 'E001', 'subject_span_id' => 'E001']]));
            $this->assertSame($expectedPeriod, $kpi->fresh()->period);
            if ($rejection === null) {
                $this->assertSame('Q3', DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole()
                    ->data['raw_period']);
                $this->assertTrue(app(MetricCollector::class)->collect($document)['observations'][0]
                    ->integrity['analyticalEligible']);
            } else {
                $this->assertSame(0, DocumentEvidence::where('document_id', $document->id)->count());
            }
        }
    }

    public function test_twelve_published_style_measurements_keep_values_units_period_subject_and_field_sources(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $groups = [
            ['KES million', [
                ['Revenue', '51.0', 'revenue', 'actual'],
                ['Operating Profit', '10.0', 'operating profit', 'actual'],
                ['Operating Costs', '41.0', 'operating costs', 'actual'],
                ['Recovery Investment', '2.4', 'recovery investment', 'proposed'],
            ]],
            ['%', [
                ['Operating Margin', '19.6', 'operating margin', 'actual'],
                ['On-Time Delivery', '87', 'on-time delivery', 'actual'],
                ['On-Time Delivery Target', '95', 'on-time delivery', 'target'],
            ]],
            ['cases', [
                ['Customer Complaints', '133', 'customer complaints', 'actual'],
                ['Customer Complaints Target', '90', 'customer complaints', 'target'],
            ]],
            ['shipments', [['Shipments Completed', '14900', 'shipments completed', 'actual']]],
            ['headcount', [['Quarter-End Employees', '123', 'quarter-end employees', 'actual']]],
            ['percentage points', [['Delivery Gap', '8', 'delivery gap', 'actual']]],
        ];
        $lines = [];
        $records = [];
        $spanNumber = 0;
        foreach ($groups as [$unit, $rows]) {
            $headerSpan = sprintf('E%03d', ++$spanNumber);
            $lines[] = "ACME HOLDINGS PLC Business Metric | Q3 2026 | {$unit}";
            foreach ($rows as [$label, $value, $concept, $basis]) {
                $valueSpan = sprintf('E%03d', ++$spanNumber);
                $lines[] = "{$label} | {$value} | {$basis}";
                $records[] = compact('label', 'value', 'concept', 'basis', 'unit', 'headerSpan', 'valueSpan');
            }
        }
        $text = implode("\n", $lines);
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Quarterly board performance.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => hash('sha256', $text), 'extracted_text' => $text, 'pages' => 1]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:twelve-metrics',
            'normal_span_grounding' => true]])->save();
        foreach ($records as $record) {
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => $record['label'],
                'value' => $record['value'], 'unit' => $record['unit'], 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => $record['concept'], 'scope' => 'ACME HOLDINGS PLC',
                    'metric_type' => in_array($record['unit'], ['%', 'percentage points'], true)
                        ? 'percentage' : (in_array($record['unit'], ['cases', 'shipments', 'headcount'], true)
                            ? 'count' : 'amount'), 'value_basis' => $record['basis']]]);
            $this->assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => $record['valueSpan'],
                    'unit_span_id' => $record['headerSpan'], 'period_span_id' => $record['headerSpan'],
                    'subject_span_id' => $record['headerSpan']]]), $record['label']);
            $source = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
            $this->assertSame($record['value'], $source->data['raw_value']);
            $this->assertSame($record['unit'], $source->data['raw_unit']);
            $this->assertSame('Q3 2026', $source->data['period']);
            $this->assertSame('ACME HOLDINGS PLC', $source->data['subject']);
            $this->assertSame(['value', 'unit', 'period', 'subject', 'governing_owner'],
                $source->data['source_fields_verified']);
        }
        $this->assertSame(12, DocumentEvidence::where('document_id', $document->id)->count());
        $observations = app(MetricCollector::class)->collect($document)['observations'];
        $this->assertCount(12, $observations);
        $this->assertSame(12, count(array_filter($observations,
            fn ($observation) => $observation->integrity['analyticalEligible'])),
            json_encode(array_map(fn ($observation) => [$observation->label, $observation->integrity],
                array_filter($observations, fn ($observation) => ! $observation->integrity['analyticalEligible']))));
        $comparisons = app(AnalyticalComparisonBuilder::class)->build($observations);
        $this->assertSame(-8.0, collect($comparisons)->firstWhere('gapUnit', 'percentage points')['gap']);
        $this->assertSame(43.0, collect($comparisons)->firstWhere('gapUnit', 'cases')['gap']);
        $reconciliation = collect($comparisons)->firstWhere('kind', 'financial_reconciliation');
        $this->assertSame(10.0, $reconciliation['gap']);
        $this->assertCount(3, $reconciliation['sourceIds']);
    }

    public function test_native_workbook_table_uses_its_row_and_column_header_relationship(): void
    {
        config()->set('document_intelligence.revision_v1', false);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Performance');
        $sheet->fromArray([['Metric', 'Q3 2026 KES million'], ['Revenue', 51]], null, 'A1');
        $sheet->addTable(new Table('A1:B2', 'ResultsTable'));
        $path = tempnam(sys_get_temp_dir(), 'kpi-workbook-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $structured = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            $user = User::factory()->create();
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
            $user->refresh();
            $document = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => 'Performance.xlsx', 'type' => 'XLSX',
                'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
                'year' => 2026, 'file_hash' => hash_file('sha256', $path), 'pages' => 1]);
            app(StructuredExtractionStore::class)->publish($document, $structured);
            $projected = app(NativeCellProjector::class)->project($document);
            self::assertCount(4, $projected);
            self::assertSame('Revenue', collect($projected)->firstWhere('row_header', true)['text']);
            self::assertSame('Q3 2026 KES million', $projected[1]['text']);
            self::assertTrue($projected[1]['column_header']);
            $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:workbook',
                'normal_span_grounding' => true]])->save();
            $spans = app(EvidenceGrounding::class)->spans($document)->all();
            $header = collect($spans)->first(fn ($span) => str_contains(mb_substr($structured->text,
                $span['start_offset'], $span['end_offset'] - $span['start_offset']), 'KES million'));
            $value = collect($spans)->first(fn ($span) => str_contains(mb_substr($structured->text,
                $span['start_offset'], $span['end_offset'] - $span['start_offset']), 'Revenue'));
            $this->assertNotNull($header);
            $this->assertNotNull($value);
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Revenue', 'value' => '51',
                'unit' => 'KES million', 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => 'revenue', 'metric_type' => 'amount',
                    'value_basis' => 'actual']]);
            $this->assertSame('governing_owner_unverified', app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => $value['key'], 'unit_span_id' => $header['key'],
                    'period_span_id' => $header['key']]]));
            $this->assertFalse(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
        } finally {
            @unlink($path);
        }
    }

    public function test_native_payroll_table_uses_metric_column_and_employee_row_without_promoting_an_account_number(): void
    {
        config()->set('document_intelligence.revision_v1', false);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Payroll');
        $sheet->fromArray([
            ['Employee', 'Salary KES Q3 2026', 'Deduction KES Q3 2026', 'Bank account'],
            ['Alice', 1000, 100, '00123456789'],
        ], null, 'A1');
        $sheet->addTable(new Table('A1:D2', 'PayrollTable'));
        $path = tempnam(sys_get_temp_dir(), 'payroll-workbook-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $structured = app(SpreadsheetTextExtractor::class)->extractStructured($path);
            $user = User::factory()->create();
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
            $user->refresh();
            $document = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => 'Pay records.xlsx', 'type' => 'XLSX',
                'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
                'year' => 2026, 'file_hash' => hash_file('sha256', $path), 'pages' => 1]);
            app(StructuredExtractionStore::class)->publish($document, $structured);
            $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:payroll',
                'normal_span_grounding' => true]])->save();
            $spans = app(EvidenceGrounding::class)->spans($document)->all();
            $header = collect($spans)->first(fn ($span) => str_contains(mb_substr($structured->text,
                $span['start_offset'], $span['end_offset'] - $span['start_offset']), 'Salary KES Q3 2026'));
            $row = collect($spans)->first(fn ($span) => str_contains(mb_substr($structured->text,
                $span['start_offset'], $span['end_offset'] - $span['start_offset']), 'Alice'));
            $this->assertNotNull($header);
            $this->assertNotNull($row);
            foreach ([['Salary', '1000'], ['Deduction', '100']] as [$label, $value]) {
                $kpi = DocumentKpi::create(['document_id' => $document->id,
                    'workspace_id' => $document->workspace_id, 'label' => $label, 'value' => $value,
                    'unit' => 'KES', 'period' => 'Q3 2026',
                    'identity_metadata' => ['concept' => $label, 'scope' => 'Alice',
                        'metric_type' => 'amount', 'value_basis' => 'actual']]);
                $this->assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
                    ['source_context' => ['value_span_id' => $row['key'],
                        'unit_span_id' => $header['key'], 'period_span_id' => $header['key'],
                        'subject_span_id' => $row['key']]]), $label);
            }
            $identifier = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => 'Bank account',
                'value' => '00123456789', 'unit' => null, 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => 'bank account', 'scope' => 'Alice',
                    'metric_type' => 'identifier', 'value_basis' => 'actual']]);
            $this->assertSame('non_measurement_numeric', app(NormalKpiGrounder::class)->record(
                $document, $identifier, ['source_context' => ['value_span_id' => $row['key'],
                    'period_span_id' => $header['key'], 'subject_span_id' => $row['key']]]));
            $this->assertSame(2, DocumentEvidence::where('document_id', $document->id)->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_verified_actuals_and_targets_produce_correct_point_and_case_gaps(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $text = "Measure | Q3 2026 | %\nDelivery performance | 87 | Actual\nDelivery target | 95 | Target\n"
            ."Measure | Q3 2026 | cases\nComplaints | 133 | Actual\nComplaints target | 90 | Target";
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Quarterly review.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => hash('sha256', $text), 'extracted_text' => $text, 'pages' => 1]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:targets',
            'normal_span_grounding' => true]])->save();
        foreach ([
            ['Delivery performance', '87', '%', 'delivery', 'actual', 'E002', 'E001'],
            ['Delivery target', '95', '%', 'delivery', 'target', 'E003', 'E001'],
            ['Complaints', '133', 'cases', 'complaints', 'actual', 'E005', 'E004'],
            ['Complaints target', '90', 'cases', 'complaints', 'target', 'E006', 'E004'],
        ] as [$label, $value, $unit, $concept, $basis, $valueSpan, $headerSpan]) {
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => $label,
                'value' => $value, 'unit' => $unit, 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => $concept,
                    'scope' => $concept === 'delivery' ? 'Delivery' : 'Complaints',
                    'metric_type' => $unit === '%' ? 'percentage' : 'count',
                    'value_basis' => $basis]]);
            $this->assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => $valueSpan,
                    'unit_span_id' => $headerSpan, 'period_span_id' => $headerSpan,
                    'subject_span_id' => $valueSpan]]));
        }
        $observations = app(MetricCollector::class)->collect($document)['observations'];
        $comparisons = app(AnalyticalComparisonBuilder::class)->build($observations);
        $this->assertCount(2, $comparisons);
        $byUnit = collect($comparisons)->keyBy('gapUnit');
        $this->assertSame(-8.0, $byUnit['percentage points']['gap']);
        $this->assertSame('below', $byUnit['percentage points']['direction']);
        $this->assertSame(43.0, $byUnit['cases']['gap']);
        $this->assertSame('above', $byUnit['cases']['direction']);
        $this->assertCount(2, $byUnit['cases']['sourceIds']);
    }

    public function test_explicit_span_id_pins_the_second_identical_occurrence_without_quote_search(): void
    {
        config()->set('document_intelligence.revision_v1', true);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $sentence = 'The reserve is KES 7 million.';
        $text = $sentence."\f".$sentence;
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Reserve.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'reserve file'),
            'extracted_text' => $text, 'pages' => 2]);
        $revision = app(DocumentRevisionPublisher::class)->begin($document);
        $this->assertNotNull($revision);
        $key = 'normal:'.$revision->id;
        $serviceEligible = app(NormalRouteProvenance::class)->eligible($document, $text);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => $key,
            'normal_span_grounding' => $serviceEligible]])->save();
        $this->assertTrue($serviceEligible);
        $revision->forceFill(['pipeline_key' => $key])->save();

        $service = app(NormalRouteProvenance::class);
        $rendered = $service->promptText($document->fresh(), $text);
        $this->assertStringContainsString('[E001]', $rendered);
        $this->assertStringContainsString('[E002]', $rendered);
        $risk = DocumentRisk::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'intelligence_revision_id' => $revision->id,
            'title' => 'Reserve', 'description' => 'Reserve', 'severity' => 'medium',
            'confidence' => .9, 'evidence' => $sentence, 'status' => 'open',
            'prompt_version' => 'test']);
        $service->record($document->fresh(), 'risk', (string) $risk->id,
            ['source_span_id' => 'E002', 'evidence' => $sentence]);
        $service->record($document->fresh(), 'risk', '2',
            ['source_span_id' => 'E001', 'evidence' => 'An invented quotation']);
        $service->record($document->fresh(), 'risk', '3',
            ['source_span_id' => 'E999', 'evidence' => $sentence]);
        $this->assertSame(1, DocumentEvidence::where('document_id', $document->id)->count());

        $service->publish($document->fresh());
        $setId = DB::table('source_provenance_sets')
            ->where('document_id', $document->id)->value('id');
        $this->assertNotNull($setId);
        DocumentIntelligenceSummary::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'intelligence_revision_id' => $revision->id,
            'executive_summary' => 'The reserve is recorded.', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
                'stage' => $stage, 'status' => 'completed']);
        }
        app(DocumentRevisionPublisher::class)->publish($document, $revision->id);
        $this->assertSame($setId, $revision->fresh()->provenance_set_id);
        $sourceId = 'risk:'.$risk->id;
        $source = app(SourceReferenceResolver::class)->resolve($document->fresh(), $sourceId);
        $this->assertSame('CURRENT', $source['state']);
        $this->assertSame('EXACT', $source['matchState']);
        $this->assertSame(mb_strlen($sentence) + 1, $source['source']['range']['start']);
        $this->assertSame($revision->id, $source['intelligenceRevisionId']);
        $this->actingAs($user, 'sanctum')->getJson("/api/documents/{$document->id}/sources/{$sourceId}")
            ->assertOk()->assertJsonPath('data.matchState', 'EXACT')
            ->assertJsonPath('data.source.page', 2);
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);
        $this->actingAs($outsider->fresh(), 'sanctum')
            ->getJson("/api/documents/{$document->id}/sources/{$sourceId}")->assertForbidden();
    }
}
