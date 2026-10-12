<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use App\Services\WorkspaceService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadCleanupFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_database_failure_removes_unowned_file_and_rolls_back_reservation(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);
        $injected = false;
        Event::listen('eloquent.created: '.Document::class, function () use (&$injected): void {
            if (! $injected) {
                $injected = true;
                DB::select('SELECT 1 / 0');
            }
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('fault.pdf', 1, 'application/pdf'),
        ])->assertStatus(500);

        $this->assertTrue($injected);
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, Document::count());
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_unrelated_unique_violation_is_not_reported_as_duplicate_upload(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);
        Event::listen('eloquent.created: '.Document::class, function () use ($user): void {
            DB::table('workspace_members')->insert(['workspace_id' => $user->current_workspace_id,
                'user_id' => $user->id, 'role' => 'Viewer', 'joined_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('fault.pdf', 1, 'application/pdf'),
        ])->assertStatus(500);

        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, Document::count());
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_ambiguous_storage_failure_attempts_cleanup_of_generated_key(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new \RuntimeException('Connection lost after write'));
        $disk->shouldReceive('delete')->once()->withArgs(fn ($path) => preg_match('/^[0-9a-f-]{36}\/[0-9a-f-]{36}\.pdf$/', $path) === 1)->andReturn(true);
        Storage::shouldReceive('disk')->with('documents')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        app(DocumentStorageService::class)->store(
            UploadedFile::fake()->create('fault.pdf', 1, 'application/pdf'),
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    }

    public function test_allowance_failure_after_file_write_rolls_back_document_and_cleans_file(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);
        Event::listen('eloquent.created: '.Document::class, function () use ($user): void {
            $user->currentWorkspace->credits()->update(['documents_remaining' => 0]);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('allowance.pdf', 1, 'application/pdf'),
        ])->assertStatus(402);

        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, Document::count());
        $this->assertDatabaseCount('billing_operations', 0);
        $this->assertSame(3, (int) $user->currentWorkspace->credits()->value('documents_remaining'));
    }

    public function test_duplicate_arriving_after_fast_path_preserves_winning_document_and_storage(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 3]);
        $storage = new class($user) extends DocumentStorageService
        {
            public ?string $winnerId = null;

            public function __construct(private User $user) {}

            public function store(UploadedFile $file, string $workspaceId): string
            {
                $losingPath = parent::store($file, $workspaceId);
                $winnerPath = $workspaceId.'/winner.pdf';
                Storage::disk('documents')->put($winnerPath, 'winning content');
                $winner = Document::create(['workspace_id' => $workspaceId,
                    'uploaded_by' => $this->user->id, 'name' => 'Winner.pdf', 'type' => 'PDF',
                    'status' => 'Ready', 'classification' => 'Public', 'size_kb' => 1,
                    'year' => 2026, 'file_path' => $winnerPath,
                    'file_hash' => hash_file('sha256', $file->getRealPath())]);
                $this->winnerId = $winner->id;

                return $losingPath;
            }
        };
        app()->instance(DocumentStorageService::class, $storage);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('race.pdf', 1, 'application/pdf'),
        ])->assertStatus(409);

        $this->assertSame($storage->winnerId, $response->json('data.id'));
        $this->assertSame(1, Document::count());
        $this->assertSame([$user->current_workspace_id.'/winner.pdf'], Storage::disk('documents')->allFiles());
        $this->assertDatabaseCount('billing_operations', 0);
    }
}
