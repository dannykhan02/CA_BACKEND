<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\DocumentCredits;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not enough credits ends the document as Failed with a structured, recoverable credit_block, never a crash. */
class InsufficientCreditsStateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false,
            'ai_credits.settlement_mode' => 'quoted', 'ai_credits.admission_min_ratio' => 1.0, 'document_intelligence.revision_v1' => false]);
    }

    private function workspace(int $savedAi): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function document(Workspace $workspace): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    private function payload(Document $document): ?array
    {
        app()->forgetScopedInstances();

        return app(DocumentCredits::class)->for($document->fresh());
    }

    public function test_a_credit_refusal_writes_the_block_and_holds_nothing(): void
    {
        $workspace = $this->workspace(1); // the smallest band costs 2
        $document = $this->document($workspace);

        self::assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        $fresh = $document->fresh();
        self::assertSame('Failed', $fresh->status);
        self::assertStringStartsWith('Insufficient credits: 1 available, 2 required.', $fresh->error_message);
        $block = $fresh->ai_pipeline['credit_block'];
        self::assertSame('insufficient_credits', $block['code']);
        self::assertSame(2, $block['quoted_credits']);
        self::assertSame(2, $block['required_to_start']);
        self::assertSame(0, $block['monthly_available']);
        self::assertSame(0, $block['trial_available']);
        self::assertSame(1, $block['saved_available']);
        self::assertNotEmpty($block['at']);
        self::assertSame(0, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        self::assertSame(0, DB::table('credit_ledger')->where('related_id', $document->id)->count());
    }

    public function test_the_payload_reports_the_insufficient_credits_state(): void
    {
        $workspace = $this->workspace(1);
        $document = $this->document($workspace);
        app(AiCreditAdmission::class)->admitDocument($document->fresh());

        $payload = $this->payload($document);

        self::assertSame('insufficient_credits', $payload['state']);
        self::assertSame(2, $payload['quotedCredits']);
        self::assertNull($payload['chargedCredits']);
        self::assertSame('insufficient_credits', $payload['creditBlock']['code']);
        self::assertSame(2, $payload['creditBlock']['quotedCredits']);
        self::assertSame(2, $payload['creditBlock']['requiredToStart']);
        self::assertSame(1, $payload['creditBlock']['savedAvailable']);
        self::assertSame(0, $payload['creditBlock']['monthlyAvailable']);
        self::assertSame(0, $payload['creditBlock']['trialAvailable']);
    }

    public function test_a_retry_with_credits_clears_the_block(): void
    {
        Queue::fake();
        $workspace = $this->workspace(1);
        $document = $this->document($workspace);
        app(AiCreditAdmission::class)->admitDocument($document->fresh());
        $workspace->credits()->update(['ai_credits_remaining' => 100]);

        // The API recovery workflow documents:reprocess uses.
        $this->postJson("/api/documents/{$document->id}/reprocess")->assertStatus(202);
        self::assertSame('Processing', $document->fresh()->status);
        self::assertNull($document->fresh()->error_message);

        // Extraction would run next; once it reaches admission the credits are held and the block goes.
        $document->fresh()->forceFill(['extracted_text' => 'Revenue grew in the quarter.'])->save();
        self::assertTrue(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        self::assertArrayNotHasKey('credit_block', $document->fresh()->ai_pipeline ?? []);
        self::assertSame('reserved', $this->payload($document)['state']);
        self::assertNull($this->payload($document)['creditBlock']);
    }

    public function test_a_retry_without_credits_stays_blocked_and_refreshes_the_numbers(): void
    {
        $workspace = $this->workspace(1);
        $document = $this->document($workspace);
        app(AiCreditAdmission::class)->admitDocument($document->fresh());
        $workspace->credits()->update(['ai_credits_remaining' => 0]);
        $document->fresh()->forceFill(['status' => 'Processing'])->save();

        self::assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));

        self::assertSame(0, $document->fresh()->ai_pipeline['credit_block']['saved_available']);
        self::assertSame('insufficient_credits', $this->payload($document)['state']);
    }
}
