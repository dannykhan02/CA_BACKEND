<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Documents\UploadDispatchRecovery;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function upload(): Document
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'Viewer']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user = $user->fresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);

        $id = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf'),
        ])->assertAccepted()->json('data.id');

        return Document::findOrFail($id);
    }

    public function test_unclaimed_upload_is_terminal_and_does_not_replay_billable_work(): void
    {
        $document = $this->upload();
        $pending = ProcessingJob::where('document_id', $document->id)->where('stage', 'virus_scan')->firstOrFail();
        $this->assertSame('pending', $pending->status);

        $this->assertTrue(app(UploadDispatchRecovery::class)->failIfUnclaimed($document->id, null));
        $this->assertSame('Failed', $document->fresh()->status);
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertFalse(app(UploadDispatchRecovery::class)->failIfUnclaimed($document->id, null));
        $this->assertSame(1, $document->processingJobs()->count());
    }

    public function test_worker_claim_prevents_dispatch_recovery_from_failing_the_document(): void
    {
        $document = $this->upload();
        app(UploadDispatchRecovery::class)->claim($document, null);

        $this->assertFalse(app(UploadDispatchRecovery::class)->failIfUnclaimed($document->id, null));
        $this->assertSame('Processing', $document->fresh()->status);
        $this->assertSame('skipped', $document->processingJobs()->first()->status);
    }

    public function test_stale_unclaimed_upload_is_closed_by_scheduled_command(): void
    {
        $document = $this->upload();
        ProcessingJob::where('document_id', $document->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('documents:recover-upload-dispatches')->assertSuccessful();
        $this->assertSame('Failed', $document->fresh()->status);
    }
}
