<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OcrResult;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\CostVsSizeReport;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** docintel:cost-vs-size-report against fixtures: what is counted, what is left out, and that nothing is written. */
class CostVsSizeReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
    }

    private function document(string $type, int $bytes, string $status = 'Ready', int $pages = 0, ?array $pipeline = null): Document
    {
        $document = Document::create(['name' => 'SECRET-NAME.pdf', 'type' => $type, 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $this->workspace->id, 'uploaded_by' => $this->user->id, 'pages' => $pages,
            'extracted_text' => str_repeat('x', $bytes)]);
        if ($pipeline !== null) {
            $document->forceFill(['ai_pipeline' => $pipeline])->save();
        }

        return $document;
    }

    private function recordRun(Document $document, ?float $cost, string $status = 'success', int $attempt = 1, string $purpose = 'entities', string $model = 'claude-haiku-4-5-20251001'): void
    {
        DocumentAiRun::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'purpose' => $purpose,
            'provider' => 'anthropic', 'model' => $model, 'estimated_cost_usd' => $cost, 'status' => $status,
            'request_attempt' => $attempt, 'created_at' => now()]);
    }

    /** @return array<string, array<string, mixed>> rows keyed by document id */
    private function rows(): array
    {
        return collect(app(CostVsSizeReport::class)->rows())->keyBy('document_id')->all();
    }

    public function test_one_row_per_ready_document_with_priced_runs_and_nothing_else(): void
    {
        // A: PDF, 6,000 bytes = 2,000 tokens at 3.0, x1.15 local-estimate margin = 2,300. Three priced runs: one clean, one retry, one failed. A zero-cost run and Q&A are left out.
        $a = $this->document('PDF', 6000, pages: 12);
        $this->recordRun($a, 0.010);
        $this->recordRun($a, 0.004, attempt: 2);
        $this->recordRun($a, 0.006, status: 'provider_error');
        $this->recordRun($a, 0.0);
        $this->recordRun($a, null);
        $this->recordRun($a, 0.5, purpose: 'document_qa');
        // B: DOCX, 11,000 bytes = 1,965 tokens at 5.6 (x1.15 = 2,260) locally, but the provider counted 4,000 and the quote would use that.
        $b = $this->document('DOCX', 11000, pipeline: ['count_method' => 'anthropic', 'tokens' => 4000, 'route' => 'normal']);
        $this->recordRun($b, 0.05);
        // C: XLSX, 1,700 bytes = 1,000 tokens at 1.7.
        $c = $this->document('XLSX', 1700);
        $this->recordRun($c, 0.002);
        // D: a scan, 30 bytes of text but three OCR pages: at least 3 x 600 tokens.
        $d = $this->document('PNG', 30);
        foreach ([1, 2, 3] as $page) {
            OcrResult::create(['workspace_id' => $d->workspace_id, 'document_id' => $d->id, 'page_number' => $page, 'engine' => 'vision', 'raw_text' => 'x', 'confidence' => 0.9]);
            $this->recordRun($d, 0.003, purpose: 'ocr');
        }
        // Left out: not Ready, deleted, and Ready with no priced run.
        $this->recordRun($this->document('PDF', 3000, status: 'Processing'), 0.9);
        $deleted = $this->document('PDF', 3000);
        $this->recordRun($deleted, 0.9);
        $deleted->delete();
        $this->document('PDF', 3000);

        $rows = $this->rows();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], array_keys($rows));

        $this->assertSame(['PDF', 12, 2300, 2300, null, 3, 1, 2], [$rows[$a->id]['file_type'], $rows[$a->id]['pages'], $rows[$a->id]['quote_tokens'],
            $rows[$a->id]['local_estimate_tokens'], $rows[$a->id]['provider_tokens'], $rows[$a->id]['calls'], $rows[$a->id]['first_try_calls'], $rows[$a->id]['failed_or_retried_calls']]);
        $this->assertEqualsWithDelta(0.020, $rows[$a->id]['total_cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.010, $rows[$a->id]['first_try_cost_usd'], 1e-9, 'cost excluding the retry and the failed call');

        $this->assertSame([4000, 2260, 4000, 'normal'], [$rows[$b->id]['quote_tokens'], $rows[$b->id]['local_estimate_tokens'], $rows[$b->id]['provider_tokens'], $rows[$b->id]['route']]);
        $this->assertSame([1044, 1044], [$rows[$c->id]['quote_tokens'], $rows[$c->id]['local_estimate_tokens']]); // 1,700 bytes = 567 tokens x1.15 x1.6 (spreadsheet)
        $this->assertSame([1800, 3, 3], [$rows[$d->id]['quote_tokens'], $rows[$d->id]['ocr_pages'], $rows[$d->id]['calls']]);
        $this->assertEqualsWithDelta(0.009, $rows[$d->id]['total_cost_usd'], 1e-9);
    }

    public function test_the_command_prints_csv_then_the_bucket_summary_and_changes_nothing(): void
    {
        $a = $this->document('PDF', 6000, pages: 12);
        $this->recordRun($a, 0.010);
        $this->recordRun($a, 0.010);
        $c = $this->document('XLSX', 1700);
        $this->recordRun($c, 0.002);
        $before = [Document::count(), DocumentAiRun::count(), OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(),
            DB::table('provider_call_attempts')->count(), DB::table('documents')->orderBy('id')->get(['id', 'ai_pipeline', 'updated_at', 'status'])->all()];

        $this->assertSame(0, Artisan::call('docintel:cost-vs-size-report'));
        $output = Artisan::output();

        $this->assertStringContainsString('document_id,file_type,pages,quote_tokens,local_estimate_tokens,provider_tokens,route,ocr_pages,total_cost_usd,calls,first_try_cost_usd,first_try_calls,failed_or_retried_calls', $output);
        $this->assertStringContainsString($a->id.',PDF,12,2300,2300,,,0,0.02,2,0.02,2,0', $output);
        $this->assertStringContainsString('2 Ready documents with priced runs', $output);
        foreach (['0-1k', '1-2k', '2-4k', '4-6k', '6-10k', '10-20k', '20-30k', '30-60k', '60k+', 'credits_for_p90', 'band_for_p90', 'credits_for_p90_x1.25', 'edge_band', 'edge_covers_max',
            'median_would_credits', 'median_would_cap_over_cost', 'would_minus_needed_x1.25', 'would_band', 'stage_extraction_usd', 'cost_entities', 'model_entities'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertStringContainsString('Bands by token edge: small <= 4000 tokens = 2 credits (cap USD 0.054)', $output);
        $this->assertStringContainsString('Nothing was changed.', $output);
        $this->assertStringNotContainsString('SECRET-NAME', $output);
        $this->assertEquals($before, [Document::count(), DocumentAiRun::count(), OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count(),
            DB::table('provider_call_attempts')->count(), DB::table('documents')->orderBy('id')->get(['id', 'ai_pipeline', 'updated_at', 'status'])->all()]);
        $this->assertSame(0, OperationQuote::count(), 'the would-quote columns are computed, never saved');
    }

    public function test_summary_only_omits_the_per_document_rows(): void
    {
        $a = $this->document('PDF', 6000);
        $this->recordRun($a, 0.010);

        Artisan::call('docintel:cost-vs-size-report', ['--summary-only' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('document_id,file_type', $output);
        $this->assertStringNotContainsString($a->id, $output);
        $this->assertStringContainsString('2-4k', $output);
    }

    public function test_the_days_option_limits_documents_by_creation_date(): void
    {
        $old = $this->document('PDF', 6000);
        $this->recordRun($old, 0.010);
        Document::whereKey($old->id)->update(['created_at' => now()->subDays(40)]);
        $recent = $this->document('PDF', 6000);
        $this->recordRun($recent, 0.010);

        $this->assertCount(2, app(CostVsSizeReport::class)->rows());
        $this->assertSame([$recent->id], array_column(app(CostVsSizeReport::class)->rows(30), 'document_id'));
    }

    /** @return array<string, array<string, string>> csv lines keyed by document id, each mapped by column name */
    private function csvByDocument(array $options = []): array
    {
        Artisan::call('docintel:cost-vs-size-report', $options);
        $lines = [];
        foreach (explode("\n", Artisan::output()) as $line) {
            if (str_starts_with($line, 'document_id,') || preg_match('/^[0-9a-f-]{36},/', $line)) {
                $lines[] = str_getcsv($line);
            }
        }
        $header = array_shift($lines);

        return collect($lines)->mapWithKeys(fn ($values) => [$values[0] => array_combine($header, $values)])->all();
    }

    public function test_cost_is_broken_down_by_stage_and_by_real_purpose_with_the_model_used(): void
    {
        $d = $this->document('PDF', 6000);
        $this->recordRun($d, 0.020, purpose: 'entities');
        $this->recordRun($d, 0.010, purpose: 'entities', model: 'claude-sonnet-5-5');
        $this->recordRun($d, 0.080, purpose: 'document_summary', model: 'claude-sonnet-5-5');
        $this->recordRun($d, 0.020, purpose: 'document_summary', model: 'claude-sonnet-5-5');
        $this->recordRun($d, 0.040, purpose: 'brief_synthesis', model: 'claude-sonnet-5-5');
        $this->recordRun($d, 0.003, purpose: 'ocr');
        $this->recordRun($d, 0.005, purpose: 'chart_vision');
        $this->recordRun($d, 0.002, purpose: 'kpi_identity');
        $this->recordRun($d, 0.5, purpose: 'document_qa', model: 'claude-sonnet-5-5');

        $row = $this->rows()[$d->id];

        $this->assertEqualsWithDelta(0.03, $row['by_purpose']['entities']['cost'], 1e-9);
        $this->assertSame([2, 'claude-haiku-4-5-20251001|claude-sonnet-5-5'], [$row['by_purpose']['entities']['calls'], $row['by_purpose']['entities']['models']]);
        $this->assertSame('claude-sonnet-5-5', $row['by_purpose']['document_summary']['models']);
        $this->assertSame(['brief_synthesis', 'chart_vision', 'document_summary', 'entities', 'kpi_identity', 'ocr'], array_keys($row['by_purpose']), 'real purpose values, Q&A left out');
        // extraction = entities + kpi_identity, synthesis = document_summary + brief_synthesis, then ocr and chart vision on their own.
        $this->assertEqualsWithDelta(0.032, $row['stage_extraction_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.14, $row['stage_synthesis_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.003, $row['stage_ocr_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.005, $row['stage_chart_vision_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $row['stage_other_usd'], 1e-9);
        $this->assertEqualsWithDelta($row['total_cost_usd'], $row['stage_extraction_usd'] + $row['stage_synthesis_usd'] + $row['stage_ocr_usd'] + $row['stage_chart_vision_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.08, $row['largest_request_usd'], 1e-9);
        $this->assertSame('document_summary', $row['largest_request_purpose']);

        $csv = $this->csvByDocument()[$d->id];
        $this->assertSame(['0.03', 'claude-haiku-4-5-20251001|claude-sonnet-5-5', '0.1', 'claude-sonnet-5-5', '0.003', '0.032', '0.14', '0.08', 'document_summary'],
            [$csv['cost_entities'], $csv['model_entities'], $csv['cost_document_summary'], $csv['model_document_summary'], $csv['cost_ocr'], $csv['stage_extraction_usd'],
                $csv['stage_synthesis_usd'], $csv['largest_request_usd'], $csv['largest_request_purpose']]);
        $this->assertArrayNotHasKey('cost_document_qa', $csv);
    }

    public function test_the_current_quote_is_computed_for_each_document_without_saving_anything(): void
    {
        config(['document_intelligence.incremental' => false]);
        $a = $this->document('PDF', 6000);                                     // 2,000 tokens x1.15 = 2,300
        $this->recordRun($a, 0.02);
        $b = $this->document('DOCX', 11000, pipeline: ['count_method' => 'anthropic', 'tokens' => 4000]);
        $this->recordRun($b, 0.05);
        $c = $this->document('XLSX', 1700);                                    // 567 x1.15 x1.6 = 1,044, dense: up one band
        $this->recordRun($c, 0.002);
        $d = $this->document('PNG', 30);                                       // three OCR pages: 1,800 tokens
        foreach ([1, 2, 3] as $page) {
            OcrResult::create(['workspace_id' => $d->workspace_id, 'document_id' => $d->id, 'page_number' => $page, 'engine' => 'vision', 'raw_text' => 'x', 'confidence' => 0.9]);
        }
        $this->recordRun($d, 0.003, purpose: 'ocr');
        $e = $this->document('PDF', 750000);                                   // 250,000 tokens x1.15: over the top band
        $this->recordRun($e, 0.9);

        $rows = $this->rows();

        $this->assertSame([2300, 'local_estimate', 'small', 2, 0.054, 0.0, 'normal'], [$rows[$a->id]['would_tokens'], $rows[$a->id]['token_source'], $rows[$a->id]['would_band'],
            $rows[$a->id]['would_credits'], $rows[$a->id]['would_cap_usd'], $rows[$a->id]['synthesis_floor_usd'], $rows[$a->id]['would_route']]);
        $this->assertSame([4000, 'provider_count', 'small', 2], [$rows[$b->id]['would_tokens'], $rows[$b->id]['token_source'], $rows[$b->id]['would_band'], $rows[$b->id]['would_credits']]);
        $this->assertSame([1044, 'local_estimate', 'medium', 6], [$rows[$c->id]['would_tokens'], $rows[$c->id]['token_source'], $rows[$c->id]['would_band'], $rows[$c->id]['would_credits']]);
        $this->assertSame([1800, 'ocr_pages', 'small', 2], [$rows[$d->id]['would_tokens'], $rows[$d->id]['token_source'], $rows[$d->id]['would_band'], $rows[$d->id]['would_credits']]);
        $this->assertSame(['declined', null, null], [$rows[$e->id]['would_band'], $rows[$e->id]['would_credits'], $rows[$e->id]['would_cap_usd']]);
        $this->assertSame(0, OperationQuote::count());
        $this->assertSame(0, DB::table('billing_operations')->count());

        $csv = $this->csvByDocument();
        $this->assertSame(['2300', 'local_estimate', 'small', '2', '0.054', 'normal'], [$csv[$a->id]['would_tokens'], $csv[$a->id]['token_source'], $csv[$a->id]['would_band'],
            $csv[$a->id]['would_credits'], $csv[$a->id]['would_cap_usd'], $csv[$a->id]['would_route']]);
        $this->assertSame('', $csv[$a->id]['reservation_floor_usd'], 'only incremental-route documents carry the reservation columns');
        $this->assertSame(0, OperationQuote::count());
    }

    public function test_an_incremental_document_shows_the_synthesis_reservation_against_what_synthesis_really_cost(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['document_intelligence.incremental' => true, 'document_intelligence.large_tokens' => 100]);
        $i = $this->document('PDF', 3000, pipeline: ['route' => 'incremental']);
        $this->recordRun($i, 0.020, purpose: 'entities');
        $this->recordRun($i, 0.030, purpose: 'entities');
        $this->recordRun($i, 0.050, purpose: 'document_summary', model: 'claude-sonnet-5-5');
        $n = $this->document('PDF', 3000, pipeline: ['route' => 'normal']);
        $this->recordRun($n, 0.040);

        $rows = $this->rows();
        $row = $rows[$i->id];

        $this->assertSame('incremental', $row['would_route']);
        $this->assertGreaterThan(0.1, $row['synthesis_floor_usd'], 'primary + fallback + repair are reserved even for a short document');
        $this->assertEqualsWithDelta($row['synthesis_floor_usd'], $row['reservation_floor_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $row['real_synthesis_cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(round($row['reservation_floor_usd'] / 0.05, 2), $row['floor_over_real'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $row['largest_request_usd'], 1e-9);
        // The band had to be big enough to fund that reservation, not the document's real cost.
        $this->assertGreaterThanOrEqual($row['reservation_floor_usd'], $row['would_cap_usd']);
        $this->assertNotSame('small', $row['would_band']);
        // The normal-route document has no reservation columns.
        $this->assertArrayNotHasKey('reservation_floor_usd', $rows[$n->id]);

        $csv = $this->csvByDocument()[$i->id];
        $this->assertEqualsWithDelta($row['reservation_floor_usd'], (float) $csv['reservation_floor_usd'], 1e-6);
        $this->assertSame('0.05', $csv['real_synthesis_cost_usd']);
        $this->assertSame('', $this->csvByDocument()[$n->id]['floor_over_real']);
    }

    public function test_a_document_that_ran_incrementally_but_would_be_quoted_as_normal_is_visible(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['document_intelligence.incremental' => false]);
        $i = $this->document('PDF', 3000, pipeline: ['route' => 'incremental']);
        $this->recordRun($i, 0.050, purpose: 'document_summary', model: 'claude-sonnet-5-5');

        $row = $this->rows()[$i->id];

        $this->assertSame(['incremental', 'normal', 0.0], [$row['route'], $row['would_route'], $row['synthesis_floor_usd']]);
        $this->assertGreaterThan(0.1, $row['reservation_floor_usd'], 'what the pipeline reserved, whatever the quote assumed');
    }

    public function test_skip_would_quote_leaves_those_columns_empty_and_summary_only_and_days_still_work(): void
    {
        $a = $this->document('PDF', 6000);
        $this->recordRun($a, 0.010);
        // The old document is created up front, before any command runs, so the later --days 30 check can exclude it.
        $old = $this->document('PDF', 6000);
        $this->recordRun($old, 0.010);
        Document::whereKey($old->id)->update(['created_at' => now()->subDays(60)]);

        $skipped = app(CostVsSizeReport::class)->rows(null, false)[0];
        $this->assertArrayNotHasKey('would_band', $skipped);
        $this->assertArrayHasKey('by_purpose', $skipped);

        $csv = $this->csvByDocument(['--skip-would-quote' => true])[$a->id];
        $this->assertSame(['', ''], [$csv['would_band'], $csv['would_credits']]);

        Artisan::call('docintel:cost-vs-size-report', ['--summary-only' => true, '--days' => 30]);
        $output = Artisan::output();
        $this->assertStringNotContainsString('document_id,file_type', $output);
        $this->assertStringContainsString('median_would_credits', $output);

        $this->assertSame([$a->id], array_keys($this->csvByDocument(['--days' => 30])));
    }

    public function test_the_summary_table_says_where_the_quote_charges_more_than_the_cost_needs(): void
    {
        // A 3,000-token PDF that cost 0.02 is quoted small (2 credits, cap 0.054); its p90 x 1.25 needs 1 credit.
        $a = $this->document('PDF', 8700);
        $this->recordRun($a, 0.02);
        $rows = app(CostVsSizeReport::class)->rows();
        $bucket = collect(app(CostVsSizeReport::class)->summary($rows))->firstWhere('bucket', '2-4k');

        $this->assertSame([1, 2.0, 1.0], [$bucket['documents'], $bucket['median_would_credits'], $bucket['would_minus_needed_x1.25']]);
        $this->assertEqualsWithDelta(2.7, $bucket['median_would_cap_over_cost'], 1e-9);
        $this->assertSame(0, OperationQuote::count());
    }
}