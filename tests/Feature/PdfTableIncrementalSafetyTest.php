<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\SourceProvenanceReader;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PdfTableIncrementalSafetyTest extends TestCase
{
    use DatabaseTransactions;

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
        config()->set('document_intelligence.server_evidence_resolution', true);
        self::assertSame(['verified' => 0, 'rejected' => 6],
            app(IncrementalMetricFieldVerifier::class)->verify($document, $setId));
    }

    public function test_page_only_baseline_rejects_the_same_six_nasdaq_observations(): void
    {
        [$document, $source, $ids] = $this->fixtureDocument(false);
        self::assertSame(['page'], array_unique(array_column($source->nodes, 'type')));
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $proofs = app(SourceProvenanceReader::class)->forEvidence($document, $ids, $setId);
        foreach ($proofs as $proof) {
            self::assertFalse($proof['capabilities']['structural']);
        }
        self::assertSame(0, DB::table('source_relationships')
            ->where('document_id', $document->id)->where('type', 'row_header')->count());
        $this->assertNoChart($document, $setId);
    }

    private function fixtureDocument(bool $structured): array
    {
        $path = base_path('tests/Fixtures/nasdaq_q3_2026_income_statement_page.pdf');
        config()->set('document_intelligence.pdf_table_structure', $structured);
        $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);

        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Nasdaq income statement.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 117,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
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
                        'unit' => 'USD millions', 'subject' => 'Nasdaq, Inc.'],
                    'sources' => [['quote' => $quote, 'start_offset' => $start,
                        'end_offset' => $start + mb_strlen($quote), 'page' => 1,
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
