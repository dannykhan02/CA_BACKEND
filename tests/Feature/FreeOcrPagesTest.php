<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A plan without OCR may still read a mostly digital PDF with up to max_ocr_pages scanned pages; everything else stays blocked. */
class FreeOcrPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false,
            'ai_credits.settlement_mode' => 'quoted', 'ai_credits.admission_min_ratio' => 1.0, 'document_intelligence.incremental' => false]);
    }

    private function limits(int $freeMaxOcrPages): void
    {
        config(['ai_credits.plan_limits.free' => ['allowed_types' => 'pdf,docx', 'allow_ocr' => false, 'max_quote_tokens' => 18000, 'max_ocr_pages' => $freeMaxOcrPages],
            'ai_credits.plan_limits.starter' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => 90000, 'max_ocr_pages' => 0],
            'ai_credits.plan_limits.professional' => ['allowed_types' => '', 'allow_ocr' => true, 'max_quote_tokens' => null, 'max_ocr_pages' => 0]]);
    }

    private function workspace(): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(\App\Services\WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 200]);

        return $workspace;
    }

    /** $pages = 0 means the page count is unknown (documents.pages is NOT NULL). */
    private function pdf(Workspace $workspace, int $pages): Document
    {
        return Document::create(['name' => 'Mixed.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => str_repeat('abc', 10), 'pages' => $pages]);
    }

    public function test_with_max_ocr_pages_zero_the_ocr_required_block_is_unchanged(): void
    {
        $this->limits(0);
        $document = $this->pdf($this->workspace(), 8);

        $block = app(PlanLimits::class)->ocrBlock($document->fresh(), 1);

        self::assertSame('plan_limit', $block['code']);
        self::assertSame('ocr_required', $block['reason']);
        self::assertSame('free', $block['plan']);
        self::assertSame('starter', $block['required_plan']);
        self::assertFalse(app(AiCreditAdmission::class)->admitOcr($document->fresh(), 1));
        self::assertSame('ocr_required', $document->fresh()->ai_pipeline['credit_block']['reason']);
    }

    public function test_one_or_two_scanned_pages_of_eight_are_allowed_and_an_ocr_quote_is_issued(): void
    {
        $this->limits(2);
        $workspace = $this->workspace();
        foreach ([1, 2] as $scanned) {
            $document = $this->pdf($workspace, 8);

            self::assertNull(app(PlanLimits::class)->ocrBlock($document->fresh(), $scanned));
            self::assertTrue(app(AiCreditAdmission::class)->admitOcr($document->fresh(), $scanned));

            self::assertSame(1, DB::table('operation_quotes')->where('resource_id', $document->id)->where('kind', 'ocr')->count());
            self::assertArrayNotHasKey('credit_block', $document->fresh()->ai_pipeline ?? []);
            self::assertNotSame('Failed', $document->fresh()->status);
        }
    }

    public function test_three_scanned_pages_of_eight_are_blocked(): void
    {
        $this->limits(2);
        $document = $this->pdf($this->workspace(), 8);

        self::assertFalse(app(AiCreditAdmission::class)->admitOcr($document->fresh(), 3));

        self::assertSame('ocr_required', $document->fresh()->ai_pipeline['credit_block']['reason']);
        self::assertSame(0, DB::table('operation_quotes')->where('resource_id', $document->id)->count());
    }

    public function test_a_fully_scanned_pdf_is_blocked(): void
    {
        $this->limits(2);
        $document = $this->pdf($this->workspace(), 2);

        self::assertSame('ocr_required', app(PlanLimits::class)->ocrBlock($document->fresh(), 2)['reason']);
        self::assertFalse(app(AiCreditAdmission::class)->admitOcr($document->fresh(), 2));
    }

    public function test_an_unknown_page_count_or_unknown_scanned_count_is_blocked(): void
    {
        $this->limits(2);
        $workspace = $this->workspace();

        self::assertSame('ocr_required', app(PlanLimits::class)->ocrBlock($this->pdf($workspace, 0)->fresh(), 1)['reason']);
        self::assertSame('ocr_required', app(PlanLimits::class)->ocrBlock($this->pdf($workspace, 8)->fresh(), null)['reason']);
        self::assertSame('ocr_required', app(PlanLimits::class)->ocrBlock($this->pdf($workspace, 8)->fresh(), 0)['reason']);
    }

    public function test_a_starter_workspace_is_unaffected(): void
    {
        $this->limits(2);
        $workspace = $this->workspace();
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
        $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => 100]);
        $document = $this->pdf($workspace, 2);

        self::assertNull(app(PlanLimits::class)->ocrBlock($document->fresh(), 2));
        self::assertNull(app(PlanLimits::class)->ocrBlock($document->fresh()));
    }
}
