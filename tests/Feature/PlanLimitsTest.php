<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\DocumentCredits;
use App\Services\AiCredits\PlanLimits;
use App\Services\EntitlementService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The plan ladder: trial pocket, document types, OCR and quote size. The env values are set as config in each test. */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false,
            'ai_credits.settlement_mode' => 'quoted', 'ai_credits.admission_min_ratio' => 1.0, 'document_intelligence.incremental' => false]);
    }

    /** The decided ladder: free = pdf,docx, no OCR, 18,000 tokens; starter = all types, 90,000 tokens; professional = everything. */
    private function decidedLadder(): void
    {
        config(['ai_credits.plan_limits.free' => ['allowed_types' => 'pdf,docx', 'allow_ocr' => false, 'max_quote_tokens' => 18000],
            'ai_credits.plan_limits.starter' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => 90000],
            'ai_credits.plan_limits.professional' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => null]]);
    }

    private function workspace(int $savedAi = 200): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function subscribe(Workspace $workspace, string $plan): void
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => $plan,
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
        $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => 100]);
    }

    /** A document whose quote is exactly $tokens tokens (a PDF is 3 characters per token). */
    private function document(Workspace $workspace, int $tokens, string $type = 'PDF'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => $type, 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => str_repeat('abc', $tokens)]);
    }

    private function payload(Document $document): ?array
    {
        app()->forgetScopedInstances();

        return app(DocumentCredits::class)->for($document->fresh());
    }

    private function upload(string $name, string $mime): \Illuminate\Testing\TestResponse
    {
        // Distinct content per upload: identical bytes would hit the workspace duplicate-hash rule (409). The MIME type follows the extension.
        return $this->postJson('/api/documents', ['file' => UploadedFile::fake()->createWithContent($name, 'fixture '.$mime.' '.uniqid('', true)),
            'classification' => 'Public']);
    }

    private function trial(Workspace $workspace): void
    {
        config(['ai_credits.free_trial_days' => 30, 'ai_credits.grants.free_trial_credits' => 15]);
        $workspace->credits()->update(['ai_credits_remaining' => 0]);
        self::assertTrue(app(WorkspaceCreditService::class)->grantTrial($workspace, $this->user, '203.0.113.'.random_int(1, 250), 'fp-'.uniqid()));
    }

    // ------------------------------------------------------------------ trial pocket

    public function test_a_new_trial_is_fifteen_credits_in_its_own_pocket_expiring_in_thirty_days(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);

        $row = WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail();
        self::assertSame(15, (int) $row->trial_credits_remaining);
        self::assertSame(0, (int) $row->ai_credits_remaining, 'the trial is not saved credits');
        self::assertEqualsWithDelta(30, now()->diffInDays($row->trial_credits_expires_at), 1);
        self::assertSame(15, app(CreditAccountant::class)->trialAvailable($workspace->id));
        self::assertSame(1, DB::table('credit_ledger')->where('workspace_id', $workspace->id)->where('unit', 'trial_ai_credit')
            ->where('direction', 'credit')->where('amount', 15)->where('reference', 'trial:'.$this->user->id)->count());
    }

    public function test_an_expired_trial_has_no_available_credits_and_expiry_is_written_once(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);
        WorkspaceCredit::where('workspace_id', $workspace->id)->update(['trial_credits_expires_at' => now()->subMinute()]);

        self::assertSame(0, app(CreditAccountant::class)->trialAvailable($workspace->id));
        self::assertSame(15, app(CreditAccountant::class)->expireTrial($workspace->id));
        self::assertSame(0, app(CreditAccountant::class)->expireTrial($workspace->id));
        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        self::assertSame(1, DB::table('credit_ledger')->where('workspace_id', $workspace->id)->where('reason', 'trial_expired')->count());
    }

    public function test_the_expire_command_is_a_dry_run_until_applied(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);
        WorkspaceCredit::where('workspace_id', $workspace->id)->update(['trial_credits_expires_at' => now()->subMinute()]);

        $this->artisan('credits:expire-trials')->assertSuccessful();
        self::assertSame(15, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        self::assertSame(0, DB::table('credit_ledger')->where('reason', 'trial_expired')->count());

        $this->artisan('credits:expire-trials', ['--apply' => true])->assertSuccessful();
        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        self::assertSame(1, DB::table('credit_ledger')->where('reason', 'trial_expired')->count());
    }

    public function test_an_unexpired_trial_carries_over_into_saved_credits_exactly_once(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);
        $accountant = app(CreditAccountant::class);

        self::assertSame(15, $accountant->carryOverTrial($workspace->id));
        self::assertSame(0, $accountant->carryOverTrial($workspace->id));

        $row = WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail();
        self::assertSame(0, (int) $row->trial_credits_remaining);
        self::assertSame(15, (int) $row->ai_credits_remaining);
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'trial_carryover:'.$workspace->id)->count());
        self::assertSame(1, DB::table('credit_ledger')->where('reference', 'trial_carryover_out:'.$workspace->id)->count());
    }

    public function test_an_expired_trial_carries_nothing(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);
        WorkspaceCredit::where('workspace_id', $workspace->id)->update(['trial_credits_expires_at' => now()->subMinute()]);

        self::assertSame(0, app(CreditAccountant::class)->carryOverTrial($workspace->id));
        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining'));
        self::assertSame(0, DB::table('credit_ledger')->where('reference', 'like', 'trial_carryover%')->count());
    }

    public function test_a_trial_pays_a_hold_and_settles_from_the_trial_pocket(): void
    {
        $workspace = $this->workspace(0);
        $this->trial($workspace);
        $document = $this->document($workspace, 1000);

        self::assertTrue(app(AiCreditAdmission::class)->admitDocument($document->fresh()));
        $op = DB::table('billing_operations')->where('resource_id', $document->id)->first();
        self::assertSame(CreditAccountant::TRIAL, $op->funding_bucket);
        self::assertSame(15 - (int) $op->amount_reserved, app(CreditAccountant::class)->trialAvailable($workspace->id));

        app(CreditAccountant::class)->settleDocument($document->id, $this->user->id);

        self::assertSame(15 - (int) $op->amount_reserved, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('trial_credits_remaining'));
        self::assertSame(0, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining'));
    }

    // ------------------------------------------------------------------ file types

    public function test_free_plan_rejects_spreadsheets_and_images_before_anything_is_stored(): void
    {
        $this->decidedLadder();
        Storage::fake('documents');
        Queue::fake();
        $this->workspace();

        $xlsx = $this->upload('book.xlsx', self::XLSX);
        $jpg = $this->upload('scan.jpg', 'image/jpeg');

        $xlsx->assertStatus(403)->assertJson(['code' => 'plan_upgrade_required', 'reason' => 'file_type', 'type' => 'XLSX', 'plan' => 'free', 'required_plan' => 'starter']);
        $jpg->assertStatus(403)->assertJson(['code' => 'plan_upgrade_required', 'reason' => 'file_type', 'type' => 'JPG', 'plan' => 'free', 'required_plan' => 'starter']);
        self::assertSame(0, Document::count());
        self::assertSame([], Storage::disk('documents')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_free_plan_accepts_word_and_pdf(): void
    {
        $this->decidedLadder();
        Storage::fake('documents');
        Queue::fake();
        $this->workspace();

        self::assertSame(202, $this->upload('letter.docx', self::DOCX)->getStatusCode());
        self::assertSame(202, $this->upload('report.pdf', 'application/pdf')->getStatusCode());
        self::assertSame(2, Document::count());
    }

    public function test_starter_accepts_images_and_spreadsheets(): void
    {
        $this->decidedLadder();
        Storage::fake('documents');
        Queue::fake();
        $workspace = $this->workspace();
        $this->subscribe($workspace, 'starter');

        self::assertSame(202, $this->upload('book.xlsx', self::XLSX)->getStatusCode());
        self::assertSame(202, $this->upload('scan.jpg', 'image/jpeg')->getStatusCode());
        self::assertSame(2, Document::count());
    }

    // ------------------------------------------------------------------ OCR

    public function test_free_plan_stops_a_scan_before_any_ocr_call_and_charges_nothing(): void
    {
        $this->decidedLadder();
        Http::fake();
        $workspace = $this->workspace();
        $document = $this->document($workspace, 10);

        self::assertFalse(app(AiCreditAdmission::class)->admitOcr($document->fresh(), 3));

        $fresh = $document->fresh();
        self::assertSame('Failed', $fresh->status);
        // jsonb does not keep key order, so compare the block by sorted keys.
        $expected = ['code' => 'plan_limit', 'reason' => 'ocr_required', 'plan' => 'free', 'required_plan' => 'starter'];
        $actual = array_intersect_key($fresh->ai_pipeline['credit_block'], $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame(0, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        self::assertSame(0, DB::table('credit_ledger')->where('related_id', $document->id)->count());
        self::assertSame(0, DB::table('operation_quotes')->where('resource_id', $document->id)->count());
        Http::assertNothingSent();
        self::assertSame('plan_limit', $this->payload($document)['state']);
        self::assertSame('ocr_required', $this->payload($document)['creditBlock']['reason']);
    }

    public function test_starter_may_use_ocr(): void
    {
        $this->decidedLadder();
        $workspace = $this->workspace();
        $this->subscribe($workspace, 'starter');
        $document = $this->document($workspace, 10);

        self::assertNull(app(PlanLimits::class)->ocrBlock($document->fresh()));
    }

    // ------------------------------------------------------------------ quote size

    public function test_free_plan_refuses_a_quote_above_18000_tokens_without_a_hold_or_a_charge(): void
    {
        $this->decidedLadder();
        $workspace = $this->workspace();
        $document = $this->document($workspace, 20000);

        self::assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        $fresh = $document->fresh();
        self::assertSame('Failed', $fresh->status);
        $block = $fresh->ai_pipeline['credit_block'];
        self::assertSame('plan_limit', $block['code']);
        self::assertSame('document_size', $block['reason']);
        self::assertSame(20000, $block['quote_tokens']);
        self::assertSame(18000, $block['limit_tokens']);
        self::assertSame('free', $block['plan']);
        self::assertSame('starter', $block['required_plan']);
        self::assertSame(0, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        self::assertSame(200, (int) WorkspaceCredit::where('workspace_id', $workspace->id)->value('ai_credits_remaining'));
        self::assertSame('plan_limit', $this->payload($document)['state']);
    }

    public function test_starter_refuses_above_90000_tokens_and_names_professional(): void
    {
        $this->decidedLadder();
        $workspace = $this->workspace();
        $this->subscribe($workspace, 'starter');
        $document = $this->document($workspace, 100000);

        self::assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        $block = $document->fresh()->ai_pipeline['credit_block'];
        self::assertSame('document_size', $block['reason']);
        self::assertSame('starter', $block['plan']);
        self::assertSame('professional', $block['required_plan']);
        self::assertSame(90000, $block['limit_tokens']);
    }

    public function test_professional_accepts_a_167903_token_quote(): void
    {
        $this->decidedLadder();
        $workspace = $this->workspace();
        $this->subscribe($workspace, 'professional');
        $document = $this->document($workspace, 167903);

        // 80 credits needs the customer's confirmation: parked for it, not refused.
        self::assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        $fresh = $document->fresh();
        self::assertSame('Processing', $fresh->status);
        self::assertTrue($fresh->ai_pipeline['awaiting_credit_confirmation']);
        self::assertArrayNotHasKey('credit_block', $fresh->ai_pipeline);
    }

    // ------------------------------------------------------------------ summary and defaults

    public function test_the_entitlement_summary_exposes_the_plan_trial_and_limits(): void
    {
        $this->decidedLadder();
        $workspace = $this->workspace(0);
        $this->trial($workspace);

        $summary = app(EntitlementService::class)->summary($workspace->id)['ai_credits'];

        self::assertSame('free', $summary['plan']);
        self::assertSame(15, $summary['trialCreditsRemaining']);
        self::assertNotNull($summary['trialExpiresAt']);
        self::assertSame(['PDF', 'DOCX'], $summary['allowedTypes']);
        self::assertFalse($summary['allowOcr']);
        self::assertSame(18000, $summary['maxQuoteTokens']);
        self::assertArrayHasKey('availableCredits', $summary);
    }

    public function test_with_no_env_set_nothing_is_limited_and_a_trial_is_plain_saved_credits(): void
    {
        foreach (['free', 'starter', 'professional'] as $plan) {
            self::assertSame(['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => null], config("ai_credits.plan_limits.{$plan}"));
        }
        self::assertNull(config('ai_credits.free_trial_days'));
        Storage::fake('documents');
        Queue::fake();
        $workspace = $this->workspace(0);
        $workspace->credits()->update(['ai_credits_remaining' => 0]);
        config(['ai_credits.grants.free_trial_credits' => 20]);
        self::assertTrue(app(WorkspaceCreditService::class)->grantTrial($workspace, $this->user, '198.51.100.7', 'fp-defaults'));
        $row = WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail();
        self::assertSame(20, (int) $row->ai_credits_remaining);
        self::assertSame(0, (int) $row->trial_credits_remaining);
        self::assertNull($row->trial_credits_expires_at);

        self::assertSame(202, $this->upload('book.xlsx', self::XLSX)->getStatusCode());
        $big = $this->document($workspace, 200000);
        self::assertNull(app(PlanLimits::class)->sizeBlock($big, 200000));
        self::assertNull(app(PlanLimits::class)->ocrBlock($big));
    }
}
