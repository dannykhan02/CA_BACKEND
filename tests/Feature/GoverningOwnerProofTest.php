<?php

namespace Tests\Feature;

use App\Http\Resources\KpiResource;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\RowExpander;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class GoverningOwnerProofTest extends TestCase
{
    use DatabaseTransactions;

    public function test_null_company_scope_needs_local_owner_evidence(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\n"
            ."Financial results (USD million)\nMeasure\tFY2023\tFY2024\tFY2025\n"
            ."Revenue (USD million)\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $kpi,
            'E004', 'E003', 'E004'));
        self::assertFalse(DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->exists());
    }

    public function test_blank_scope_inherits_a_single_reporting_entity_through_a_bounded_financial_section(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."FY2025 / ANNUAL REPORT\n2. Financial performance\n"
            ."Financial results by year (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, null);
        self::assertNull($this->record($document, $kpi,
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023'),
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023')));
        $proof = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole()->data['governing_owner'];
        self::assertSame('ACME HOLDINGS PLC', $proof['entity']);
        self::assertSame('reporting_entity_section_table', $proof['relationship']);
        self::assertCount(6, $proof['source_spans']);
        self::assertNull($kpi->fresh()->identity_metadata['scope']);
        self::assertTrue($this->observation($document, $kpi)->integrity['analyticalEligible']);
    }

    public function test_report_identity_does_not_override_a_subsidiary_financial_caption(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."BETA DISTRIBUTION LTD financial results (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
        $row = $this->spanId($document, 'Revenue' . "\t" . '42.0');
        $header = $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023');
        $parent = $this->kpi($document, 'ACME HOLDINGS PLC');
        self::assertNotNull($this->record($document, $parent, $row, $header,
            $this->spanId($document, 'ACME HOLDINGS PLC | ANNUAL', 2), $header));
        $blank = $this->kpi($document, null);
        self::assertNull($this->record($document, $blank, $row, $header, $row, $header));
        self::assertSame('BETA DISTRIBUTION LTD', DocumentEvidence::where('source_id',
            'kpi:'.$blank->id)->sole()->data['governing_owner']['entity']);
    }

    public function test_reporting_entity_inference_refuses_mixed_page_and_conflicting_citation(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."BETA DISTRIBUTION LTD performance is also discussed.\n"
            ."Financial results by year (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
        $row = $this->spanId($document, 'Revenue' . "\t" . '42.0');
        $header = $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023');
        $blank = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $blank,
            $row, $header, $row, $header));
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."BETA DISTRIBUTION LTD revenue grew last year.\n"
            ."Financial results by year (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
        $blank = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $blank,
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023'),
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023')));
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."Financial results by year (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0\n"
            ."BETA DISTRIBUTION LTD disputes this attribution.");
        $blank = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $blank,
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023'),
            $this->spanId($document, 'BETA DISTRIBUTION LTD disputes'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023')));
        $uncitedConflict = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $uncitedConflict,
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023'),
            $this->spanId($document, 'Revenue' . "\t" . '42.0'),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023')));
    }

    public function test_continued_table_requires_a_repeated_local_financial_section(): void
    {
        $base = "ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 1\n"
            ."ACME HOLDINGS PLC\nFY2025 Annual Financial Report\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 2\n"
            ."Financial results by year (USD million)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nCosts\t33.0\t36.0\t41.0\f"
            ."ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\tPage 3\n";
        foreach ([true, false] as $hasCaption) {
            $document = $this->document($base.($hasCaption ? "Financial results (continued)\n" : '')
                ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
            $kpi = $this->kpi($document, null);
            $result = $this->record($document, $kpi,
                $this->spanId($document, 'Revenue' . "\t" . '42.0', 3),
                $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023', 3),
                $this->spanId($document, 'Revenue' . "\t" . '42.0', 3),
                $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023', 3));
            self::assertSame($hasCaption ? null : 'governing_owner_unverified', $result);
        }
        $subsidiary = str_replace('Financial results by year (USD million)',
            'BETA DISTRIBUTION LTD financial results (USD million)', $base);
        $document = $this->document($subsidiary."Financial results (continued)\n"
            ."Measure (USD million)\tFY2023\tFY2024\tFY2025\nRevenue\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $kpi,
            $this->spanId($document, 'Revenue' . "\t" . '42.0', 3),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023', 3),
            $this->spanId($document, 'Revenue' . "\t" . '42.0', 3),
            $this->spanId($document, 'Measure (USD million)' . "\t" . 'FY2023', 3)));
    }

    public function test_explicit_company_claim_has_persisted_structural_owner_proof(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | ANNUAL REPORT | FY2025\n"
            ."Financial results (USD million)\nMeasure\tFY2023\tFY2024\tFY2025\n"
            ."Revenue (USD million)\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, 'ACME HOLDINGS PLC');
        self::assertNull($this->record($document, $kpi, 'E004', 'E003', 'E001'));
        $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
        self::assertSame('ACME HOLDINGS PLC', $row->data['governing_owner']['entity']);
        self::assertSame('cited_section_to_table', $row->data['governing_owner']['relationship']);
        self::assertContains('governing_owner', $row->data['source_fields_verified']);
        $observation = $this->observation($document, $kpi);
        self::assertTrue($observation->integrity['analyticalEligible']);
        self::assertSame('verified', $observation->integrity['ownerVerification']);
        self::assertSame(hash('sha256', $document->extracted_text),
            $row->data['governing_owner']['source_revision']);
        self::assertContains('governing_owner', array_merge(...array_column($row->sources, 'roles')));
        $request = Request::create('/api/documents/'.$document->id.'/intelligence');
        $request->attributes->set('kpi_evidence', collect(['kpi:'.$kpi->id => $row]));
        $request->attributes->set('document_source_revision', hash('sha256', $document->extracted_text));
        $card = (new KpiResource($kpi))->resolve($request);
        self::assertTrue($card['analyticalEligible']);
        self::assertSame('verified', $card['ownerVerification']);
        self::assertSame('ACME HOLDINGS PLC', $card['governingOwner']);
    }

    public function test_parent_banner_cannot_own_subsidiary_table_and_blank_scope_uses_caption(): void
    {
        $document = $this->document("PARENT HOLDINGS PLC | FY2025\n"
            ."SUBSIDIARY DISTRIBUTION LTD financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $parent = $this->kpi($document, 'PARENT HOLDINGS PLC');
        self::assertSame('subject_relationship_unverified', $this->record($document, $parent,
            'E004', 'E003', 'E001'));
        $blank = $this->kpi($document, null);
        self::assertNull($this->record($document, $blank, 'E004', 'E003', 'E004'));
        $proof = DocumentEvidence::where('source_id', 'kpi:'.$blank->id)->sole()->data['governing_owner'];
        self::assertSame('SUBSIDIARY DISTRIBUTION LTD', $proof['entity']);
        self::assertSame('table_caption', $proof['relationship']);
        self::assertNull($blank->fresh()->identity_metadata['scope']);
    }

    public function test_two_companies_on_one_page_with_identical_values_remain_separate(): void
    {
        $document = $this->document("ACME HOLDINGS PLC financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0\n"
            ."BETA DISTRIBUTION LTD financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $first = $this->kpi($document, null);
        $second = $this->kpi($document, null);
        self::assertNull($this->record($document, $first, 'E003', 'E002', 'E003'));
        self::assertNull($this->record($document, $second, 'E006', 'E005', 'E006'));
        $a = $this->observation($document, $first);
        $b = $this->observation($document, $second);
        self::assertNotSame($a->groupKey(), $b->groupKey());
        self::assertNotSame($a->integrity['governingOwner'], $b->integrity['governingOwner']);
        $wrong = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $wrong,
            'E006', 'E005', 'E001'));
    }

    public function test_document_heading_contradicted_by_table_caption_is_rejected(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | FY2025\n"
            ."BETA DISTRIBUTION LTD financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, 'ACME HOLDINGS PLC');
        self::assertSame('subject_relationship_unverified', $this->record($document, $kpi,
            'E004', 'E003', 'E001'));
    }

    public function test_population_metric_does_not_require_a_company_owner(): void
    {
        $document = $this->document("Operational indicators\n"
            ."Measure\tFY2023\tFY2024\tFY2025\n"
            ."On-time deliveries\t92%\t90%\t87%");
        $kpi = $this->kpi($document, 'deliveries', 'On-time deliveries', 'on-time deliveries',
            '87%', '%', 'percentage');
        self::assertNull($this->record($document, $kpi, 'E003', 'E002', 'E003', 'E003'));
        self::assertTrue($this->observation($document, $kpi)->integrity['analyticalEligible']);
        self::assertSame('population', DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)
            ->sole()->data['ownership_requirement']);
    }

    public function test_expanded_siblings_reverify_the_same_owner_relation(): void
    {
        config()->set('document_intelligence.server_evidence_resolution', true);
        $document = $this->document("ACME HOLDINGS PLC financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $input = ['label' => 'Revenue', 'value' => '51.0', 'unit' => 'USD million',
            'period' => 'FY2025', 'identity' => ['concept' => 'revenue', 'scope' => null,
                'metric_type' => 'amount', 'value_basis' => 'actual', 'period' => 'FY2025'],
            'source_context' => ['value_span_id' => 'E003', 'unit_span_id' => 'E003',
                'period_span_id' => 'E002', 'subject_span_id' => 'E003']];
        $expanded = app(RowExpander::class)->expand($document, [$input]);
        self::assertCount(2, $expanded);
        foreach ($expanded as $candidate) {
            $kpi = $this->kpi($document, null, 'Revenue', 'revenue', $candidate['value'],
                'USD million', 'amount', $candidate['period']);
            self::assertNull(app(NormalKpiGrounder::class)->record($document, $kpi, $candidate));
            self::assertSame('ACME HOLDINGS PLC', DocumentEvidence::where('source_id',
                'kpi:'.$kpi->id)->sole()->data['governing_owner']['entity']);
        }
    }

    public function test_stale_revision_and_ambiguous_owner_do_not_pass(): void
    {
        $document = $this->document("ACME HOLDINGS PLC | BETA DISTRIBUTION LTD | FY2025\n"
            ."Financial results (USD million)\nMeasure\tFY2023\tFY2024\tFY2025\n"
            ."Revenue (USD million)\t42.0\t46.0\t51.0");
        $ambiguous = $this->kpi($document, null);
        self::assertSame('governing_owner_unverified', $this->record($document, $ambiguous,
            'E004', 'E003', 'E004'));
        $document = $this->document("ACME HOLDINGS PLC financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, null);
        self::assertNull($this->record($document, $kpi, 'E003', 'E002', 'E003'));
        $segmenter = config('document_intelligence.span_segmenter_version');
        config()->set('document_intelligence.span_segmenter_version', $segmenter.'-revised');
        self::assertContains('governing_owner_unverified',
            $this->observation($document, $kpi)->integrity['reasons']);
        config()->set('document_intelligence.span_segmenter_version', $segmenter);
        $document->forceFill(['extracted_text' => $document->extracted_text."\nrevised"])->save();
        $observation = $this->observation($document->fresh(), $kpi);
        self::assertFalse($observation->integrity['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $observation->integrity['reasons']);
    }

    public function test_persisted_role_without_exact_relationship_proof_is_refused(): void
    {
        $document = $this->document("ACME HOLDINGS PLC financial results (USD million)\n"
            ."Measure\tFY2023\tFY2024\tFY2025\nRevenue (USD million)\t42.0\t46.0\t51.0");
        $kpi = $this->kpi($document, null);
        self::assertNull($this->record($document, $kpi, 'E003', 'E002', 'E003'));
        $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
        $data = $row->data;
        $data['governing_owner']['source_spans'][0]['start_offset']++;
        $row->forceFill(['data' => $data])->save();
        $integrity = $this->observation($document, $kpi)->integrity;
        self::assertFalse($integrity['analyticalEligible']);
        self::assertContains('governing_owner_unverified', $integrity['reasons']);
    }

    private function document(string $text): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'owner-fixture.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'pages' => substr_count($text, "\f") + 1,
            'file_hash' => hash('sha256', $text),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:owner',
            'normal_span_grounding' => true, 'text_hash' => hash('sha256', $text)]])->save();

        return $document;
    }

    private function spanId(Document $document, string $needle, ?int $page = null): string
    {
        $set = app(EvidenceGrounding::class)->spans($document);
        foreach ($set->all() as $span) {
            $source = $set->resolve($span['key']);
            if (($page === null || $source['page'] === $page)
                && str_contains($source['text'], $needle)) {
                return $source['span_id'];
            }
        }
        self::fail('Missing source span: '.$needle);
    }

    private function kpi(Document $document, ?string $scope, string $label = 'Revenue',
        string $concept = 'revenue', string $value = '51.0', string $unit = 'USD million',
        string $type = 'amount', string $period = 'FY2025'): DocumentKpi
    {
        return DocumentKpi::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'label' => $label, 'value' => $value,
            'unit' => $unit, 'period' => $period,
            'identity_metadata' => ['concept' => $concept, 'scope' => $scope,
                'metric_type' => $type, 'value_basis' => 'actual', 'period' => $period]]);
    }

    private function record(Document $document, DocumentKpi $kpi, string $value,
        string $header, string $subject, ?string $unit = null): ?string
    {
        return app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => ['value_span_id' => $value, 'unit_span_id' => $unit ?? $value,
                'period_span_id' => $header, 'subject_span_id' => $subject]]);
    }

    private function observation(Document $document, DocumentKpi $kpi): \App\Services\Intelligence\MetricObservation
    {
        return collect(app(MetricCollector::class)->collect($document)['observations'])
            ->firstWhere('sourceId', 'kpi:'.$kpi->id);
    }
}
