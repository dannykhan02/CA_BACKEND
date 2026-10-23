<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\EntitlementService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests from the independent AI-credits review (docs/audits/ai-credits-findings-ledger.md).
 * Each one fails on the code as it stood before the matching fix.
 */
class AiCreditsIndependentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
    }

    private function workspace(int $savedAi): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);

        return $workspace;
    }

    /** $tokens is the local estimate: strlen / 3. 20000 tokens quotes the 10-credit standard band. */
    private function document(Workspace $workspace, int $tokens = 20000): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id,
            'extracted_text' => str_repeat('Revenue grew in the quarter. ', (int) ceil($tokens * 3 / 30))]);
    }

    private function admit(Document $document): bool
    {
        $fresh = $document->fresh();
        // Accepting the price up front is harmless for a quote that needs no confirmation; these tests are about releases.
        $fresh->forceFill(['ai_pipeline' => [...($fresh->ai_pipeline ?? []), 'credit_quote_confirmed' => true]])->save();

        return app(AiCreditAdmission::class)->admitDocument($fresh->fresh());
    }

    private function op(string $resourceId, string $kind): ?object
    {
        return DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $resourceId)->first();
    }

    private function ledger(string $workspaceId, string $direction): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('direction', $direction)->sum('amount');
    }

    /**
     * Finding C7 / ledger (c). A paid re-analysis reserves its own operation on a document that is already settled.
     * When that run fails, its hold must come back at once and the original charge must stay.
     */
    public function test_a_failed_paid_reanalysis_releases_its_hold_immediately_and_keeps_the_original_charge(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws);
        $accountant = app(CreditAccountant::class);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));

        app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), false, $this->user->id);
        $this->assertSame('reserved', $this->op($document->id, 'reanalysis')->status);
        $this->assertSame(80, $accountant->available($ws->id));

        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->releaseDocumentReservation($document->fresh(), 'synthesis_failed'));

        $this->assertSame('released', $this->op($document->id, 'reanalysis')->status);
        $this->assertSame('synthesis_failed', $this->op($document->id, 'reanalysis')->release_reason);
        $this->assertSame(90, $accountant->available($ws->id), 'the held credits are available again');
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'), 'a release never touches the counter');
        $this->assertSame('completed', $this->op($document->id, 'document')->status, 'the delivered analysis stays charged');
        $this->assertSame(10, $this->ledger($ws->id, 'release'));
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));

        // The failure handler can run twice (job retry, then the sweep): the second call changes nothing.
        $this->assertFalse($entitlements->releaseDocumentReservation($document->fresh(), 'synthesis_failed'));
        $this->assertSame(10, $this->ledger($ws->id, 'release'));
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));
    }

    /** A settled document with nothing held is still a no-op: the original rule (never refund a delivered charge) is intact. */
    public function test_releasing_a_settled_document_with_no_open_hold_changes_nothing(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws);
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        $this->assertFalse(app(EntitlementService::class)->releaseDocumentReservation($document->fresh(), 'document_removed'));
        $this->assertSame('completed', $this->op($document->id, 'document')->status);
        $this->assertSame(0, $this->ledger($ws->id, 'release'));
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    /**
     * Finding N2. The sweep covered document, OCR and re-analysis holds only, so a comparison whose job was lost
     * without calling failed() held its 12 credits forever.
     */
    public function test_an_orphaned_comparison_hold_expires_like_every_other_hold(): void
    {
        $ws = $this->workspace(100);
        $accountant = app(CreditAccountant::class);
        $comparisonId = (string) Str::uuid();
        $quote = app(QuoteService::class)->quoteComparison($ws->id, $comparisonId);
        $accountant->reserve($quote, $this->user->id);
        $this->assertSame(88, $accountant->available($ws->id));

        // A fresh hold belongs to a comparison that may still be running: the sweep leaves it alone.
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame('reserved', $this->op($comparisonId, 'comparison')->status);

        DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparisonId)
            ->update(['updated_at' => now()->subHours((int) config('billing.pipeline_hours') + 1)]);
        app(EntitlementService::class)->summary($ws->id);

        $this->assertSame('released', $this->op($comparisonId, 'comparison')->status);
        $this->assertSame('reservation_expired', $this->op($comparisonId, 'comparison')->release_reason);
        $this->assertSame('released', OperationQuote::findOrFail($quote->id)->status);
        $this->assertSame(100, $accountant->available($ws->id));
        $this->assertSame(100, (int) $ws->credits()->value('ai_credits_remaining'));
        $this->assertSame(12, $this->ledger($ws->id, 'release'));
        $this->assertSame(0, $this->ledger($ws->id, 'debit'));

        // Running the sweep again releases nothing more.
        app(EntitlementService::class)->summary($ws->id);
        $this->assertSame(12, $this->ledger($ws->id, 'release'));
    }

    /** The command that sweeps dormant workspaces must apply the same rule and must not treat comparison ids as missing documents. */
    public function test_the_hourly_command_releases_an_expired_comparison_hold_and_spares_a_fresh_one(): void
    {
        $ws = $this->workspace(100);
        $accountant = app(CreditAccountant::class);
        $stale = (string) Str::uuid();
        $fresh = (string) Str::uuid();
        foreach ([$stale, $fresh] as $id) {
            $accountant->reserve(app(QuoteService::class)->quoteComparison($ws->id, $id), $this->user->id);
        }
        DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $stale)
            ->update(['updated_at' => now()->subHours((int) config('billing.pipeline_hours') + 1)]);

        $this->artisan('billing:release-stale-reservations')->assertExitCode(0);

        $this->assertSame('released', $this->op($stale, 'comparison')->status);
        $this->assertSame('reserved', $this->op($fresh, 'comparison')->status);
        $this->assertSame(88, $accountant->available($ws->id));
    }

    /**
     * Quote validation. An OCR quote caps provider spend at pages x USD 0.02, but the provider boundary reserves a hard
     * per-request ceiling (about USD 0.028 for one vision request on the default extraction model) before it dispatches.
     * A one-page scan must be able to fund its own request.
     */
    public function test_a_one_page_scan_can_fund_its_own_vision_request(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 1000);
        $this->assertTrue(app(AiCreditAdmission::class)->admitOcr($document, 1));
        $quote = OperationQuote::where('kind', 'ocr')->firstOrFail();

        Http::fakeSequence('api.anthropic.com/*')
            ->push(['id' => 'ocr', 'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode(['text' => 'page one', 'confidence' => 0.9])]],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
        app(AnthropicClient::class)->extractTextFromImage(base64_encode('png'), 'image/png', $document->fresh());

        $this->assertSame(1, DocumentAiRun::where('operation_quote_id', $quote->id)->count());
        $this->assertEqualsWithDelta(0.0015, app(OperationSpend::class)->spentUsd($quote->id), 0.000001);
        $this->assertSame(0, (int) $quote->credits, 'reading the pages is no longer a separate customer charge');
    }

    /** The cap only changes for scans so short that pages x per-page is below one request; every longer scan keeps pages x per-page. */
    public function test_the_ocr_provider_cap_floor_leaves_longer_scans_unchanged_and_ocr_stays_free_of_credits(): void
    {
        $quotes = app(QuoteService::class);
        $perPage = (float) config('ai_credits.ocr.provider_cost_usd_per_page');
        $this->assertGreaterThan($perPage, $quotes->classifyOcr(1)['cap'], 'a single page needs headroom for one request');
        $this->assertEqualsWithDelta(5 * $perPage, $quotes->classifyOcr(5)['cap'], 0.000001);
        $this->assertEqualsWithDelta(60 * $perPage, $quotes->classifyOcr(60)['cap'], 0.000001);
        $this->assertSame([0, 0, 0, 0], [$quotes->classifyOcr(1)['credits'], $quotes->classifyOcr(20)['credits'],
            $quotes->classifyOcr(25)['credits'], $quotes->classifyOcr(60)['credits']]);
    }
}
