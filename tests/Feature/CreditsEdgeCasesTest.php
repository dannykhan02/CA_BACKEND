<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Edge cases of the credit engine found in the billing audit: one test per bug fixed, plus the cases that were already handled. */
class CreditsEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.settlement_mode' => 'actual', 'ai_credits.admission_min_ratio' => 0.4,
            'ai_credits.saved_fallback_during_subscription' => true, 'ai_credits.settlement_buffer' => 1.25, 'ai_credits.settlement_min' => 1,
            'ai_credits.provider_cost_usd_per_credit' => 0.027, 'document_intelligence.incremental' => false,
            'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false]);
    }

    private function workspace(int $savedAi = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function monthly(Workspace $workspace, int $allowed, int $used = 0): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $allowed, 'ai_credits_used' => $used]);
    }

    private function document(Workspace $workspace, string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    private function quote(Workspace $workspace, Document $document, int $credits = 6): OperationQuote
    {
        return app(QuoteService::class)->issue($workspace->id, 'document', $document->id,
            ['band' => 'regular', 'credits' => $credits, 'cap' => $credits * 0.027, 'preflight' => ['pages' => 3]]);
    }

    private function cost(Workspace $workspace, Document $document, OperationQuote $quote, string $cost): void
    {
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash,
            'purpose' => 'entities', 'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1,
            'status' => 'success', 'estimated_cost_usd' => $cost, 'operation_quote_id' => $quote->id, 'created_at' => now()]);
    }

    private function saved(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining');
    }

    private function trialRow(Workspace $workspace, int $remaining, $expiresAt): void
    {
        WorkspaceCredit::where('workspace_id', $workspace->id)->update(['trial_credits_remaining' => $remaining, 'trial_credits_expires_at' => $expiresAt]);
    }

    // ------------------------------------------------------------------ A21

    public function test_a21_a_fixed_price_operation_never_starts_on_a_partial_hold(): void
    {
        $workspace = $this->workspace();
        $this->monthly($workspace, 5); // 40% of a 12-credit comparison is 5
        $comparison = app(QuoteService::class)->issue($workspace->id, 'comparison', (string) Str::uuid(),
            ['band' => 'comparison', 'credits' => 12, 'cap' => 0.324, 'preflight' => ['kind' => 'ai_terms']]);

        try {
            app(CreditAccountant::class)->reserve($comparison, $this->user->id);
            self::fail('A 12-credit comparison must not be held at 5 credits and then charged 5.');
        } catch (HttpException $e) {
            self::assertSame(402, $e->getStatusCode());
            self::assertSame('Insufficient credits: 5 available, 12 required.', $e->getMessage());
        }
        self::assertSame(0, DB::table('billing_operations')->where('quote_id', $comparison->id)->count());

        // A document in the same position may still start on the partial hold.
        $document = $this->document($workspace);
        $documentQuote = $this->quote($workspace, $document, 12);
        app(CreditAccountant::class)->reserve($documentQuote, $this->user->id);
        self::assertSame(5, (int) DB::table('billing_operations')->where('quote_id', $documentQuote->id)->value('amount_reserved'));
    }
}
