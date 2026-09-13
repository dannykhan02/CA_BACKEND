<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\AI\DocumentIntelligenceEngine;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CaughtExceptionLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_unique_conflict_logs_exception_before_unchanged_409_response(): void
    {
        Storage::fake('documents');
        Queue::fake();
        $user = User::factory()->create(['role' => 'Viewer']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user = $user->fresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 10]);
        $driver = new \PDOException('SQLSTATE[23505]: Unique violation: duplicate document', 23505);
        $driver->errorInfo = ['23505', 7, 'duplicate document'];
        $exception = new QueryException('pgsql', 'INSERT INTO documents (...) VALUES (?)', ['private binding'], $driver);
        Document::creating(function () use ($exception) {
            throw $exception;
        });

        Sanctum::actingAs($user);
        Log::spy();
        $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ])->assertStatus(409)->assertExactJson([
            'message' => 'This exact file has already been uploaded.',
            'data' => null,
        ]);

        Log::shouldHaveReceived('error')->once()->with('Document upload conflicted with an existing file.', [
            'user_id' => $user->id,
            'workspace_id' => $user->current_workspace_id,
            'exception_class' => QueryException::class,
            'exception_message' => 'SQLSTATE[23505]: Unique violation: duplicate document',
        ]);
        $this->assertSame([], Storage::disk('documents')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_email_conflict_logs_exception_before_unchanged_409_response(): void
    {
        $user = User::factory()->create([
            'pending_email' => 'private-new@example.com',
            'pending_email_code' => Hash::make('123456'),
            'pending_email_expires_at' => now()->addMinutes(10),
        ]);
        // Exercise the existing 23000 response branch; this change does not
        // alter which database errors the controller classifies as conflicts.
        $exception = new QueryException('pgsql', 'UPDATE users SET email = ?', ['private-new@example.com'], new \PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: duplicate email', 23000
        ));
        DB::partialMock()->shouldReceive('transaction')->once()->andThrow($exception);

        Sanctum::actingAs($user);
        Log::spy();
        $this->postJson('/api/auth/change-email/confirm', ['code' => '123456'])
            ->assertStatus(409)->assertJsonPath('message', 'That email address was just taken by another account. Please choose a different one.');

        Log::shouldHaveReceived('error')->once()->with('Email change conflicted with another account.', [
            'user_id' => $user->id,
            'exception_class' => QueryException::class,
            'exception_message' => 'SQLSTATE[23000]: Integrity constraint violation: duplicate email',
        ]);
    }

    public function test_orchestrator_logs_failures_outside_job_catches_and_continues_other_stages(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create([
            'workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id,
            'name' => 'Fixture.pdf', 'type' => 'PDF', 'size_kb' => 1,
            'status' => 'Processing', 'classification' => 'Internal', 'year' => 2026,
            'extracted_text' => 'Synthetic fixture text.',
        ]);
        $recorder = Mockery::mock(PipelineStageRecorder::class);
        $recorder->shouldReceive('start')->times(5)->andThrow(new \RuntimeException('Stage recorder unavailable.'));
        $client = Mockery::mock(AnthropicClient::class);

        Log::spy();
        $result = (new DocumentIntelligenceEngine($client, $recorder))->analyze($document);
        $this->assertSame([
            'document_type' => false, 'entities' => false, 'risks' => false,
            'deadlines' => false, 'document_summary' => false,
        ], $result);
        foreach (array_keys($result) as $stage) {
            Log::shouldHaveReceived('error')->with(
                $stage === 'document_summary' ? 'Document intelligence summary failed.' : 'Document intelligence stage failed.',
                [
                    'document_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'stage' => $stage,
                    'exception_class' => \RuntimeException::class,
                    'exception_message' => 'Stage recorder unavailable.',
                ]
            )->once();
        }
    }
}
