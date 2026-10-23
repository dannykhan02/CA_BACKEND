<?php

namespace Tests\Feature;

use App\Http\Resources\KpiResource;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\MeasurementIntegrity;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\SourceProvenance;
use App\Services\Intelligence\SourceProvenanceReader;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceProvenancePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $text): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Fixture.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'pages' => 1, 'file_hash' => hash('sha256', $text.$user->id),
            'extracted_text' => $text]);
    }

    private function evidence(Document $document, array $data, array $sources): DocumentEvidence
    {
        return DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => $document->ai_pipeline['key'] ?? 'legacy',
            'identity' => hash('sha256', json_encode($data)), 'kind' => 'metric',
            'data' => $data, 'sources' => $sources]);
    }

    public function test_raw_unicode_offsets_and_idempotent_publication(): void
    {
        $text = "Revenue\u{00A0}increased to USD 12.4bn in FY2025.";
        $document = $this->document($text);
        $row = $this->evidence($document, ['value' => 'USD 12.4bn', 'label' => 'Revenue',
            'period' => 'FY2025'], [['quote' => $text, 'start_offset' => 0,
                'end_offset' => mb_strlen($text), 'page' => 1, 'span_id' => 'E001']]);
        $producer = app(TextRoleProvenanceProducer::class);
        $first = $producer->publish($document);
        $second = $producer->publish($document);
        self::assertSame($first, $second);
        self::assertSame(1, DB::table('source_provenance_sets')->count());
        $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$row->id])[$row->id];
        self::assertSame('FIELD_ROLE', $proof['level']);
        self::assertSame('USD 12.4bn', $proof['roles']['value'][0]['text']);
        self::assertSame(mb_strpos($text, 'USD'), $proof['roles']['value'][0]['start_offset']);
    }

    public function test_repeated_prose_value_is_ambiguous_and_remains_textual(): void
    {
        $text = 'Revenue was 12.4, then 12.4 was revised.';
        $document = $this->document($text);
        $row = $this->evidence($document, ['value' => '12.4', 'label' => 'Revenue'],
            [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text), 'page' => 1,
                'span_id' => 'E001']]);
        app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame([], app(SourceProvenanceReader::class)->forEvidence($document, [$row->id]));
    }

    public function test_incremental_metric_eligibility_requires_field_roles_from_the_current_source_graph(): void
    {
        $text = 'Revenue 12.4 KES million in FY2025.';
        $document = $this->document($text);
        $source = [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
            'page' => 1, 'span_id' => 'E001']];
        $correct = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'KES million', 'period' => 'FY2025'], $source);
        $wrongPeriod = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'KES million', 'period' => 'FY2024'], $source);
        $wrongUnit = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'USD million', 'period' => 'FY2025'], $source);
        $integrity = app(MeasurementIntegrity::class);
        self::assertFalse($integrity->forEvidence($correct)['analyticalEligible']);

        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 1, 'rejected' => 2],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertContains('governing_owner_unverified',
            $integrity->forEvidence($correct->refresh())['reasons']);
        self::assertFalse($integrity->forEvidence($correct)['analyticalEligible']);
        self::assertSame('period_source_mismatch', $wrongPeriod->refresh()->data['grounding_rejection']);
        self::assertFalse($integrity->forEvidence($wrongPeriod)['analyticalEligible']);
        self::assertSame('unit_source_mismatch', $wrongUnit->refresh()->data['grounding_rejection']);
        self::assertFalse($integrity->forEvidence($wrongUnit)['analyticalEligible']);
        self::assertSame(['verified' => 1, 'rejected' => 2],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame(['verified' => 0, 'rejected' => 3],
            app(IncrementalMetricFieldVerifier::class)->verify($document, null));
        self::assertFalse($integrity->forEvidence($correct->refresh())['analyticalEligible']);
    }

    public function test_a_proposed_investment_cannot_be_verified_as_a_performance_target(): void
    {
        $text = 'Proposed service recovery investment 2.4 KES million in FY2025.';
        $document = $this->document($text);
        $source = [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
            'page' => 1, 'span_id' => 'E001']];
        $target = $this->evidence($document, ['label' => 'service recovery investment',
            'value' => '2.4', 'unit' => 'KES million', 'period' => 'FY2025',
            'value_basis' => 'target'], $source);
        $proposed = $this->evidence($document, ['label' => 'service recovery investment',
            'value' => '2.4', 'unit' => 'KES million', 'period' => 'FY2025',
            'value_basis' => 'proposed'], $source);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 1, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('value_basis_source_mismatch', $target->refresh()->data['grounding_rejection']);
        self::assertFalse(app(MeasurementIntegrity::class)->forEvidence($target)['analyticalEligible']);
        self::assertContains('governing_owner_unverified',
            app(MeasurementIntegrity::class)->forEvidence($proposed->refresh())['reasons']);
    }

    public function test_a_period_in_another_sentence_does_not_verify_the_value(): void
    {
        $text = 'Revenue was 12.4 KES million. The FY2025 plan follows.';
        $document = $this->document($text);
        $row = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'KES million', 'period' => 'FY2025'],
            [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
                'page' => 1, 'span_id' => 'E001']]);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 0, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('period_relationship_unverified', $row->refresh()->data['grounding_rejection']);
        self::assertFalse(app(MeasurementIntegrity::class)->forEvidence($row)['analyticalEligible']);
    }

    public function test_incremental_graph_verdict_is_shared_by_collector_and_kpi_api(): void
    {
        $text = 'Revenue 12.4 KES million in FY2025.';
        $document = $this->document($text);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental', 'key' => 'pk-proof']])->save();
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => 'Revenue',
            'value' => '12.4', 'unit' => 'KES million', 'period' => 'FY2025']);
        $row = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'KES million', 'period' => 'FY2025'],
            [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
                'page' => 1, 'span_id' => 'E001']]);
        $row->forceFill(['source_id' => 'kpi:'.$kpi->id])->save();
        $request = Request::create('/api/documents/'.$document->id);
        $request->attributes->set('kpi_evidence', collect(['kpi:'.$kpi->id => $row]));
        self::assertFalse(app(MetricCollector::class)->collect($document)['observations'][0]
            ->integrity['analyticalEligible']);
        self::assertFalse((new KpiResource($kpi))->toArray($request)['analyticalEligible']);

        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        app(IncrementalMetricFieldVerifier::class)->verify($document, $setId);
        $request->attributes->set('kpi_evidence', collect(['kpi:'.$kpi->id => $row->refresh()]));
        $collected = app(MetricCollector::class)->collect($document)['observations'][0]->integrity;
        self::assertFalse($collected['analyticalEligible']);
        self::assertContains('governing_owner_unverified', $collected['reasons']);
        $api = (new KpiResource($kpi))->toArray($request);
        self::assertFalse($api['analyticalEligible']);
        self::assertContains('governing_owner_unverified', $api['limitationReasons']);

        $document->forceFill(['extracted_text' => 'Revenue 12.4 KES million in FY2024.'])->save();
        $stale = app(MeasurementIntegrity::class)->forEvidence($row->refresh());
        self::assertFalse($stale['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $stale['reasons']);
        $collector = app(MetricCollector::class)->collect($document)['observations'][0]->integrity;
        self::assertFalse($collector['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $collector['reasons']);
        $api = (new KpiResource($kpi->fresh()))->toArray($request);
        self::assertFalse($api['analyticalEligible']);
        self::assertContains('source_revision_mismatch', $api['limitationReasons']);
    }

    public function test_a_target_word_in_another_sentence_cannot_verify_the_values_basis(): void
    {
        $text = 'Revenue was 12.4 KES million in FY2025. The target for FY2026 was 15.';
        $document = $this->document($text);
        $row = $this->evidence($document, ['label' => 'Revenue', 'value' => '12.4',
            'unit' => 'KES million', 'period' => 'FY2025', 'value_basis' => 'target'],
            [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
                'page' => 1, 'span_id' => 'E001']]);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 0, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('value_basis_unverified', $row->refresh()->data['grounding_rejection']);
    }

    public function test_failed_candidate_is_not_published_and_other_workspace_cannot_bind(): void
    {
        $document = $this->document('Revenue 12.4');
        $foreign = $this->document('Revenue 12.4');
        $other = $this->evidence($foreign, ['value' => '12.4', 'label' => 'Revenue'], []);
        try {
            app(SourceProvenance::class)->publish($document, 'fixture.v1', [
                ['key' => 'v', 'kind' => 'text_span', 'modality' => 'embedded_text',
                    'start_offset' => 8, 'end_offset' => 12, 'text' => '12.4'],
            ], [], [['evidence_id' => $other->id, 'anchor' => 'v', 'role' => 'value']]);
            self::fail('Foreign evidence was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, DB::table('source_provenance_sets')->where('document_id', $document->id)->count());
        }
    }

    public function test_old_anchors_do_not_follow_changed_source_revision(): void
    {
        $document = $this->document('Revenue 12.4');
        $row = $this->evidence($document, ['value' => '12.4', 'label' => 'Revenue'],
            [['quote' => 'Revenue 12.4', 'start_offset' => 0, 'end_offset' => 12, 'page' => 1,
                'span_id' => 'E001']]);
        app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertArrayHasKey($row->id, app(SourceProvenanceReader::class)->forEvidence($document, [$row->id]));
        $document->forceFill(['extracted_text' => 'Revenue 99.9'])->save();
        self::assertSame([], app(SourceProvenanceReader::class)->forEvidence($document->refresh(), [$row->id]));
    }

    public function test_parser_proven_table_edges_bind_value_row_column_and_table_scale(): void
    {
        $text = "Financial results - USD millions\nFY2024 | FY2025\nRevenue | 10,200 | 12,400";
        $document = $this->document($text);
        $row = $this->evidence($document, ['value' => '12,400', 'label' => 'Revenue',
            'period' => 'FY2025'], [['quote' => $text, 'start_offset' => 0,
                'end_offset' => mb_strlen($text), 'page' => 1]]);
        $anchors = [['key' => 'table:1', 'kind' => 'table', 'modality' => 'docx', 'path' => 'tbl[1]']];
        foreach (['v' => ['12,400', 'cell'], 'r' => ['Revenue', 'row_header'],
            'c' => ['FY2025', 'column_header'], 'unit' => ['USD millions', 'caption']] as $key => [$value, $kind]) {
            $at = mb_strpos($text, $value);
            $anchors[] = ['key' => $key, 'kind' => $kind, 'modality' => 'docx',
                'path' => 'tbl[1]/'.$key, 'start_offset' => $at,
                'end_offset' => $at + mb_strlen($value), 'text' => $value];
        }
        $edges = [];
        foreach (['v', 'r', 'c'] as $key) {
            $edges[] = ['from' => $key, 'to' => 'table:1', 'type' => 'table_parent'];
        }
        $edges[] = ['from' => 'v', 'to' => 'r', 'type' => 'row_header'];
        $edges[] = ['from' => 'v', 'to' => 'c', 'type' => 'column_header'];
        $edges[] = ['from' => 'table:1', 'to' => 'unit', 'type' => 'caption'];
        app(SourceProvenance::class)->publish($document, 'docx-table.fixture.v1', $anchors, $edges, [
            ['evidence_id' => $row->id, 'anchor' => 'v', 'role' => 'value'],
            ['evidence_id' => $row->id, 'anchor' => 'r', 'role' => 'metric'],
            ['evidence_id' => $row->id, 'anchor' => 'c', 'role' => 'period'],
            ['evidence_id' => $row->id, 'anchor' => 'unit', 'role' => 'scale'],
        ]);
        $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$row->id])[$row->id];
        self::assertSame('12,400', $proof['roles']['value'][0]['text']);
        self::assertSame('Revenue', $proof['roles']['metric'][0]['text']);
        self::assertSame('FY2025', $proof['roles']['period'][0]['text']);
        self::assertSame('USD millions', $proof['roles']['scale'][0]['text']);
    }

    public function test_structural_publisher_rejects_a_period_bound_to_the_wrong_column(): void
    {
        $text = 'Operating profit | Q2 2026 | Q3 2026 | 10.0';
        $document = $this->document($text);
        $row = $this->evidence($document, ['value' => '10.0', 'label' => 'Operating profit',
            'period' => 'Q2 2026'], [['quote' => $text, 'start_offset' => 0,
                'end_offset' => mb_strlen($text), 'page' => 1]]);
        $anchors = [['key' => 'table', 'kind' => 'table', 'modality' => 'docx', 'path' => 'tbl[1]']];
        foreach (['value' => ['10.0', 'cell'], 'metric' => ['Operating profit', 'row_header'],
            'wrong_period' => ['Q2 2026', 'column_header'],
            'right_period' => ['Q3 2026', 'column_header']] as $key => [$value, $kind]) {
            $start = mb_strpos($text, $value);
            $anchors[] = ['key' => $key, 'kind' => $kind, 'modality' => 'docx',
                'path' => 'tbl[1]/'.$key, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen($value), 'text' => $value];
        }
        try {
            app(SourceProvenance::class)->publish($document, 'wrong-column.fixture.v1', $anchors, [
                ['from' => 'value', 'to' => 'table', 'type' => 'table_parent'],
                ['from' => 'metric', 'to' => 'table', 'type' => 'table_parent'],
                ['from' => 'wrong_period', 'to' => 'table', 'type' => 'table_parent'],
                ['from' => 'right_period', 'to' => 'table', 'type' => 'table_parent'],
                ['from' => 'value', 'to' => 'metric', 'type' => 'row_header'],
                ['from' => 'value', 'to' => 'right_period', 'type' => 'column_header'],
            ], [
                ['evidence_id' => $row->id, 'anchor' => 'value', 'role' => 'value'],
                ['evidence_id' => $row->id, 'anchor' => 'metric', 'role' => 'metric'],
                ['evidence_id' => $row->id, 'anchor' => 'wrong_period', 'role' => 'period'],
            ]);
            self::fail('The wrong period column was published.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Field role is outside the value table.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('source_provenance_sets')->count());
        self::assertFalse(app(MeasurementIntegrity::class)->forEvidence($row)['analyticalEligible']);
    }

    public function test_parser_version_change_does_not_mix_old_and_new_graphs(): void
    {
        $document = $this->document('Revenue 12.4');
        $row = $this->evidence($document, ['value' => '12.4', 'label' => 'Revenue'],
            [['quote' => 'Revenue 12.4', 'start_offset' => 0, 'end_offset' => 12, 'page' => 1,
                'span_id' => 'E001']]);
        app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertArrayHasKey($row->id, app(SourceProvenanceReader::class)->forEvidence($document, [$row->id]));
        app(SourceProvenance::class)->publish($document, 'new-parser.v2', [], [], []);
        self::assertSame([], app(SourceProvenanceReader::class)->forEvidence($document, [$row->id]));
        self::assertSame(1, DB::table('source_provenance_sets')->where('active', true)->count());
    }

    public function test_normalization_variants_never_shift_raw_role_offsets(): void
    {
        foreach (['ﬁnance — 12.4', "Cafe\u{0301} ‘12.4’", "Revenue\u{00A0}12.4"] as $text) {
            $document = $this->document($text);
            $row = $this->evidence($document, ['value' => '12.4'],
                [['quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text),
                    'page' => 1, 'span_id' => 'E001']]);
            app(TextRoleProvenanceProducer::class)->publish($document);
            $proof = app(SourceProvenanceReader::class)->forEvidence($document, [$row->id])[$row->id];
            self::assertSame(mb_strpos($text, '12.4'), $proof['roles']['value'][0]['start_offset']);
            self::assertSame('12.4', $proof['roles']['value'][0]['text']);
        }
    }

    public function test_identical_cell_text_keeps_distinct_occurrence_anchors(): void
    {
        $text = "Revenue | 12.4\nExpenses | 12.4";
        $document = $this->document($text);
        $first = mb_strpos($text, '12.4');
        $second = mb_strpos($text, '12.4', $first + 1);
        app(SourceProvenance::class)->publish($document, 'table.fixture.v1', [
            ['key' => 'row1/cell2', 'kind' => 'cell', 'modality' => 'docx', 'path' => 'tbl1/r1/c2',
                'start_offset' => $first, 'end_offset' => $first + 4, 'text' => '12.4'],
            ['key' => 'row2/cell2', 'kind' => 'cell', 'modality' => 'docx', 'path' => 'tbl1/r2/c2',
                'start_offset' => $second, 'end_offset' => $second + 4, 'text' => '12.4'],
        ], [], []);
        self::assertSame(2, DB::table('source_anchors')->count());
        self::assertSame(2, DB::table('source_anchors')->distinct()->count('id'));
    }

    public function test_structural_anchor_without_source_occurrence_is_rejected(): void
    {
        $document = $this->document('Revenue 12.4');
        $this->expectException(\InvalidArgumentException::class);
        app(SourceProvenance::class)->publish($document, 'fixture.v1', [
            ['key' => 'invented-table', 'kind' => 'table', 'modality' => 'docx'],
        ], [], []);
    }
}
