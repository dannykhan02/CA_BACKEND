<?php

namespace Tests\Feature;

use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceCreditsTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(int $balance): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => $balance]);
        Sanctum::actingAs($user->fresh());

        return $workspace;
    }

    private function document(Workspace $workspace): Document
    {
        return Document::create([
            'name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1,
            'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $workspace->id, 'uploaded_by' => $workspace->users()->first()->id,
            'extracted_text' => 'Revenue increased.',
        ]);
    }

    private function runJob(Document $document): void
    {
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
    }

    public function test_empty_workspace_rejects_upload_without_storing_or_dispatching(): void
    {
        $workspace = $this->workspace(0);
        Queue::fake();
        Storage::fake('documents');
        $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf'),
            'classification' => 'Public',
        ])->assertStatus(402)->assertJsonPath('message', 'Your workspace has no document credits remaining. Purchase more to continue.');
        $this->assertSame(0, Document::where('workspace_id', $workspace->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_one_credit_accepts_upload_without_consuming_it(): void
    {
        $workspace = $this->workspace(1);
        Queue::fake();
        Storage::fake('documents');
        $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf'),
            'classification' => 'Public',
        ])->assertStatus(202);
        $this->assertSame(1, $workspace->credits->documents_remaining);
    }

    public function test_ready_consumes_once_even_after_redelivery_and_reprocessing(): void
    {
        $workspace = $this->workspace(3);
        $document = $this->document($workspace);
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->twice()->andReturn([]));
        $this->runJob($document);
        $this->runJob($document);
        $this->assertSame('Ready', $document->fresh()->status);
        $document->refresh()->update(['status' => 'Processing']);
        $this->runJob($document);
        $this->assertSame(2, $workspace->credits->documents_remaining);
        $this->assertSame(0, $workspace->credits->documents_purchased_total);
    }

    public function test_failed_analysis_does_not_consume(): void
    {
        $workspace = $this->workspace(2);
        $document = $this->document($workspace);
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->once()->andThrow(new \RuntimeException('AI unavailable')));
        $this->runJob($document);
        $this->assertSame('Failed', $document->fresh()->status);
        $this->assertNull($document->fresh()->credit_accounted_at);
        $this->assertSame(2, $workspace->credits->documents_remaining);
    }

    public function test_two_in_progress_documents_finish_but_balance_floors_at_zero(): void
    {
        $workspace = $this->workspace(1);
        $first = $this->document($workspace);
        $second = $this->document($workspace);
        Log::spy();
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->twice()->andReturn([]));
        $this->runJob($first);
        $this->runJob($second);
        $this->assertSame('Ready', $first->fresh()->status);
        $this->assertSame('Ready', $second->fresh()->status);
        $this->assertSame(0, $workspace->credits->documents_remaining);
        Log::shouldHaveReceived('warning')->once()->with('Document completed without remaining workspace credits.', \Mockery::type('array'));
    }

    public function test_balance_is_scoped_to_current_workspace(): void
    {
        $other = $this->workspace(90);
        $current = $this->workspace(2);
        $current->credits()->update(['documents_purchased_total' => 100]);
        $this->getJson('/api/workspace/credits?workspace_id='.$other->id)
            ->assertOk()->assertJsonPath('data.documents_remaining', 2)
            ->assertJsonPath('data.documents_purchased_total', 100);
    }

    private function signup(string $email, string $ip, ?string $fingerprint): TestResponse
    {
        Notification::fake();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/signup', [
            'email' => $email, 'full_name' => 'Trial User',
            'password' => 'StrongPassword1!', 'password_confirmation' => 'StrongPassword1!',
            'fingerprint' => $fingerprint,
        ]);
    }

    private function assertBalanceForEmail(string $email, int $balance): void
    {
        $this->assertSame($balance, User::where('email', $email)->firstOrFail()->currentWorkspace->credits->documents_remaining);
    }

    public function test_new_signals_receive_ten_trial_documents(): void
    {
        $this->signup('NEW@example.com', '203.0.113.1', 'browser-a')->assertCreated();
        $this->assertBalanceForEmail('new@example.com', 10);
        $this->assertDatabaseHas('trial_grants', ['email' => 'new@example.com', 'ip_address' => '203.0.113.1', 'fingerprint' => 'browser-a']);
    }

    public function test_same_email_cannot_receive_another_trial_after_account_deletion(): void
    {
        $this->signup('repeat@example.com', '203.0.113.1', 'browser-a')->assertCreated();
        // Existing accounts already fail unique-email validation. Deletion
        // exercises the independent, persistent email grant guard.
        User::where('email', 'repeat@example.com')->firstOrFail()->delete();
        $this->signup('REPEAT@example.com', '203.0.113.2', 'browser-b')->assertCreated();
        $this->assertBalanceForEmail('repeat@example.com', 0);
        $this->assertDatabaseCount('trial_grants', 1);
    }

    public function test_reused_ip_denies_trial(): void
    {
        $this->signup('first@example.com', '203.0.113.1', 'browser-a')->assertCreated();
        $this->signup('second@example.com', '203.0.113.1', 'browser-b')->assertCreated();
        $this->assertBalanceForEmail('second@example.com', 0);
    }

    public function test_reused_fingerprint_denies_trial(): void
    {
        $this->signup('first@example.com', '203.0.113.1', 'browser-a')->assertCreated();
        $this->signup('second@example.com', '203.0.113.2', 'browser-a')->assertCreated();
        $this->assertBalanceForEmail('second@example.com', 0);
    }

    public function test_null_fingerprint_does_not_match_other_nulls_but_ip_still_matches(): void
    {
        $this->signup('first@example.com', '203.0.113.1', null)->assertCreated();
        $this->signup('second@example.com', '203.0.113.2', null)->assertCreated();
        $this->signup('third@example.com', '203.0.113.2', null)->assertCreated();
        $this->assertBalanceForEmail('second@example.com', 10);
        $this->assertBalanceForEmail('third@example.com', 0);
    }
}
