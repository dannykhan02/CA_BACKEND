<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Uses committed fixtures and separate forked PostgreSQL connections to exercise the row lock. */
class DocumentRichNotesConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE users, workspaces RESTART IDENTITY CASCADE');
        }
        parent::tearDown();
    }

    public function test_simultaneous_rich_updates_and_citation_replacements_have_one_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for the simultaneous HTTP test.');
        }
        config(['document_intelligence.notes_enabled' => true, 'document_intelligence.notes_rich_text_enabled' => true]);
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Rich concurrency']);
        $user = User::factory()->create(['role' => 'Viewer', 'current_workspace_id' => $workspace->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'Viewer', 'joined_at' => now()]);
        $document = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'name' => 'Race.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => 'Internal', 'year' => 2026]);
        Sanctum::actingAs($user);
        $url = '/api/documents/'.$document->id.'/notes';
        $id = $this->postJson($url, ['body' => 'Before', 'citations' => []])->assertCreated()->json('data.id');

        DB::disconnect();
        $start = microtime(true) + 0.4;
        $files = [];
        $children = [];
        foreach (['First', 'Second'] as $text) {
            $file = tempnam(sys_get_temp_dir(), 'rich-note-race-');
            $this->assertNotFalse($file);
            $files[] = $file;
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('pgsql');
                Sanctum::actingAs($user);
                while (microtime(true) < $start) {
                    usleep(1000);
                }
                try {
                    $response = $this->patchJson($url.'/'.$id, ['version' => 1,
                        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => $text]]]]],
                        'contentSchemaVersion' => 1,
                        'citations' => [['finding_label' => $text, 'quote_snapshot' => $text]]]);
                    file_put_contents($file, (string) $response->status());
                } catch (\Throwable) {
                    file_put_contents($file, 'exception');
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
        }
        $statuses = array_map(fn ($file) => (int) file_get_contents($file), $files);
        foreach ($files as $file) {
            unlink($file);
        }
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $note = DocumentNote::findOrFail($id);
        $this->assertSame(2, $note->version);
        $this->assertContains($note->body, ['First', 'Second']);
        $this->assertSame($note->body, $note->citations()->firstOrFail()->finding_label);
        $this->assertSame(1, $note->citations()->count());
    }
}
