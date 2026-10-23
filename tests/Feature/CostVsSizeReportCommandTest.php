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

    private function run(Document $document, ?float $cost, string $status = 'success', int $attempt = 1, string $purpose = 'entities'): void
    {
        DocumentAiRun::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'purpose' => $purpose,
            'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'estimated_cost_usd' => $cost, 'status' => $status,
            'request_attempt' => $attempt, 'created_at' => now()]);
    }

    /** @return array<string, array<string, mixed>> rows keyed by document id */
    private function rows(): array
    {
        return collect(app(CostVsSizeReport::class)->rows())->keyBy('document_id')->all();
    }

    public function test_one_row_per_ready_document_with_priced_runs_and_nothing_else(): void
    {
        // A: PDF, 6,000 bytes = 2,000 tokens at 3.0. Three priced runs: one clean, one retry, one failed. A zero-cost run and Q&A are left out.
        $a = $this->document('PDF', 6000, pages: 12);
        $this->run($a, 0.010);
        $this->run($a, 0.004, attempt: 2);
        $this->run($a, 0.006, status: 'provider_error');
        $this->run($a, 0.0);
        $this->run($a, null);
        $this->run($a, 0.5, purpose: 'document_qa');
        // B: DOCX, 11,000 bytes = 1,965 tokens at 5.6 locally, but the provider counted 4,000 and the quote would use that.
        $b = $this->document('DOCX', 11000, pipeline: ['count_method' => 'anthropic', 'tokens' => 4000, 'route' => 'normal']);
        $this->run($b, 0.05);
        // C: XLSX, 1,700 bytes = 1,000 tokens at 1.7.
        $c = $this->document('XLSX', 1700);
        $this->run($c, 0.002);
        // D: a scan, 30 bytes of text but three OCR pages: at least 3 x 600 tokens.
        $d = $this->document('PNG', 30);
        foreach ([1, 2, 3] as $page) {
            OcrResult::create(['workspace_id' => $d->workspace_id, 'document_id' => $d->id, 'page_number' => $page, 'engine' => 'vision', 'raw_text' => 'x', 'confidence' => 0.9]);
            $this->run($d, 0.003, purpose: 'ocr');
        }
        // Left out: not Ready, deleted, and Ready with no priced run.
        $this->run($this->document('PDF', 3000, status: 'Processing'), 0.9);
        $deleted = $this->document('PDF', 3000);
        $this->run($deleted, 0.9);
        $deleted->delete();
        $this->document('PDF', 3000);

        $rows = $this->rows();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], array_keys($rows));

        $this->assertSame(['PDF', 12, 2000, 2000, null, 3, 1, 2], [$rows[$a->id]['file_type'], $rows[$a->id]['pages'], $rows[$a->id]['quote_tokens'],
            $rows[$a->id]['local_estimate_tokens'], $rows[$a->id]['provider_tokens'], $rows[$a->id]['calls'], $rows[$a->id]['first_try_calls'], $rows[$a->id]['failed_or_retried_calls']]);
        $this->assertEqualsWithDelta(0.020, $rows[$a->id]['total_cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.010, $rows[$a->id]['first_try_cost_usd'], 1e-9, 'cost excluding the retry and the failed call');

        $this->assertSame([4000, 1965, 4000, 'normal'], [$rows[$b->id]['quote_tokens'], $rows[$b->id]['local_estimate_tokens'], $rows[$b->id]['provider_tokens'], $rows[$b->id]['route']]);
        $this->assertSame([1000, 1000], [$rows[$c->id]['quote_tokens'], $rows[$c->id]['local_estimate_tokens']]);
        $this->assertSame([1800, 3, 3], [$rows[$d->id]['quote_tokens'], $rows[$d->id]['ocr_pages'], $rows[$d->id]['calls']]);
        $this->assertEqualsWithDelta(0.009, $rows[$d->id]['total_cost_usd'], 1e-9);
    }

    public function test_the_command_prints_csv_then_the_bucket_summary_and_changes_nothing(): void
    {
        $a = $this->document('PDF', 6000, pages: 12);
        $this->run($a, 0.010);
        $this->run($a, 0.010);
        $c = $this->document('XLSX', 1700);
        $this->run($c, 0.002);
        $before = [Document::count(), DocumentAiRun::count(), OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count()];

        $this->assertSame(0, Artisan::call('docintel:cost-vs-size-report'));
        $output = Artisan::output();

        $this->assertStringContainsString('document_id,file_type,pages,quote_tokens,local_estimate_tokens,provider_tokens,route,ocr_pages,total_cost_usd,calls,first_try_cost_usd,first_try_calls,failed_or_retried_calls', $output);
        $this->assertStringContainsString($a->id.',PDF,12,2000,2000,,,0,0.02,2,0.02,2,0', $output);
        $this->assertStringContainsString('2 Ready documents with priced runs', $output);
        foreach (['0-1k', '1-2k', '2-4k', '4-6k', '6-10k', '10-20k', '20-30k', '30-60k', '60k+', 'credits_for_p90', 'band_for_p90', 'credits_for_p90_x1.25', 'gate_band'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertStringContainsString('Current gate: needed USD = max(0.05, synthesis floor) + tokens/1000 x 0.006. Nothing was changed.', $output);
        $this->assertStringNotContainsString('SECRET-NAME', $output);
        $this->assertSame($before, [Document::count(), DocumentAiRun::count(), OperationQuote::count(), DB::table('billing_operations')->count(), DB::table('credit_ledger')->count()]);
    }

    public function test_summary_only_omits_the_per_document_rows(): void
    {
        $a = $this->document('PDF', 6000);
        $this->run($a, 0.010);

        Artisan::call('docintel:cost-vs-size-report', ['--summary-only' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('document_id,file_type', $output);
        $this->assertStringNotContainsString($a->id, $output);
        $this->assertStringContainsString('1-2k', $output);
    }

    public function test_the_days_option_limits_documents_by_creation_date(): void
    {
        $old = $this->document('PDF', 6000);
        $this->run($old, 0.010);
        Document::whereKey($old->id)->update(['created_at' => now()->subDays(40)]);
        $recent = $this->document('PDF', 6000);
        $this->run($recent, 0.010);

        $this->assertCount(2, app(CostVsSizeReport::class)->rows());
        $this->assertSame([$recent->id], array_column(app(CostVsSizeReport::class)->rows(30), 'document_id'));
    }
}
