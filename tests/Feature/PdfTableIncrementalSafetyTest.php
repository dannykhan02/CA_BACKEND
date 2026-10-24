<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\GoverningOwnerProof;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\SourceProvenanceReader;
use App\Services\Intelligence\StructuredPdfFieldProof;
use App\Services\Intelligence\StructuredProvenanceProducer;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\Intelligence\VerifiedPdfChartPersister;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PdfTableIncrementalSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_projected_source_cells_create_separate_verified_candidates_without_rewriting_model_claims(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, 'US$ millions');
        $original = DocumentEvidence::whereKey($ids[0])->firstOrFail()->data;
        $claims = DocumentEvidence::whereIn('id', $ids)->get()->mapWithKeys(
            static fn ($row) => [$row->id => $row->data])->all();
        $counts = app(PdfSourceCandidateCollector::class)->collect($document);
        self::assertSame(128, $counts['extracted']);
        self::assertSame(128, $counts['candidates']);
        self::assertSame($counts, app(PdfSourceCandidateCollector::class)->collect($document));
        self::assertSame($original, DocumentEvidence::whereKey($ids[0])->firstOrFail()->data);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $verdicts = app(IncrementalMetricFieldVerifier::class)->verify($document, $setId);
        self::assertSame(134, array_sum($verdicts));
        foreach ($ids as $id) {
            $data = DocumentEvidence::whereKey($id)->firstOrFail()->data;
            foreach (['label', 'value', 'period', 'unit', 'subject'] as $field) {
                self::assertSame($claims[$id][$field], $data[$field]);
            }
            self::assertSame('currency_source_mismatch', $data['grounding_rejection']);
        }
        $sourceFacts = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'like', 'pdf-cell:%')->get();
        self::assertCount(128, $sourceFacts);
        $matched = app(SourceProvenanceReader::class)->forEvidence($document,
            $sourceFacts->pluck('id')->all(), $setId);
        self::assertCount(128, array_filter($matched,
            static fn ($proof) => $proof['capabilities']['structural']));
        self::assertGreaterThan(0, $sourceFacts->filter(
            static fn ($row) => ($row->data['measurement_verification'] ?? null) === 'verified')->count());
        $chartCounts = app(VerifiedPdfChartPersister::class)->persist($document, $setId);
        $eligible = array_values(array_filter(app(MetricCollector::class)->collect($document)['observations'],
            static fn ($item) => $item->integrity['analyticalEligible']));
        $reasons = [];
        foreach ($sourceFacts as $fact) {
            $reason = $fact->data['grounding_rejection'] ?? 'verified';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
        self::assertSame(['verified' => 44, 'rejected' => 90], $verdicts);
        self::assertCount(44, $eligible);
        self::assertCount(11, app(ChartCandidateBuilder::class)->build($eligible)['candidates']);
        self::assertSame(['charts' => 11, 'points' => 22], $chartCounts);
        ksort($reasons);
        self::assertSame(['governing_owner_unverified' => 84, 'verified' => 44], $reasons);
        foreach (DocumentChart::where('document_id', $document->id)->get() as $chart) {
            foreach ($chart->data as $point) {
                $anchor = DB::table('source_anchors')->where('id', $point['source']['anchor_id'])->sole();
                self::assertSame($point['sourceValue'], $anchor->source_text);
                self::assertSame(1, $point['source']['page']);
            }
        }
    }

    public function test_equivalent_model_and_source_facts_make_one_chart_point_per_period(): void
    {
        [$document, , $modelIds] = $this->fixtureDocument(true, '$ millions');
        self::assertSame(128, app(PdfSourceCandidateCollector::class)
            ->collect($document)['candidates']);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 50, 'rejected' => 84],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        foreach ($modelIds as $id) {
            self::assertSame('verified', DocumentEvidence::whereKey($id)
                ->firstOrFail()->data['measurement_verification']);
        }
        self::assertSame(['charts' => 11, 'points' => 22],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(['charts' => 11, 'points' => 22],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        $charts = DocumentChart::where('document_id', $document->id)->get();
        self::assertCount(11, $charts->pluck('title')->unique());
        foreach ($charts as $chart) {
            self::assertContains(array_column($chart->data, 'label'), [
                ['Q3 2025', 'Q3 2026'], ['9M 2025', '9M 2026'],
            ]);
            self::assertCount(2, $chart->data);
        }
    }

    public function test_source_candidates_on_independent_and_adversarial_financial_pdfs(): void
    {
        foreach ([
            'Northstar_Q3_2026_Board_Performance_Review.pdf' => [12, 12, 12, 12, 4, 4, 12,
                ['verified' => 12]],
            'financial_units_thousands.pdf' => [6, 6, 6, 6, 3, 3, 6, ['verified' => 6]],
            'financial_units_adversarial.pdf' => [6, 6, 0, 0, 0, 0, 0, ['unit_source_mismatch' => 6]],
            'financial_basis_adversarial.pdf' => [8, 8, 8, 8, 4, 4, 8, ['verified' => 8]],
            'financial_layout_adversarial.pdf' => [0, 0, 0, 0, 0, 0, 0, []],
        ] as $name => [$expectedExtracted, $expectedMatched, $expectedVerified, $expectedEligible,
            $expectedCandidates, $expectedCharts, $expectedPoints, $expectedReasons]) {
            $path = base_path('tests/Fixtures/'.$name);
            config()->set('document_intelligence.pdf_table_structure', true);
            $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
            $user = User::factory()->create();
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
            $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => $name, 'type' => 'PDF',
                'status' => 'Processing', 'classification' => 'Internal',
                'size_kb' => (int) ceil(filesize($path) / 1024),
                'year' => 2026, 'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
            $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
                'key' => 'incremental:source-candidate-test']])->save();
            app(StructuredExtractionStore::class)->publish($document, $source);
            $document->refresh();
            $counts = app(PdfSourceCandidateCollector::class)->collect($document);
            $setId = app(TextRoleProvenanceProducer::class)->publish($document);
            $facts = DocumentEvidence::where('document_id', $document->id)->get();
            $matched = app(SourceProvenanceReader::class)->forEvidence($document,
                $facts->pluck('id')->all(), $setId);
            $matchedCount = count(array_filter($matched,
                static fn ($proof) => $proof['capabilities']['structural']));
            if (str_starts_with($name, 'Northstar_')) {
                $equalValues = $facts->filter(static fn ($fact) => ($fact->data['label'] ?? null) === 'Operating profit'
                    && ($fact->data['value'] ?? null) === '10.0');
                self::assertCount(2, $equalValues);
                $anchors = $equalValues->map(static fn ($fact) => $matched[$fact->id]['roles']['value'][0]['anchor_id'] ?? null)->all();
                self::assertCount(2, array_unique($anchors));
                self::assertNotContains(null, $anchors);
                $equalValues = $equalValues->values();
                $tampered = clone $equalValues[0];
                $data = $tampered->data;
                $data['source_representation']['cell_id'] =
                    $equalValues[1]->data['source_representation']['cell_id'];
                $tampered->data = $data;
                [, , $badBindings] = app(StructuredProvenanceProducer::class)
                    ->build($source, [$tampered]);
                self::assertSame([], array_values(array_filter($badBindings,
                    static fn ($binding) => $binding['role'] === 'value')));
                $first = $facts->first(static fn ($fact) => ($fact->data['label'] ?? null) === 'Revenue');
                $proof = $matched[$first->id];
                $path = $proof['pdf_table_path'];
                $owners = app(GoverningOwnerProof::class);
                self::assertSame('NORTHSTAR DISTRIBUTION LTD', $owners->resolveReportOwner(
                    $document, $path, $proof['roles']['value'][0], '')['entity']);
                $missingFooter = [...$path, 'report_footer' => []];
                self::assertNull($owners->resolveReportOwner($document, $missingFooter,
                    $proof['roles']['value'][0], ''));
                $missingCover = [...$path, 'report_cover' => []];
                self::assertNull($owners->resolveReportOwner($document, $missingCover,
                    $proof['roles']['value'][0], ''));
                $duplicateCover = [...$path, 'report_cover' => [
                    $path['report_cover'][0], $path['report_cover'][0],
                ]];
                self::assertNull($owners->resolveReportOwner($document, $duplicateCover,
                    $proof['roles']['value'][0], ''));
                $conflictingFooter = $path;
                $conflictingFooter['report_footer'][0]['text'] = 'OTHER DISTRIBUTION LTD';
                self::assertNull($owners->resolveReportOwner($document, $conflictingFooter,
                    $proof['roles']['value'][0], ''));
                $unrelatedCaption = $path;
                $unrelatedCaption['captions'][0]['text'] = 'Regional dispatch schedule (KES million)';
                self::assertNull($owners->resolveReportOwner($document, $unrelatedCaption,
                    $proof['roles']['value'][0], ''));
                $margin = $facts->first(static fn ($fact) => ($fact->data['label'] ?? null) === 'Operating margin');
                self::assertSame('%', $margin->data['unit']);
                $badUnit = app(StructuredPdfFieldProof::class)->prove(
                    $document, [...$margin->data, 'unit' => 'KES million',
                        'source_provenance_set_id' => $setId], $matched[$margin->id]);
                self::assertSame('unit_source_mismatch', $badUnit['rejection']);
            }
            $verdicts = app(IncrementalMetricFieldVerifier::class)->verify($document, $setId);
            $eligible = array_values(array_filter(app(MetricCollector::class)->collect($document)['observations'],
                static fn ($item) => $item->integrity['analyticalEligible']));
            $candidates = app(ChartCandidateBuilder::class)->build($eligible)['candidates'];
            $persisted = app(VerifiedPdfChartPersister::class)->persist($document, $setId);
            $reasons = [];
            foreach (DocumentEvidence::where('document_id', $document->id)->get() as $fact) {
                $reason = $fact->data['grounding_rejection'] ?? 'verified';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
            self::assertSame($counts['candidates'], array_sum($verdicts), $name);
            self::assertSame($expectedExtracted, $counts['extracted'], $name);
            self::assertSame($expectedMatched, $matchedCount, $name);
            self::assertSame($expectedVerified, $verdicts['verified'], $name);
            self::assertCount($expectedEligible, $eligible, $name);
            self::assertCount($expectedCandidates, $candidates, $name);
            self::assertSame(['charts' => $expectedCharts, 'points' => $expectedPoints], $persisted, $name);
            ksort($reasons);
            ksort($expectedReasons);
            self::assertSame($expectedReasons, $reasons, $name);
        }
    }

    public function test_optional_original_apple_statement_keeps_duration_scope_and_share_units_separate(): void
    {
        $path = getenv('DOCINTEL_APPLE_PDF') ?: '/tmp/docintel-apple-fy25-q2.pdf';
        if (! is_file($path)) {
            $this->markTestSkipped('Original Apple PDF is an external audit input.');
        }
        self::assertSame('e333dd821d9ef827e82d8e0b696493a127c4ee171ea63aca3a80dc1dfea60e89',
            hash_file('sha256', $path));
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(2, $source->capabilities['projected_tables']);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2025,
            'pages' => 3, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:apple-phase5']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        self::assertSame(['extracted' => 132, 'candidates' => 132],
            app(PdfSourceCandidateCollector::class)->collect($document));
        $set = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 18, 'rejected' => 114],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $set));
        self::assertSame(['charts' => 4, 'points' => 8],
            app(VerifiedPdfChartPersister::class)->persist($document, $set));
        $facts = DocumentEvidence::where('document_id', $document->id)->get();
        foreach ($facts->filter(static fn ($fact) => in_array($fact->data['label'] ?? '',
            ['Basic', 'Diluted'], true)) as $shares) {
            self::assertNotSame('verified', $shares->data['measurement_verification']);
        }
        foreach (DocumentChart::where('document_id', $document->id)->get() as $chart) {
            self::assertCount(2, $chart->data);
            self::assertCount(1, array_unique(array_map(static fn ($point) => explode(' ', $point['label'])[0], $chart->data)));
            foreach ($chart->data as $point) {
                $anchor = DB::table('source_anchors')->where('id', $point['source']['anchor_id'])->sole();
                self::assertSame($point['sourceValue'], $anchor->source_text);
                self::assertContains($point['source']['page'], [1, 3]);
            }
        }
    }

    public function test_optional_walmart_balance_table_cannot_borrow_the_preceding_roi_unit_caption(): void
    {
        $path = getenv('DOCINTEL_WALMART_PDF') ?: '/tmp/docintel-walmart-q3-fy26.pdf';
        if (! is_file($path)) {
            $this->markTestSkipped('Original Walmart PDF is an external audit input.');
        }
        self::assertSame('86de6bebc0851a20fcc258b097863f46d32f04e92bc6d0c80f7fecff4a5beab3',
            hash_file('sha256', $path));
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(1, $source->capabilities['projected_tables']);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2025,
            'pages' => 19, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:walmart-phase5']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        self::assertSame(['extracted' => 12, 'candidates' => 12],
            app(PdfSourceCandidateCollector::class)->collect($document));
        $set = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 0, 'rejected' => 12],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $set));
        foreach (DocumentEvidence::where('document_id', $document->id)->get() as $fact) {
            self::assertSame('unit_source_mismatch', $fact->data['grounding_rejection']);
            self::assertSame('', $fact->data['unit']);
        }
        self::assertSame(['charts' => 0, 'points' => 0],
            app(VerifiedPdfChartPersister::class)->persist($document, $set));
    }

    public function test_nasdaq_cells_enter_the_incremental_graph_but_unproven_claims_do_not_chart(): void
    {
        [$document, $source, $ids] = $this->fixtureDocument(true);
        self::assertSame(1, $source->capabilities['projected_tables']);

        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $proofs = app(SourceProvenanceReader::class)->forEvidence($document, $ids, $setId);
        self::assertCount(6, $proofs);
        foreach ($proofs as $proof) {
            self::assertTrue($proof['capabilities']['structural']);
            self::assertArrayHasKey('value', $proof['roles']);
            self::assertArrayHasKey('metric', $proof['roles']);
            self::assertArrayNotHasKey('period', $proof['roles']);
            self::assertArrayNotHasKey('unit', $proof['roles']);
        }
        self::assertSame(12, DB::table('source_relationships')
            ->where('document_id', $document->id)->where('type', 'row_header')
            ->distinct()->count('from_anchor_id'));
        $this->assertNoChart($document, $setId);
        $checked = DocumentEvidence::whereKey($ids[0])->firstOrFail();
        self::assertSame(['value', 'metric', 'period', 'scale'], $checked->data['source_fields_verified'],
            json_encode($checked->data, JSON_THROW_ON_ERROR));
        config()->set('document_intelligence.server_evidence_resolution', true);
        self::assertSame(['verified' => 0, 'rejected' => 6],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
    }

    public function test_page_only_baseline_rejects_the_same_six_nasdaq_observations(): void
    {
        [$document, $source, $ids] = $this->fixtureDocument(false, '$ millions');
        self::assertSame(['page'], array_unique(array_column($source->nodes, 'type')));
        self::assertSame(['extracted' => 0, 'candidates' => 0],
            app(PdfSourceCandidateCollector::class)->collect($document));
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $proofs = app(SourceProvenanceReader::class)->forEvidence($document, $ids, $setId);
        foreach ($proofs as $proof) {
            self::assertFalse($proof['capabilities']['structural']);
        }
        self::assertSame(0, DB::table('source_relationships')
            ->where('document_id', $document->id)->where('type', 'row_header')->count());
        $this->assertNoChart($document, $setId);
        foreach ($ids as $id) {
            self::assertSame('unit_source_mismatch',
                DocumentEvidence::whereKey($id)->firstOrFail()->data['grounding_rejection']);
        }
    }

    public function test_source_backed_dollar_millions_claim_can_reach_chart_eligibility(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $paths = app(SourceProvenanceReader::class)->forEvidence($document, $ids, $setId);
        self::assertNotNull(app(GoverningOwnerProof::class)->resolveStructured(
            $document, $paths[$ids[0]]['pdf_table_path'], $paths[$ids[0]]['roles']['value'][0],
            'Nasdaq, Inc.'), json_encode($paths[$ids[0]]['pdf_table_path'], JSON_THROW_ON_ERROR));
        $counts = app(IncrementalMetricFieldVerifier::class)->verify($document, $setId);
        self::assertSame(['verified' => 6, 'rejected' => 0], $counts,
            json_encode(DocumentEvidence::whereKey($ids[0])->firstOrFail()->data, JSON_THROW_ON_ERROR));
        self::assertSame($setId, app(TextRoleProvenanceProducer::class)->publish($document));
        foreach ($ids as $id) {
            $data = DocumentEvidence::whereKey($id)->firstOrFail()->data;
            foreach (['value', 'metric', 'period', 'scale', 'unit', 'currency',
                'subject', 'governing_owner'] as $role) {
                self::assertContains($role, $data['source_fields_verified']);
            }
            self::assertSame('Nasdaq, Inc', $data['governing_owner']['entity']);
            self::assertSame($setId, $data['governing_owner']['source_provenance_set_id']);
        }
        $collected = app(MetricCollector::class)->collect($document);
        self::assertCount(6, array_filter($collected['observations'],
            static fn ($item) => $item->integrity['analyticalEligible']));
        $candidates = app(ChartCandidateBuilder::class)
            ->build($collected['observations'])['candidates'];
        self::assertCount(3, $candidates);
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(3, DocumentChart::where('document_id', $document->id)->count());
        self::assertSame(6, DocumentChartPoint::where('workspace_id', $document->workspace_id)
            ->whereIn('document_chart_id', DocumentChart::where('document_id', $document->id)
                ->pluck('id'))->count());
        $expected = [
            'Revenues less transaction-based expenses' => ['1,315', '1,481'],
            'Total operating expenses' => ['729', '749'],
            'Operating income' => ['586', '732'],
        ];
        foreach (DocumentChart::where('document_id', $document->id)->get() as $chart) {
            self::assertSame('verified_pdf_table', $chart->source_metadata['origin']);
            self::assertCount(2, $chart->data);
            self::assertArrayHasKey($chart->title, $expected);
            self::assertSame($expected[$chart->title],
                array_column($chart->data, 'sourceValue'));
            self::assertSame(['Q3 2025', 'Q3 2026'], array_column($chart->data, 'label'));
            foreach ($chart->data as $point) {
                self::assertNotEmpty($point['source']['anchor_id']);
                self::assertSame(1, $point['source']['page']);
                $anchor = DB::table('source_anchors')->where('id', $point['source']['anchor_id'])->sole();
                self::assertSame($point['sourceValue'], $anchor->source_text);
                self::assertSame($setId, $anchor->set_id);
            }
        }
    }

    public function test_mutated_financial_claims_lose_eligibility_while_unchanged_pairs_remain_chartable(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $verifier = app(IncrementalMetricFieldVerifier::class);
        self::assertSame(['verified' => 6, 'rejected' => 0], $verifier->verify($document, $setId));
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        $original = DocumentEvidence::whereKey($ids[0])->firstOrFail()->data;
        foreach ([
            'wrong_quarter' => ['period' => 'Q3 2025'],
            'nine_month_as_quarter' => ['period' => '9M 2026'],
            'thousands_as_millions' => ['unit' => '$ thousands'],
            'unsupported_usd' => ['unit' => 'USD millions'],
            'unsupported_qualified_dollar' => ['unit' => 'US$ millions'],
            'wrong_company' => ['subject' => 'Acme Inc.'],
            'wrong_segment' => ['subject' => 'Market Services'],
            'wrong_sign' => ['value' => '-1,481'],
            'unsupported_alias' => ['label' => 'Net revenue'],
            'unsupported_gaap_label' => ['label' => 'GAAP operating expenses'],
            'unsupported_gaap_basis' => ['accounting_basis' => 'gaap'],
            'unsupported_non_gaap' => ['value_basis' => 'non-gaap'],
        ] as $case => $change) {
            $row = DocumentEvidence::whereKey($ids[0])->firstOrFail();
            $row->forceFill(['data' => array_replace($original, $change)])->save();
            self::assertSame(['verified' => 5, 'rejected' => 1],
                $verifier->verify($document, $setId), $case);
            $rejected = DocumentEvidence::whereKey($ids[0])->firstOrFail()->data;
            self::assertNotEmpty($rejected['grounding_rejection'], $case);
            $eligible = array_values(array_filter(app(MetricCollector::class)
                ->collect($document)['observations'],
                static fn ($item) => $item->integrity['analyticalEligible']));
            self::assertCount(5, $eligible, $case);
            self::assertCount(2, app(ChartCandidateBuilder::class)
                ->build($eligible)['candidates'], $case);
            self::assertSame(['charts' => 2, 'points' => 4],
                app(VerifiedPdfChartPersister::class)->persist($document, $setId), $case);
            self::assertSame(2, DocumentChart::where('document_id', $document->id)->count(), $case);
        }
        DocumentEvidence::whereKey($ids[0])->firstOrFail()->forceFill(['data' => $original])->save();
        self::assertSame(['verified' => 6, 'rejected' => 0], $verifier->verify($document, $setId));
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_changed_extraction_revision_invalidates_all_pdf_role_proofs(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 6, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        $document->forceFill(['extracted_text' => $document->extracted_text.' changed'])->save();
        self::assertSame(['verified' => 0, 'rejected' => 6],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertCount(0, array_filter(app(MetricCollector::class)->collect($document)['observations'],
            static fn ($item) => $item->integrity['analyticalEligible']));
        self::assertSame(['charts' => 0, 'points' => 0],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(0, DocumentChart::where('document_id', $document->id)->count());
    }

    public function test_missing_active_extraction_prunes_only_its_source_candidates_and_retracts_charts(): void
    {
        [$document] = $this->fixtureDocument(true, '$ millions');
        $other = $this->fixtureDocument(true, '$ millions')[0];
        $collector = app(PdfSourceCandidateCollector::class);
        self::assertSame(128, $collector->collect($document)['candidates']);
        self::assertSame(128, $collector->collect($other)['candidates']);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        app(IncrementalMetricFieldVerifier::class)->verify($document, $setId);
        self::assertSame(11, app(VerifiedPdfChartPersister::class)
            ->persist($document, $setId)['charts']);

        $document->forceFill(['extracted_text' => $document->extracted_text.' changed'])->save();
        self::assertSame(['extracted' => 0, 'candidates' => 0], $collector->collect($document));
        self::assertSame(0, DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'like', 'pdf-cell:%')->count());
        self::assertSame(128, DocumentEvidence::where('document_id', $other->id)
            ->where('source_id', 'like', 'pdf-cell:%')->count());
        self::assertSame(['charts' => 0, 'points' => 0],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(0, DocumentChart::where('document_id', $document->id)->count());
    }

    public function test_a_claim_changed_after_verification_cannot_keep_its_prior_verdict(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 6, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        $row = DocumentEvidence::whereKey($ids[0])->firstOrFail();
        $row->forceFill(['data' => [...$row->data, 'value' => '9,999']])->save();
        $observations = app(MetricCollector::class)->collect($document)['observations'];
        self::assertCount(5, array_filter($observations,
            static fn ($item) => $item->integrity['analyticalEligible']));
        $changed = collect($observations)->first(static fn ($item) => $item->rawValue === '9,999');
        self::assertContains('source_claim_changed', $changed->integrity['reasons']);
        self::assertSame(['charts' => 2, 'points' => 4],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_segment_row_in_a_consolidated_statement_does_not_inherit_company_scope(): void
    {
        [$document, $source] = $this->fixtureDocument(true, '$ millions');
        $quote = collect(explode("\n", $source->text))
            ->first(static fn ($line) => str_starts_with(trim($line), 'Market Services '));
        self::assertIsString($quote);
        $start = mb_strpos($source->text, $quote);
        $row = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'incremental:pdf-table-test',
            'identity' => hash('sha256', 'market-services-as-consolidated'),
            'kind' => 'metric', 'source_id' => 'test:market-services',
            'data' => ['label' => 'Market Services', 'value' => '1,221',
                'period' => 'Q3 2026', 'unit' => '$ millions', 'subject' => 'Nasdaq, Inc.'],
            'sources' => [['quote' => $quote, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen($quote), 'page' => 1,
                'span_id' => 'table:market-services']]]);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 6, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('governing_owner_unverified', $row->refresh()->data['grounding_rejection']);
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_missing_model_subject_is_filled_only_from_the_verified_table_owner(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $row = DocumentEvidence::whereKey($ids[0])->firstOrFail();
        $row->forceFill(['data' => [...$row->data, 'subject' => '']])->save();
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 6, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        $verified = $row->refresh()->data;
        self::assertSame('Nasdaq, Inc', $verified['subject']);
        self::assertContains('subject', $verified['source_fields_verified']);
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_per_share_caption_exception_overrides_the_millions_scale(): void
    {
        [$document, $source] = $this->fixtureDocument(true, '$ millions');
        $quote = collect(explode("\n", $source->text))
            ->first(static fn ($line) => str_starts_with(trim($line), 'Basic earnings per share '));
        self::assertIsString($quote);
        $start = mb_strpos($source->text, $quote);
        $ids = [];
        foreach ([['Q3 2026', '0.97'], ['Q3 2025', '0.74']] as [$period, $value]) {
            $row = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'incremental:pdf-table-test',
                'identity' => hash('sha256', 'per-share'.$period), 'kind' => 'metric',
                'source_id' => 'test:per-share:'.$period,
                'data' => ['label' => 'Basic earnings per share', 'value' => $value,
                    'period' => $period, 'unit' => '$ per share', 'subject' => 'Nasdaq, Inc.'],
                'sources' => [['quote' => $quote, 'start_offset' => $start,
                    'end_offset' => $start + mb_strlen($quote), 'page' => 1,
                    'span_id' => 'table:per-share']]]);
            $ids[] = $row->id;
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 8, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        foreach ($ids as $id) {
            $data = DocumentEvidence::whereKey($id)->firstOrFail()->data;
            self::assertContains('scale', $data['source_fields_verified']);
            self::assertSame('verified', $data['measurement_verification']);
        }
        $row = DocumentEvidence::whereKey($ids[0])->firstOrFail();
        $row->forceFill(['data' => [...$row->data, 'unit' => '$ millions']])->save();
        self::assertSame(['verified' => 7, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('scale_source_mismatch',
            DocumentEvidence::whereKey($ids[0])->firstOrFail()->data['grounding_rejection']);
    }

    public function test_misaligned_and_ambiguous_persisted_header_edges_never_verify(): void
    {
        [$document, , $ids] = $this->fixtureDocument(true, '$ millions');
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $reader = app(SourceProvenanceReader::class);
        $proofs = $reader->forEvidence($document, $ids, $setId);
        $current = $proofs[$ids[0]];
        $prior = $proofs[$ids[1]];
        $from = $current['roles']['value'][0]['anchor_id'];
        $year = $current['pdf_table_path']['headers'][2]['anchor_id'];
        $priorYear = $prior['pdf_table_path']['headers'][2]['anchor_id'];
        $base = DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $from)->where('to_anchor_id', $year)
            ->where('type', 'column_header');
        self::assertSame(1, $base->update(['to_anchor_id' => $priorYear]));
        self::assertSame(['verified' => 5, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame(1, DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $from)->where('to_anchor_id', $priorYear)
            ->where('type', 'column_header')->update(['to_anchor_id' => $year]));

        $edge = DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $from)->where('to_anchor_id', $year)
            ->where('type', 'column_header')->first();
        DB::table('source_relationships')->insert(['set_id' => $setId,
            'workspace_id' => $edge->workspace_id, 'document_id' => $edge->document_id,
            'from_anchor_id' => $from, 'to_anchor_id' => $priorYear,
            'type' => 'column_header', 'path_order' => 3]);
        self::assertSame(['verified' => 5, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $from)->where('to_anchor_id', $priorYear)
            ->where('type', 'column_header')->delete();

        $nineMonth = collect(DB::table('source_anchors')->where('set_id', $setId)
            ->where('kind', 'column_header')->get())->first(
                static fn ($anchor) => $anchor->source_text === 'Nine Months Ended');
        self::assertNotNull($nineMonth);
        $scope = $current['pdf_table_path']['headers'][0]['anchor_id'];
        self::assertSame(1, DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $from)->where('to_anchor_id', $scope)
            ->where('type', 'column_header')->update(['to_anchor_id' => $nineMonth->id]));
        self::assertSame(['verified' => 5, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
    }

    public function test_full_original_nasdaq_pdf_reaches_verified_chart_persistence_when_available(): void
    {
        $path = getenv('DOCINTEL_NASDAQ_PDF');
        if (! is_string($path) || ! is_file($path)) {
            $this->markTestSkipped('Set DOCINTEL_NASDAQ_PDF to the original 17-page Nasdaq release.');
        }
        [$document, $source] = $this->fixtureDocument(true, '$ millions', $path, 8);
        self::assertSame(3, $source->capabilities['projected_tables']);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 6, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame(['charts' => 3, 'points' => 6],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        foreach (DocumentChart::where('document_id', $document->id)->get() as $chart) {
            foreach ($chart->data as $point) {
                self::assertSame(8, $point['source']['page']);
            }
        }
    }

    public function test_conflicting_scale_words_in_a_real_pdf_heading_reject_the_claim(): void
    {
        $path = base_path('tests/Fixtures/financial_units_adversarial.pdf');
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(1, $source->capabilities['projected_tables']);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Conflicting units.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 4,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:conflicting-units']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        $quote = collect(explode("\n", $source->text))
            ->first(static fn ($line) => str_starts_with(trim($line), 'Net sales '));
        self::assertIsString($quote);
        $start = mb_strpos($source->text, $quote);
        $row = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'incremental:conflicting-units',
            'identity' => hash('sha256', 'net-sales-q3-2026'), 'kind' => 'metric',
            'source_id' => 'test:net-sales',
            'data' => ['label' => 'Net sales', 'value' => '18', 'period' => 'Q3 2026',
                'unit' => 'USD millions', 'subject' => 'Example Holdings, Inc.'],
            'sources' => [['quote' => $quote, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen($quote), 'page' => 1,
                'span_id' => 'table:net-sales']]]);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertTrue(app(SourceProvenanceReader::class)
            ->forEvidence($document, [$row->id], $setId)[$row->id]['capabilities']['structural']);
        self::assertSame(['verified' => 0, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('unit_source_mismatch', $row->refresh()->data['grounding_rejection']);
        self::assertSame(['charts' => 0, 'points' => 0],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_independent_pdf_proves_usd_thousands_and_rejects_millions(): void
    {
        $path = base_path('tests/Fixtures/financial_units_thousands.pdf');
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(1, $source->capabilities['projected_tables']);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'USD thousands.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 4,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:usd-thousands']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        $quote = collect(explode("\n", $source->text))
            ->first(static fn ($line) => str_starts_with(trim($line), 'Operating income '));
        self::assertIsString($quote);
        $start = mb_strpos($source->text, $quote);
        $ids = [];
        foreach ([['Q3 2025', '7'], ['Q3 2026', '9']] as [$period, $value]) {
            $row = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'incremental:usd-thousands',
                'identity' => hash('sha256', 'income'.$period), 'kind' => 'metric',
                'source_id' => 'test:income:'.$period,
                'data' => ['label' => 'Operating income', 'value' => $value,
                    'period' => $period, 'unit' => 'USD thousands',
                    'subject' => 'Example Holdings, Inc.'],
                'sources' => [['quote' => $quote, 'start_offset' => $start,
                    'end_offset' => $start + mb_strlen($quote), 'page' => 1,
                    'span_id' => 'table:income']]]);
            $ids[] = $row->id;
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 2, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame(['charts' => 1, 'points' => 2],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        $row = DocumentEvidence::whereKey($ids[0])->firstOrFail();
        $row->forceFill(['data' => [...$row->data, 'unit' => 'USD millions']])->save();
        self::assertSame(['verified' => 1, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('scale_source_mismatch',
            DocumentEvidence::whereKey($ids[0])->firstOrFail()->data['grounding_rejection']);
        self::assertSame(['charts' => 0, 'points' => 0],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    public function test_gaap_and_non_gaap_rows_remain_separate_verified_series(): void
    {
        $path = base_path('tests/Fixtures/financial_basis_adversarial.pdf');
        config()->set('document_intelligence.pdf_table_structure', true);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        self::assertSame(1, $source->capabilities['projected_tables']);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Accounting bases.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 4,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:basis']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        $ids = [];
        foreach ([
            ['GAAP operating income', '7', '9', 'gaap'],
            ['Non-GAAP operating income', '8', '10', 'non-gaap'],
        ] as [$label, $prior, $current, $basis]) {
            $quote = collect(explode("\n", $source->text))
                ->first(static fn ($line) => str_starts_with(trim($line), $label.' '));
            self::assertIsString($quote);
            $start = mb_strpos($source->text, $quote);
            foreach ([['Q3 2025', $prior], ['Q3 2026', $current]] as [$period, $value]) {
                $row = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                    'document_id' => $document->id, 'pipeline_key' => 'incremental:basis',
                    'identity' => hash('sha256', $label.$period), 'kind' => 'metric',
                    'source_id' => 'test:'.hash('sha256', $label.$period),
                    'data' => ['label' => $label, 'value' => $value, 'period' => $period,
                        'unit' => 'USD millions', 'subject' => 'Example Holdings, Inc.'],
                    'sources' => [['quote' => $quote, 'start_offset' => $start,
                        'end_offset' => $start + mb_strlen($quote), 'page' => 1,
                        'span_id' => 'table:'.hash('sha256', $quote)]]]);
                $ids[$basis][] = $row->id;
            }
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        self::assertSame(['verified' => 4, 'rejected' => 0],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        foreach ($ids as $basis => $basisIds) {
            foreach ($basisIds as $id) {
                self::assertSame($basis, DocumentEvidence::whereKey($id)->firstOrFail()->data['accounting_basis']);
            }
        }
        self::assertSame(['charts' => 2, 'points' => 4],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId),
            json_encode(array_map(static fn ($item) => [$item->label, $item->integrity],
                app(MetricCollector::class)->collect($document)['observations']), JSON_THROW_ON_ERROR));
        $titles = DocumentChart::where('document_id', $document->id)->pluck('title')->all();
        sort($titles);
        self::assertSame(['GAAP operating income', 'Non-GAAP operating income'], $titles);
        $wrong = DocumentEvidence::whereKey($ids['gaap'][0])->firstOrFail();
        $wrong->forceFill(['data' => [...$wrong->data,
            'accounting_basis' => 'non-gaap']])->save();
        self::assertSame(['verified' => 3, 'rejected' => 1],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        self::assertSame('accounting_basis_unverified', $wrong->refresh()->data['grounding_rejection']);
        self::assertSame(['charts' => 1, 'points' => 2],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
    }

    private function fixtureDocument(bool $structured, string $unit = 'USD millions',
        ?string $pdfPath = null, int $sourcePage = 1): array
    {
        $path = $pdfPath ?? base_path('tests/Fixtures/nasdaq_q3_2026_income_statement_page.pdf');
        config()->set('document_intelligence.pdf_table_structure', $structured);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);

        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Nasdaq income statement.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024),
            'year' => 2026, 'pages' => $sourcePage === 1 ? 1 : 17,
            'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:pdf-table-test']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();

        $ids = [];
        foreach ([
            ['Revenues less transaction-based expenses', '1,481', '1,315'],
            ['Total operating expenses', '749', '729'],
            ['Operating income', '732', '586'],
        ] as [$label, $current, $prior]) {
            $quote = null;
            foreach (explode("\n", $source->text) as $line) {
                if (str_starts_with(trim($line), $label.' ')) {
                    $quote = $line;
                    break;
                }
            }
            self::assertNotNull($quote);
            $start = mb_strpos($source->text, $quote);
            foreach ([['Q3 2026', $current], ['Q3 2025', $prior]] as [$period, $value]) {
                $record = DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                    'document_id' => $document->id, 'pipeline_key' => 'incremental:pdf-table-test',
                    'identity' => hash('sha256', $label.$period), 'kind' => 'metric',
                    'source_id' => 'test:'.hash('sha256', $label.$period),
                    'data' => ['label' => $label, 'value' => $value, 'period' => $period,
                        'unit' => $unit, 'subject' => 'Nasdaq, Inc.'],
                    'sources' => [['quote' => $quote, 'start_offset' => $start,
                        'end_offset' => $start + mb_strlen($quote), 'page' => $sourcePage,
                        'span_id' => 'table:'.hash('sha256', $quote)]]]);
                $ids[] = $record->id;
            }
        }

        return [$document, $source, $ids];
    }

    private function assertNoChart(Document $document, string $setId): void
    {
        self::assertSame(['verified' => 0, 'rejected' => 6],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
        $collected = app(MetricCollector::class)->collect($document);
        self::assertCount(0, array_filter($collected['observations'],
            static fn ($item) => $item->integrity['analyticalEligible']));
        self::assertSame([], app(ChartCandidateBuilder::class)
            ->build($collected['observations'])['candidates']);
        self::assertSame(0, DB::table('document_charts')->where('document_id', $document->id)->count());
    }
}
