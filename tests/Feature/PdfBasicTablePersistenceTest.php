<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\PdfCategoricalCandidateCollector;
use App\Services\Intelligence\PdfCategoricalFieldVerifier;
use App\Services\Intelligence\PdfCategoricalFieldProof;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\Intelligence\VerifiedPdfChartPersister;
use App\Services\Intelligence\VerifiedPdfChartReadModel;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Http\Resources\ChartResource;
use App\Http\Resources\DocumentIntelligenceResource;
use Illuminate\Http\Request;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PdfBasicTablePersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_basic_grid_produces_seven_verified_source_linked_chart_points(): void
    {
        [$document, $setId, $source] = $this->prepare();
        self::assertSame(1, $source->capabilities['projected_basic_tables']);
        self::assertSame($source->parserVersion,
            app(StructuredExtractionStore::class)->active($document)->parserVersion);
        self::assertSame(['extracted' => 0, 'candidates' => 0],
            app(PdfSourceCandidateCollector::class)->collect($document));
        self::assertSame(['tables' => 1, 'candidates' => 7],
            app(PdfCategoricalCandidateCollector::class)->collect($document));
        self::assertSame(['verified' => 7, 'rejected' => 0],
            app(PdfCategoricalFieldVerifier::class)->verify($document, $setId));
        $firstEvidence = DocumentEvidence::where('document_id', $document->id)
            ->where('kind', 'table_category')->firstOrFail();
        $firstProof = app(PdfCategoricalFieldProof::class)
            ->prove($document, $firstEvidence, $setId);
        self::assertEquals($firstProof['sources'], $firstEvidence->sources);
        self::assertSame(7, DB::table('document_evidence')->where('document_id', $document->id)
            ->where('kind', 'table_category')->count());
        self::assertSame(['charts' => 1, 'points' => 7],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(['charts' => 1, 'points' => 7],
            app(VerifiedPdfChartPersister::class)->persist($document, $setId));
        self::assertSame(1, DB::table('document_charts')->where('document_id', $document->id)->count());
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        self::assertNotNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart));
        self::assertCount(7, $chart->data);
        self::assertSame('Seed', $chart->data[0]['label']);
        self::assertEquals(138400.0, $chart->data[0]['value']);
        self::assertNotContains('Total', array_column($chart->data, 'label'));
        self::assertSame('$', $chart->data[0]['sourceUnit']);
        $request = Request::create('/');
        $request->attributes->set('chart_published_document', $document);
        $api = (new ChartResource($chart))->toArray($request);
        self::assertTrue($api['renderEligible']);
        self::assertSame('categorical', $api['basis']);
        self::assertSame('categorical.v1', $api['verificationStrategy']);
        $document->forceFill(['status' => 'Ready'])->save();
        $sourceId = $chart->data[0]['sourceIds'][0];
        $reference = app(SourceReferenceResolver::class)->resolve($document->fresh(), $sourceId);
        self::assertSame('CURRENT', $reference['state']);
        self::assertSame('138,400', $reference['source']['text']);
        self::assertSame($chart->data[0]['source']['anchor_id'], $reference['location']['id']);
        self::assertNotNull($reference['location']['geometry']);
        self::assertArrayHasKey('metric', $reference['roles']);
        self::assertArrayHasKey('unit', $reference['roles']);
        self::assertContains('row_header', array_column($reference['relationships'], 'type'));
        self::assertContains('column_header', array_column($reference['relationships'], 'type'));
        $intelligence = (new DocumentIntelligenceResource($document->fresh()))->toArray($request);
        self::assertSame('138,400', ((array) $intelligence['evidence'])[$sourceId]['quote']);
    }

    public function test_changed_category_value_header_or_unit_claim_is_rejected_even_with_new_fingerprint(): void
    {
        [$document, $setId] = $this->verified();
        $row = DocumentEvidence::where('document_id', $document->id)
            ->where('kind', 'table_category')->orderBy('source_id')->firstOrFail();
        $other = DocumentEvidence::where('document_id', $document->id)
            ->where('kind', 'table_category')->where('id', '!=', $row->id)->firstOrFail();
        foreach ([
            ['category_cell_id' => $other->data['category_cell_id']],
            ['value_cell_id' => $other->data['value_cell_id']],
            ['value_column' => 3],
            ['header_cell_id' => $other->data['category_cell_id']],
            ['header_cell_id' => null],
            ['unit' => 'EUR'],
            ['period' => '2024/25'],
        ] as $change) {
            $data = [...$row->data, ...$change];
            $data['source_claim_fingerprint'] = PdfCategoricalFieldProof::fingerprint($data);
            $row->forceFill(['data' => $data])->save();
            self::assertNull(app(PdfCategoricalFieldVerifier::class)
                ->current($document, $row->fresh(), $setId));
            $row->refresh();
        }
    }

    public function test_missing_anchor_or_relationship_revokes_chart_and_source_navigation(): void
    {
        [$document, $setId] = $this->verified();
        app(VerifiedPdfChartPersister::class)->persist($document, $setId);
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        $sourceId = $chart->data[0]['sourceIds'][0];
        $valueAnchor = $chart->data[0]['source']['anchor_id'];
        DB::table('source_relationships')->where('set_id', $setId)
            ->where('from_anchor_id', $valueAnchor)->where('type', 'row_header')->delete();
        self::assertNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart));
        $document->forceFill(['status' => 'Ready'])->save();
        self::assertSame('SOURCE_UNAVAILABLE',
            app(SourceReferenceResolver::class)->resolve($document, $sourceId)['state']);
        DB::table('source_anchors')->where('id', $valueAnchor)->delete();
        self::assertNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart));
    }

    public function test_read_time_rejects_mutated_chart_point_and_stale_document_revision(): void
    {
        [$document, $setId] = $this->verified();
        app(VerifiedPdfChartPersister::class)->persist($document, $setId);
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        $original = $chart->data;
        $changed = $original;
        $changed[0]['value'] = 999;
        $chart->forceFill(['data' => $changed])->save();
        self::assertNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart->fresh()));
        $chart->forceFill(['data' => $original])->save();
        self::assertNotNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart->fresh()));
        DB::table('documents')->where('id', $document->id)
            ->update(['extracted_text' => $document->extracted_text.' changed']);
        self::assertNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart->fresh()));
    }

    public function test_categorical_trust_is_gated_by_both_default_off_flags(): void
    {
        [$document, $setId] = $this->verified();
        app(VerifiedPdfChartPersister::class)->persist($document, $setId);
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        foreach (['pdf_basic_table_structure', 'pdf_table_structure'] as $flag) {
            config()->set('document_intelligence.'.$flag, false);
            self::assertNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart));
            config()->set('document_intelligence.'.$flag, true);
            self::assertNotNull(app(VerifiedPdfChartReadModel::class)->candidate($document, $chart));
        }
    }

    public function test_caption_scale_is_verified_and_shown_with_the_printed_currency_symbol(): void
    {
        [$document, $setId] = $this->prepare('synthetic_single_period_costs_scale.pdf');
        self::assertSame(['verified' => 7, 'rejected' => 0],
            app(PdfCategoricalFieldVerifier::class)->verify($document, $setId));
        app(VerifiedPdfChartPersister::class)->persist($document, $setId);
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        $request = Request::create('/');
        $request->attributes->set('chart_published_document', $document);
        $api = (new ChartResource($chart))->toArray($request);
        self::assertSame('$ million', $api['valueFormat']['unit']);
        self::assertSame('$', $api['valueFormat']['sourceUnit']);
        self::assertSame(1000000, $api['valueFormat']['scale']);
        self::assertSame('138,400', $api['data'][0]['sourceValue']);
    }

    private function verified(): array
    {
        [$document, $setId] = $this->prepare();
        app(PdfCategoricalCandidateCollector::class)->collect($document);
        self::assertSame(['verified' => 7, 'rejected' => 0],
            app(PdfCategoricalFieldVerifier::class)->verify($document, $setId));

        return [$document, $setId];
    }

    private function prepare(string $fixture = 'synthetic_single_period_costs.pdf'): array
    {
        config()->set('document_intelligence.pdf_table_structure', true);
        config()->set('document_intelligence.pdf_basic_table_structure', true);
        $path = base_path('tests/Fixtures/'.$fixture);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Synthetic cost table.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026, 'pages' => 1,
            'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:basic-table-test']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        app(PdfCategoricalCandidateCollector::class)->collect($document);

        return [$document, app(TextRoleProvenanceProducer::class)->publish($document), $source];
    }
}
