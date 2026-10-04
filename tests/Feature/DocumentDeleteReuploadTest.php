<?php

namespace Tests\Feature;

use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentDeleteReuploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_duplicate_is_rejected_but_deleted_file_can_be_processed_as_new_document(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create(['role' => 'Viewer']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user = $user->fresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);
        Sanctum::actingAs($user);
        $file = fn () => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf');

        $first = $this->postJson('/api/documents', ['file' => $file()])->assertAccepted()->json('data.id');
        $this->postJson('/api/documents', ['file' => $file()])->assertStatus(409);
        $this->assertSame(1, Document::count());

        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->twice()->andReturn([
            'kpis' => [], 'charts' => [], 'insights' => [],
        ]));
        $original = Document::findOrFail($first);
        $original->update(['extracted_text' => 'First extraction.']);
        (new GenerateInsightsJob($first))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $original->refresh()->update(['insights' => ['Prior intelligence']]);
        $originalPath = $original->file_path;
        $this->deleteJson("/api/documents/{$first}")->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $first]);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $first, 'action' => 'document.deleted']);
        Storage::disk('documents')->assertExists($originalPath);

        $second = $this->postJson('/api/documents', ['file' => $file()])->assertAccepted()->json('data.id');
        $this->assertNotSame($first, $second);
        $new = Document::findOrFail($second);
        $this->assertSame($original->file_hash, $new->file_hash);
        $this->assertSame([], $new->insights);
        $this->assertNull($new->extracted_text);
        $this->assertSame(0, $new->processingJobs()->count());
        $new->update(['extracted_text' => 'Second extraction.']);
        (new GenerateInsightsJob($second))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $this->assertSame('Ready', $new->fresh()->status);
        $this->assertSame([], $new->fresh()->insights);
        $this->assertSame(1, $user->currentWorkspace->credits()->value('documents_remaining'));
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $first, 'action' => 'document.uploaded']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $second, 'action' => 'document.uploaded']);
    }
}
