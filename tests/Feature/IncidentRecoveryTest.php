<?php

namespace Tests\Feature;

use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 5]);

        return Document::create([
            'workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'name' => 'Disposable.docx', 'type' => 'DOCX', 'size_kb' => 1,
            'year' => 2026, 'classification' => 'Internal', 'status' => 'Failed',
            'file_path' => 'disposable.docx',
        ]);
    }

    public function test_command_queues_full_recovery_and_requires_an_authorized_actor(): void
    {
        Queue::fake();
        $document = $this->document();
        $this->artisan('documents:reprocess', ['document' => $document->id])->assertFailed();
        Queue::assertNothingPushed();
        $this->artisan('documents:reprocess', ['document' => $document->id, '--actor' => $document->uploaded_by])->assertSuccessful();
        $this->assertSame('Processing', $document->fresh()->status);
        Queue::assertPushed(ScanUploadedFileJob::class, fn ($job) => $job->documentId === $document->id && count($job->chained) === 3);
        $this->assertSame($document->uploaded_by, $document->fresh()->last_updated_by);
    }

    public function test_soft_deleted_document_produces_a_real_warning_without_scan(): void
    {
        $document = $this->document();
        $document->delete();
        $this->assertNull(Document::find($document->id));
        $path = tempnam(sys_get_temp_dir(), 'soft-delete-warning-');
        config(['logging.default' => 'audit_test', 'logging.channels.audit_test' => [
            'driver' => 'single', 'path' => $path, 'level' => 'warning',
        ]]);
        try {
            (new ScanUploadedFileJob($document->id))->handle(app(PipelineStageRecorder::class));
            $warning = file_get_contents($path);
            $this->assertStringContainsString('WARNING', $warning);
            $this->assertStringContainsString("Document {$document->id} not found", $warning);
            $this->assertStringContainsString('possible soft-delete race', $warning);
            $this->assertDatabaseCount('processing_jobs', 0);
        } finally {
            Log::forgetChannel('audit_test');
            unlink($path);
        }
    }

    public function test_production_safety_rejects_legacy_driver(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false, 'auth.developer.expose_verification_code' => false,
            'auth.developer.expose_password_reset_token' => false,
            'document_processing.clamav_enabled' => true, 'document_processing.clamav_driver' => 'socket',
        ]);
        $this->artisan('config:check-production-safety')->assertFailed();
        config(['document_processing.clamav_driver' => 'cli']);
        $this->artisan('config:check-production-safety')->assertSuccessful();
    }
}
