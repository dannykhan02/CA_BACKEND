<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A quote that needs the customer's confirmation (30+ credits) must not start any paid AI before it is given. */
class HoldAiUntilConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.local_estimate_multiplier' => 1.0, 'ai_credits.dense_shift.enabled' => false,
            'document_intelligence.incremental' => false]);
    }

    private function workspace(int $savedAi = 200): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    /** 60,000 estimated tokens x 1.6 for a spreadsheet = 96,000: the very_large band, 80 credits. */
    private function xlsx(Workspace $workspace): Document
    {
        return Document::create(['name' => 'Prices.xlsx', 'type' => 'XLSX', 'size_kb' => 141, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id,
            'extracted_text' => str_repeat('Revenue grew in the quarter. ', 6000)]);
    }

    private function neverCalled(): AnthropicClient
    {
        $client = \Mockery::mock(AnthropicClient::class);
        $client->shouldNotReceive('extractDocumentInsights');

        return $client;
    }

    private function park(Document $document): void
    {
        $this->assertFalse(app(AiCreditAdmission::class)->admitDocument($document->fresh()));
    }

    private function reserved(Document $document): ?object
    {
        return DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
    }

    public function test_an_80_credit_xlsx_quote_starts_no_ai_job_until_confirmed(): void
    {
        Queue::fake();
        Http::fake();
        $document = $this->xlsx($this->workspace());
        $this->park($document);

        $pipeline = $document->fresh()->ai_pipeline;
        $this->assertSame(80, $pipeline['credit_quote']['credits']);
        $this->assertSame('very_large', $pipeline['credit_quote']['band']);

        // The upload chain reaches the insights stage right after extraction parked the document.
        (new GenerateInsightsJob($document->id))->handle($this->neverCalled(), app(PipelineStageRecorder::class));

        $fresh = $document->fresh();
        $this->assertSame('Processing', $fresh->status);
        $this->assertTrue($fresh->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertSame('Waiting for your confirmation', $fresh->ai_pipeline['progress_stage']['label']);
        $this->assertTrue($fresh->ai_pipeline['insights_held']);
        $this->assertNull($this->reserved($document));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_confirming_reserves_credits_and_runs_the_held_analysis_exactly_once(): void
    {
        Queue::fake();
        Http::fake();
        $document = $this->xlsx($this->workspace());
        $this->park($document);
        (new GenerateInsightsJob($document->id))->handle($this->neverCalled(), app(PipelineStageRecorder::class));

        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202);
        Queue::assertPushed(ResumeAfterCreditConfirmationJob::class, 1);
        $this->assertNull($this->reserved($document)); // the click itself reserves nothing

        (new ResumeAfterCreditConfirmationJob($document->id))->handle();
        (new ResumeAfterCreditConfirmationJob($document->id))->handle(); // a repeated resume must not start it twice

        $this->assertSame(80, $this->reserved($document)->amount_reserved);
        Queue::assertPushed(GenerateInsightsJob::class, 1);
        $fresh = $document->fresh();
        $this->assertSame('Processing', $fresh->status);
        $this->assertFalse($fresh->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertNotSame('awaiting_confirmation', $fresh->ai_pipeline['progress_stage']['key']);
        Http::assertNothingSent();
    }

    public function test_declining_releases_everything_and_charges_nothing(): void
    {
        Queue::fake();
        $workspace = $this->workspace(200);
        $document = $this->xlsx($workspace);
        $this->park($document);

        $this->postJson("/api/documents/{$document->id}/decline-credits")->assertOk();

        $fresh = $document->fresh();
        $this->assertSame('Failed', $fresh->status);
        $this->assertFalse($fresh->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertNull($this->reserved($document));
        $this->assertSame(200, (int) $workspace->credits()->value('ai_credits_remaining'));
        $this->assertSame(0, DB::table('credit_ledger')->where('workspace_id', $workspace->id)->where('direction', 'debit')->count());
        $this->postJson("/api/documents/{$document->id}/decline-credits")->assertStatus(409);
        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(409);
    }

    public function test_a_confirmed_quote_cannot_be_declined(): void
    {
        Queue::fake();
        $document = $this->xlsx($this->workspace());
        $this->park($document);
        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202);

        $this->postJson("/api/documents/{$document->id}/decline-credits")->assertStatus(409);
        $this->assertSame('Processing', $document->fresh()->status);
    }

    public function test_credits_not_reserved_keeps_the_document_waiting_instead_of_needing_review(): void
    {
        Queue::fake();
        Http::fake();
        $document = $this->xlsx($this->workspace());
        // Never parked: the provider call is the first thing to notice nothing is reserved.
        $client = \Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('extractDocumentInsights')->once()->andThrow(new AiProcessingException('credits_not_reserved'));

        (new GenerateInsightsJob($document->id))->handle($client, app(PipelineStageRecorder::class));

        $fresh = $document->fresh();
        $this->assertSame('Processing', $fresh->status);
        $this->assertTrue($fresh->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertSame('Waiting for your confirmation', $fresh->ai_pipeline['progress_stage']['label']);
        $this->assertNull($fresh->error_message);
        $this->assertNull($this->reserved($document));
    }

    public function test_a_credits_not_reserved_job_failure_never_moves_the_document_to_needs_review(): void
    {
        $document = $this->xlsx($this->workspace());

        (new GenerateInsightsJob($document->id))->failed(new AiProcessingException('credits_not_reserved'));

        $this->assertSame('Processing', $document->fresh()->status);
    }

    public function test_the_repair_command_lists_in_dry_run_and_fixes_with_apply(): void
    {
        $workspace = $this->workspace();
        $stuck = $this->xlsx($workspace);
        $this->park($stuck);
        $stuck->forceFill(['status' => 'Needs Review', 'error_message' => 'Analysis could not be completed. Please try again.'])->save();

        $confirmed = $this->xlsx($workspace);
        $this->park($confirmed);
        $confirmed->forceFill(['status' => 'Needs Review', 'ai_pipeline' => [...$confirmed->fresh()->ai_pipeline, 'credit_quote_confirmed' => true]])->save();

        $unrelated = $this->xlsx($workspace);
        $unrelated->forceFill(['status' => 'Needs Review'])->save();

        $this->artisan('credits:repair-unconfirmed')->assertSuccessful();
        $this->assertSame('Needs Review', $stuck->fresh()->status);

        $this->artisan('credits:repair-unconfirmed', ['--apply' => true])->assertSuccessful();

        $fixed = $stuck->fresh();
        $this->assertSame('Processing', $fixed->status);
        $this->assertNull($fixed->error_message);
        $this->assertTrue($fixed->ai_pipeline['awaiting_credit_confirmation']);
        $this->assertSame('awaiting_confirmation', $fixed->ai_pipeline['progress_stage']['key']);
        $this->assertSame('Needs Review', $confirmed->fresh()->status);
        $this->assertSame('Needs Review', $unrelated->fresh()->status);

        $pending = $this->getJson('/api/workspace/credits')->json('data.ai_credits.pendingConfirmations');
        $this->assertSame([$stuck->id], array_column($pending, 'documentId'));
    }
}
