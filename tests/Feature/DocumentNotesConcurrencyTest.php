<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentNotesConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE users, workspaces RESTART IDENTITY CASCADE');
        }
        parent::tearDown();
    }

    public function test_simultaneous_edits_commit_one_body_and_one_complete_citation_set(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent connections.');
        }
        config(['document_intelligence.notes_enabled' => true]);
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $document = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'name' => 'Race.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => 'Internal', 'year' => 2026]);
        $note = DocumentNote::create(['workspace_id' => $workspace->id, 'document_id' => $document->id,
            'user_id' => $user->id, 'body' => 'Before', 'version' => 1]);

        $results = sys_get_temp_dir().'/document-note-race-'.bin2hex(random_bytes(8));
        mkdir($results);
        DB::disconnect();
        $children = [];
        try {
            foreach (['First', 'Second'] as $index => $body) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        Sanctum::actingAs($user->fresh());
                        $response = $this->patchJson('/api/documents/'.$document->id.'/notes/'.$note->id,
                            ['body' => $body, 'version' => 1, 'citations' => [
                                ['finding_label' => $body, 'quote_snapshot' => $body.' citation'],
                            ]]);
                        file_put_contents($results.'/'.$index, json_encode([
                            'status' => $response->status(), 'code' => $response->json('error.code'),
                        ]));
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($results.'/'.$index, json_encode(['error_class' => $exception::class]));
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            }
            $responses = array_map(fn ($file) => json_decode(file_get_contents($file), true), glob($results.'/*'));
            $this->assertEqualsCanonicalizing([200, 409], array_column($responses, 'status'));
            $this->assertSame(1, count(array_filter($responses,
                fn ($response) => ($response['code'] ?? null) === 'NOTE_VERSION_CONFLICT')));
            $final = DocumentNote::with('citations')->findOrFail($note->id);
            $this->assertSame(2, $final->version);
            $this->assertCount(1, $final->citations);
            $this->assertSame($final->body, $final->citations[0]->finding_label);
            $this->assertSame($final->body.' citation', $final->citations[0]->quote_snapshot);
        } finally {
            foreach (glob($results.'/*') as $file) {
                unlink($file);
            }
            rmdir($results);
        }
    }
}
