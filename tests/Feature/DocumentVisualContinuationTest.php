<?php

namespace Tests\Feature;

use App\Models\DocumentDeadline;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class DocumentVisualContinuationTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function page($document, string $family, int $offset = 0, string $revision = 'legacy')
    {
        return $this->getJson("/api/documents/{$document->id}/intelligence/visuals?".http_build_query([
            'family' => $family, 'offset' => $offset, 'revision' => $revision,
        ]));
    }

    public function test_later_charts_and_datasets_remain_reachable_without_unbounded_initial_payload(): void
    {
        $document = $this->intelligenceDocument();
        foreach (range(1, 52) as $index) {
            $label = 'Operating measure '.chr(64 + intdiv($index - 1, 26) + 1).chr(65 + ($index - 1) % 26);
            $this->metricFinding($document, $label, (string) $index, 'KES million', 'Q1 2025');
            $this->metricFinding($document, $label, (string) ($index + 1), 'KES million', 'Q2 2025');
        }
        Sanctum::actingAs(User::find($document->uploaded_by));

        $initial = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        self::assertCount(12, $initial->json('data.analysis.visualAnalysis.charts'));
        self::assertCount(12, $initial->json('data.analysis.analyticalDatasets.items'));
        self::assertGreaterThan(12, $initial->json('data.analysis.analyticalDatasets.diagnostics.eligible'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        $charts = $this->page($document, 'charts', 12)->assertOk();
        $chartMs = round((hrtime(true) - $started) / 1_000_000, 2);
        $chartQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThan(30, $chartQueries, 'One visual page must not query each candidate separately.');
        self::assertSame('legacy', $charts->json('intelligenceRevisionId'));
        self::assertSame(12, $charts->json('offset'));
        self::assertSame(12, $charts->json('returned'));
        self::assertGreaterThan(0, $charts->json('remaining'));
        self::assertEmpty(array_intersect(array_column($initial->json('data.analysis.visualAnalysis.charts'), 'id'),
            array_column($charts->json('items'), 'id')));
        $datasets = $this->page($document, 'datasets', 12)->assertOk();
        self::assertSame(12, $datasets->json('returned'));
        self::assertEmpty(array_intersect(array_column($initial->json('data.analysis.analyticalDatasets.items'), 'id'),
            array_column($datasets->json('items'), 'id')));
        self::assertSame($datasets->json('eligible') - 24, $datasets->json('remaining'));
        self::assertLessThan(131072, strlen($datasets->getContent()));
        fwrite(STDOUT, json_encode(['fixture' => 'stage4-52-candidates',
            'initial_payload_bytes' => strlen($initial->getContent()),
            'chart_page_bytes' => strlen($charts->getContent()),
            'dataset_page_bytes' => strlen($datasets->getContent()),
            'chart_page_ms' => $chartMs, 'chart_page_queries' => $chartQueries,
            'eligible_charts' => $charts->json('eligible'),
            'eligible_datasets' => $datasets->json('eligible')], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_bounded_kpi_and_timeline_pages_preserve_source_and_date_semantics(): void
    {
        $document = $this->intelligenceDocument();
        $quotes = [];
        foreach (range(1, 30) as $day) {
            $quote = 'Inspection due '.sprintf('%02d', $day).' June 2025.';
            $quotes[] = $quote;
            DocumentDeadline::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'title' => 'Inspection '.$day,
                'deadline_type' => 'obligation', 'description' => '',
                'due_date' => sprintf('2025-06-%02d', $day), 'date_type' => 'explicit',
                'evidence' => $quote, 'status' => 'open', 'confidence' => 0.9,
                'prompt_version' => 'fixture']);
        }
        $document->forceFill(['extracted_text' => implode("\n", $quotes)])->save();
        foreach (range(1, 8) as $index) {
            $this->metricFinding($document, 'Contract value '.chr(64 + $index), (string) $index,
                'KES million', '2025');
        }
        Sanctum::actingAs(User::find($document->uploaded_by));

        $initial = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        self::assertSame(30, $initial->json('data.analysis.visualSummaries.diagnostics.eligibleEvents'));
        self::assertCount(24, $initial->json('data.analysis.visualSummaries.timeline'));
        $events = $this->page($document, 'timeline', 24)->assertOk();
        self::assertSame(6, $events->json('returned'));
        self::assertSame(0, $events->json('remaining'));
        self::assertSame('2025-06-25', $events->json('items.0.date'));
        self::assertSame('source_reference', $events->json('items.0.evidenceAssurance'));
        self::assertSame(['deadline:'.$document->deadlines()->orderBy('due_date')->skip(24)->first()->id],
            $events->json('items.0.sourceIds'));
        $kpis = $this->page($document, 'kpis', 4)->assertOk();
        self::assertGreaterThan(0, $kpis->json('returned'));
        self::assertSame('source_verified', $kpis->json('items.0.evidenceAssurance'));
    }

    public function test_continuation_rejects_foreign_workspace_stale_revision_and_invalid_requests(): void
    {
        $document = $this->intelligenceDocument();
        $snapshot = ['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'file_hash' => $document->file_hash, 'extracted_text' => $document->extracted_text,
            'extracted_text_hash' => hash('sha256', $document->extracted_text)];
        $a = DocumentIntelligenceRevision::create($snapshot + ['generation' => 1, 'state' => 'published',
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']]);
        $document->forceFill(['published_intelligence_revision_id' => $a->id,
            'intelligence_generation' => 1, 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']])->save();
        Sanctum::actingAs(User::find($document->uploaded_by));
        $this->page($document, 'charts', 0, 'legacy')->assertStatus(409);
        $this->page($document, 'charts', 0, $a->id)->assertOk()->assertJsonPath('intelligenceRevisionId', $a->id);
        $this->page($document, 'charts', -1, $a->id)->assertUnprocessable();

        $intruder = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($intruder);
        Sanctum::actingAs($intruder->fresh());
        $this->page($document, 'charts', 0, $a->id)->assertForbidden();
    }

    public function test_candidate_failure_and_publication_switch_keep_pages_bound_to_the_published_pipeline(): void
    {
        $document = $this->intelligenceDocument();
        $snapshot = ['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'file_hash' => $document->file_hash, 'extracted_text' => $document->extracted_text,
            'extracted_text_hash' => hash('sha256', $document->extracted_text)];
        $a = DocumentIntelligenceRevision::create($snapshot + ['generation' => 1, 'state' => 'published',
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']]);
        $document->forceFill(['published_intelligence_revision_id' => $a->id,
            'intelligence_generation' => 1, 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']])->save();
        foreach ([['Q1 2025', '10'], ['Q2 2025', '20']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period)
                ->forceFill(['intelligence_revision_id' => $a->id])->save();
        }
        $b = DocumentIntelligenceRevision::create($snapshot + ['generation' => 2, 'state' => 'candidate',
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b']]);
        $document->forceFill(['active_intelligence_revision_id' => $b->id,
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b'], 'status' => 'Processing'])->save();
        foreach ([['Q1 2025', '30'], ['Q2 2025', '40']] as [$period, $value]) {
            $this->metricFinding($document, 'Operating receipts', $value, 'KES million', $period)
                ->forceFill(['intelligence_revision_id' => $b->id])->save();
        }
        Sanctum::actingAs(User::find($document->uploaded_by));

        $during = $this->page($document, 'datasets', 0, $a->id)->assertOk();
        self::assertSame('pipeline-a', $during->json('pipelineKey'));
        self::assertSame(['10000000', '20000000'], array_column($during->json('items.0.points'), 'exactValue'));
        $b->forceFill(['state' => 'failed'])->save();
        self::assertSame($during->json('items.0.id'), $this->page($document, 'datasets', 0, $a->id)
            ->assertOk()->json('items.0.id'));

        $b->forceFill(['state' => 'published'])->save();
        $document->forceFill(['published_intelligence_revision_id' => $b->id,
            'active_intelligence_revision_id' => null, 'status' => 'Ready'])->save();
        $this->page($document, 'datasets', 0, $a->id)->assertStatus(409);
        $replacement = $this->page($document, 'datasets', 0, $b->id)->assertOk();
        self::assertSame('pipeline-b', $replacement->json('pipelineKey'));
        self::assertSame(['30000000', '40000000'], array_column($replacement->json('items.0.points'), 'exactValue'));
        self::assertNotSame($during->json('items.0.id'), $replacement->json('items.0.id'));
    }

    public function test_changed_source_hash_withdraws_paged_pdf_metric_visuals(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts', '10', 'KES million', 'Q1 2025');
        $this->metricFinding($document, 'Operating receipts', '20', 'KES million', 'Q2 2025');
        Sanctum::actingAs(User::find($document->uploaded_by));
        self::assertSame(1, $this->page($document, 'charts')->assertOk()->json('returned'));

        $document->forceFill(['extracted_text' => 'Corrected source; earlier metrics do not match.'])->save();
        $changed = $this->page($document, 'charts')->assertOk();
        self::assertSame(0, $changed->json('returned'));
        self::assertSame([], $changed->json('items'));
        self::assertSame(0, $this->page($document, 'datasets')->assertOk()->json('returned'));
    }
}
