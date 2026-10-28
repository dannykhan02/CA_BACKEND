<?php

namespace Tests\Feature;

use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\DocumentVisualSummaryReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class DocumentVisualSummaryTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function visuals($document): array
    {
        return app(DocumentAnalysisComposer::class)->compose($document->fresh())['visualSummaries'];
    }

    private function deadline($document, string $title, ?string $date, string $quote,
        string $dateType = 'explicit', string $status = 'open'): DocumentDeadline
    {
        return DocumentDeadline::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $document->intelligenceRevisionForRelations(),
            'title' => $title, 'deadline_type' => 'obligation', 'description' => '',
            'due_date' => $date, 'date_type' => $dateType, 'evidence' => $quote,
            'status' => $status, 'confidence' => 0.9, 'prompt_version' => 'fixture']);
    }

    public function test_one_reported_total_becomes_an_exact_kpi_without_a_new_chart_or_inference(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Contract value', '1.2500', 'KES million', '2025');
        $visuals = $this->visuals($document);
        self::assertCount(1, $visuals['kpis']);
        self::assertSame('1250000.0000', $visuals['kpis'][0]['exactValue']);
        self::assertSame('1.2500', $visuals['kpis'][0]['reportedValue']);
        self::assertSame('reported', $visuals['kpis'][0]['derivation']);
        self::assertSame([], $visuals['comparisons']);
        self::assertSame([], $visuals['timeline']);
    }

    public function test_strict_pdf_source_candidates_do_not_gain_a_kpi_without_artifact_proof(): void
    {
        $document = $this->intelligenceDocument();
        $kpi = $this->metricFinding($document, 'Contract value', '1', 'KES million', '2025');
        $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
        $row->forceFill(['data' => $row->data + ['source_candidate' => true,
            'source_file_hash' => $document->file_hash]])->save();
        self::assertSame([], $this->visuals($document)['kpis']);
    }

    public function test_kpi_identity_keeps_currency_and_scope_separate_and_rejects_conflicts(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Contract value', '1', 'KES million', '2025', ['subject' => 'Parcel A']);
        $this->metricFinding($document, 'Contract value', '1', 'USD million', '2025', ['subject' => 'Parcel A']);
        $this->metricFinding($document, 'Contract value', '1', 'KES million', '2025', ['subject' => 'Parcel B']);
        $this->metricFinding($document, 'Contract value', '2', 'KES million', '2025', ['subject' => 'Parcel B']);
        $visuals = $this->visuals($document);
        self::assertCount(2, $visuals['kpis']);
        self::assertNotContains('Parcel B', array_column($visuals['kpis'], 'scope'));
    }

    public function test_comparison_card_reuses_exact_difference_and_does_not_infer_relative_change(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts actual', '1.25', 'KES million', 'Q1 2025',
            ['metric_type' => 'actual', 'value_basis' => 'actual']);
        $this->metricFinding($document, 'Operating receipts target', '1.00', 'KES million', 'Q1 2025',
            ['metric_type' => 'target', 'value_basis' => 'target']);
        $cards = $this->visuals($document)['comparisons'];
        self::assertCount(1, $cards);
        self::assertSame('actual_vs_target', $cards[0]['kind']);
        self::assertSame('250000.00', $cards[0]['exactDifference']);
        self::assertArrayNotHasKey('relativeDifference', $cards[0]);
        self::assertCount(2, $cards[0]['sourceIds']);
    }

    public function test_zero_target_and_negative_actual_keep_exact_absolute_difference_only(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Operating receipts actual', '(0.25)', 'KES million', 'Q1 2025',
            ['metric_type' => 'actual', 'value_basis' => 'actual']);
        $this->metricFinding($document, 'Operating receipts target', '0', 'KES million', 'Q1 2025',
            ['metric_type' => 'target', 'value_basis' => 'target']);
        $cards = $this->visuals($document)['comparisons'];
        self::assertCount(1, $cards);
        self::assertSame('-250000.00', $cards[0]['exactDifference']);
        self::assertSame('KES', $cards[0]['differenceUnit']);
        self::assertArrayNotHasKey('relativeDifference', $cards[0]);
    }

    public function test_unlinked_amendment_suppresses_both_competing_kpi_cards(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Contract value actual', '10', 'KES million', '2025',
            ['metric_type' => 'actual']);
        $this->metricFinding($document, 'Contract value revised', '11', 'KES million', '2025',
            ['metric_type' => 'revised']);
        $visuals = $this->visuals($document);
        self::assertSame([], $visuals['kpis']);
        self::assertSame(2, $visuals['diagnostics']['unsupported']['revision_relationship_unproven']);
    }

    public function test_changed_source_text_withdraws_metric_and_date_visuals(): void
    {
        $document = $this->intelligenceDocument();
        $quote = 'Payment due 30 June 2025.';
        $document->forceFill(['extracted_text' => $quote])->save();
        $this->deadline($document, 'Payment due', '2025-06-30', $quote);
        $this->metricFinding($document, 'Contract value', '1', 'KES million', '2025');
        self::assertCount(1, $this->visuals($document)['timeline']);
        self::assertCount(1, $this->visuals($document)['kpis']);
        $document->forceFill(['extracted_text' => 'Corrected source with no matching date or metric.'])->save();
        $visuals = $this->visuals($document);
        self::assertSame([], $visuals['timeline']);
        self::assertSame([], $visuals['kpis']);
    }

    public function test_incremental_multispan_deadline_uses_existing_verified_source_offsets(): void
    {
        $document = $this->intelligenceDocument();
        $first = 'Land instalment is due.';
        $second = '30 June 2025';
        $text = $first.' Unrelated paragraph. '.$second;
        $document->forceFill(['extracted_text' => $text])->save();
        $deadline = $this->deadline($document, 'Land instalment', '2025-06-30', $first."\n\n".$second);
        $row = $this->evidenceRow($document, 'deadline', ['label' => $deadline->title,
            'value' => '', 'quote' => $deadline->evidence], 'deadline:'.$deadline->id);
        $secondStart = mb_strpos($text, $second);
        $row->forceFill(['data' => array_replace($row->data,
            ['source_revision' => hash('sha256', $text)]), 'sources' => [
                ['start_offset' => 0, 'end_offset' => mb_strlen($first), 'quote' => $first],
                ['start_offset' => $secondStart, 'end_offset' => $secondStart + mb_strlen($second),
                    'quote' => $second],
            ]])->save();
        self::assertCount(1, $this->visuals($document)['timeline']);
        $row->forceFill(['sources' => [['start_offset' => 0, 'end_offset' => mb_strlen($first),
            'quote' => 'wrong quote']]])->save();
        self::assertSame([], $this->visuals($document)['timeline']);
    }

    public function test_timeline_uses_explicit_source_dates_and_never_infers_payment_or_completion(): void
    {
        $document = $this->intelligenceDocument();
        $quotes = ['Land payment due 30 June 2025.', 'Inspection due 30 June 2025.',
            'Permit expires 15 July 2025.', 'Within 30 days after execution.',
            'Payment due 06/07/2025.'];
        $document->forceFill(['extracted_text' => implode("\n", $quotes)])->save();
        $first = $this->deadline($document, 'Land payment', '2025-06-30', $quotes[0]);
        $this->deadline($document, 'Inspection', '2025-06-30', $quotes[1]);
        $this->deadline($document, 'Permit expiry', '2025-07-15', $quotes[2], status: 'met');
        $this->deadline($document, 'Relative payment', null, $quotes[3], 'relative');
        $this->deadline($document, 'Ambiguous payment', '2025-07-06', $quotes[4]);
        $this->deadline($document, 'Land payment', '2025-06-30', $quotes[0]);
        $events = $this->visuals($document);
        self::assertCount(3, $events['timeline']);
        self::assertSame(['2025-06-30', '2025-06-30', '2025-07-15'], array_column($events['timeline'], 'date'));
        self::assertSame(['deadline:'.$first->id], collect($events['timeline'])->firstWhere('title', 'Land payment')['sourceIds']);
        self::assertSame('open', collect($events['timeline'])->firstWhere('title', 'Land payment')['status']);
        self::assertSame('undocumented', collect($events['timeline'])->firstWhere('title', 'Permit expiry')['status']);
        self::assertSame('source_reference', $events['timeline'][0]['evidenceAssurance']);
        self::assertArrayNotHasKey('amount', $events['timeline'][0]);
        self::assertSame(1, $events['diagnostics']['unsupported']['duplicate_timeline_event']);
        self::assertSame(1, $events['diagnostics']['unsupported']['timeline_date_not_explicit']);
        self::assertSame(1, $events['diagnostics']['unsupported']['timeline_source_date_unproven']);
        self::assertSame(1, $events['diagnostics']['unsupported']['timeline_status_unproven']);
    }

    public function test_candidate_revision_does_not_expose_its_deadline_before_publication(): void
    {
        $document = $this->intelligenceDocument();
        $quoteA = 'Payment due 30 June 2025.';
        $quoteB = 'Payment due 31 July 2025.';
        $snapshot = ['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'file_hash' => $document->file_hash, 'extracted_text' => $quoteA,
            'extracted_text_hash' => hash('sha256', $quoteA)];
        $a = DocumentIntelligenceRevision::create($snapshot + ['generation' => 1, 'state' => 'published',
            'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']]);
        $document->forceFill(['published_intelligence_revision_id' => $a->id,
            'active_intelligence_revision_id' => $a->id, 'intelligence_generation' => 1,
            'extracted_text' => $quoteA, 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-a']])->save();
        $this->deadline($document, 'Original due date', '2025-06-30', $quoteA)
            ->forceFill(['intelligence_revision_id' => $a->id])->save();
        $b = DocumentIntelligenceRevision::create(array_replace($snapshot,
            ['extracted_text' => $quoteB, 'extracted_text_hash' => hash('sha256', $quoteB)])
            + ['generation' => 2, 'state' => 'candidate',
                'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b']]);
        $document->forceFill(['active_intelligence_revision_id' => $b->id,
            'extracted_text' => $quoteB, 'ai_pipeline' => ['route' => 'incremental', 'key' => 'pipeline-b']])->save();
        $this->deadline($document, 'Candidate due date', '2025-07-31', $quoteB)
            ->forceFill(['intelligence_revision_id' => $b->id])->save();
        $service = app(DocumentIntelligenceService::class);
        $published = $service->loadIntelligence($document->fresh());
        $originalVisuals = app(DocumentAnalysisComposer::class)->compose($published)['visualSummaries'];
        self::assertSame($a->id, $originalVisuals['owner']['intelligenceRevisionId']);
        self::assertSame(['Original due date'], array_column($originalVisuals['timeline'], 'title'));
        $b->forceFill(['state' => 'published'])->save();
        $document->forceFill(['published_intelligence_revision_id' => $b->id,
            'active_intelligence_revision_id' => null])->save();
        $replacement = $service->loadIntelligence($document->fresh());
        $replacementVisuals = app(DocumentAnalysisComposer::class)->compose($replacement)['visualSummaries'];
        self::assertSame($b->id, $replacementVisuals['owner']['intelligenceRevisionId']);
        self::assertSame(['Candidate due date'], array_column($replacementVisuals['timeline'], 'title'));
    }

    public function test_timeline_payload_is_bounded_without_extra_queries(): void
    {
        $document = $this->intelligenceDocument();
        $quotes = [];
        foreach (range(1, 30) as $day) {
            $quote = 'Inspection due '.sprintf('%02d', $day).' June 2025.';
            $quotes[] = $quote;
            $this->deadline($document, 'Inspection '.$day, '2025-06-'.sprintf('%02d', $day), $quote);
        }
        $document->forceFill(['extracted_text' => implode("\n", $quotes)])->save();
        $visuals = $this->visuals($document);
        self::assertCount(24, $visuals['timeline']);
        self::assertSame(6, $visuals['diagnostics']['omittedEvents']);
        $loaded = $document->fresh()->load('deadlines');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $read = app(DocumentVisualSummaryReadModel::class)->build($loaded, [], [], [], [], collect());
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $bytes = strlen(json_encode($read, JSON_THROW_ON_ERROR));
        self::assertSame(0, $queries);
        self::assertLessThan(20_000, $bytes);
        fwrite(STDERR, json_encode(['fixture' => 'stage3-30-events',
            'visual_build_ms' => round($elapsedMs, 2), 'visual_payload_bytes' => $bytes,
            'visual_queries' => $queries], JSON_THROW_ON_ERROR)."\n");
    }
}
