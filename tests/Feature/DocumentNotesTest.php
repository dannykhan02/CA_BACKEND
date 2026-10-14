<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\EntitlementService;
use App\Support\NoteTelemetryRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Sentry\Event;
use Tests\TestCase;

class DocumentNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.notes_enabled' => true]);
    }

    private function actor(WorkspaceType $type = WorkspaceType::Organization, string $role = 'Viewer', ?Workspace $workspace = null): User
    {
        $workspace ??= Workspace::create(['type' => $type, 'name' => 'Notes test']);
        $user = User::factory()->create(['role' => $role, 'current_workspace_id' => $workspace->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => $type === WorkspaceType::Personal ? null : $role, 'joined_at' => now()]);

        return $user;
    }

    private function document(User $user, string $classification = 'Internal'): Document
    {
        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Private.pdf', 'type' => 'PDF',
            'size_kb' => 1, 'status' => 'Ready', 'classification' => $classification, 'year' => 2026]);
    }

    private function url(Document $document, ?string $noteId = null): string
    {
        return '/api/documents/'.$document->id.'/notes'.($noteId === null ? '' : '/'.$noteId);
    }

    private function createNote(User $user, Document $document, string $body = 'Private annotation'): string
    {
        Sanctum::actingAs($user);

        return $this->postJson($this->url($document), ['body' => $body, 'citations' => []])
            ->assertCreated()->json('data.id');
    }

    public function test_author_can_create_list_update_and_soft_delete_with_versions_and_citation_replacement(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $created = $this->postJson($this->url($document), ['body' => 'Initial private text',
            'citations' => [['finding_label' => 'Finding', 'quote_snapshot' => 'Original quote', 'page' => 3]]])
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.citations.0.verificationStatus', 'unverified_snapshot');
        $id = $created->json('data.id');
        $firstCitation = $created->json('data.citations.0.id');

        $this->getJson($this->url($document))->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($this->url($document, $id), ['body' => 'Revised private text', 'version' => 1,
            'citations' => [['finding_label' => 'New finding', 'quote_snapshot' => 'New snapshot']]])
            ->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.citations.0.findingLabel', 'New finding');
        $this->assertDatabaseMissing('document_note_citations', ['id' => $firstCitation]);
        $this->deleteJson($this->url($document, $id), [], ['If-Match' => '"2"'])->assertOk();
        $this->getJson($this->url($document))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('document_notes', ['id' => $id]);
        $this->patchJson($this->url($document, $id), ['body' => 'After deletion', 'citations' => [], 'version' => 2])->assertNotFound();

        $events = AuditLog::where('auditable_id', $id)->orderBy('created_at')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['document_note.created', 'document_note.updated', 'document_note.deleted'], $events);
        $this->assertStringNotContainsString('private text', json_encode(AuditLog::where('auditable_id', $id)->get()->toArray()));
    }

    public function test_other_author_in_same_workspace_and_user_in_other_workspace_get_identical_404s(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $noteId = $this->createNote($author, $document);
        $sameWorkspace = $this->actor(WorkspaceType::Organization, 'Administrator', $author->currentWorkspace);
        $outsider = $this->actor();

        foreach ([$sameWorkspace, $outsider] as $intruder) {
            Sanctum::actingAs($intruder);
            foreach (['patch', 'delete'] as $method) {
                $response = $method === 'patch'
                    ? $this->patchJson($this->url($document, $noteId), ['body' => 'Attack', 'citations' => [], 'version' => 1])
                    : $this->deleteJson($this->url($document, $noteId), [], ['If-Match' => '"1"']);
                $response->assertNotFound()->assertExactJson([
                    'success' => false, 'message' => 'Resource not found.', 'errors' => [],
                ]);
            }
            $response = $this->getJson($this->url($document));
            if ($intruder->id === $sameWorkspace->id) {
                $response->assertOk()->assertJsonCount(0, 'data');
            } else {
                $response->assertNotFound();
            }
        }
    }

    public function test_revoked_membership_and_raised_classification_hide_existing_notes(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->createNote($user, $document);
        $document->forceFill(['classification' => 'Restricted'])->save();
        $this->getJson($this->url($document))->assertNotFound();
        $this->patchJson($this->url($document, $id), ['body' => 'Denied', 'citations' => [], 'version' => 1])->assertNotFound();
        $document->forceFill(['classification' => 'Internal'])->save();
        WorkspaceMember::where('workspace_id', $document->workspace_id)->where('user_id', $user->id)->delete();
        $this->getJson($this->url($document))->assertNotFound();
        $this->deleteJson($this->url($document, $id), [], ['If-Match' => '"1"'])->assertNotFound();
    }

    public function test_personal_author_cannot_use_old_workspace_after_switching_current_workspace(): void
    {
        $user = $this->actor(WorkspaceType::Personal);
        $document = $this->document($user);
        $id = $this->createNote($user, $document);
        $other = Workspace::create(['type' => WorkspaceType::Personal]);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $user->id,
            'role' => null, 'joined_at' => now()]);
        $user->forceFill(['current_workspace_id' => $other->id])->save();
        Sanctum::actingAs($user->fresh());
        $this->getJson($this->url($document))->assertNotFound();
        $this->patchJson($this->url($document, $id), ['body' => 'No', 'citations' => [], 'version' => 1])->assertNotFound();
    }

    public function test_viewer_can_write_internal_but_not_restricted_and_personal_requires_uploader(): void
    {
        $viewer = $this->actor();
        $internal = $this->document($viewer);
        $restricted = $this->document($viewer, 'Restricted');
        $this->createNote($viewer, $internal);
        $this->postJson($this->url($restricted), ['body' => 'No', 'citations' => []])->assertNotFound();

        $owner = $this->actor(WorkspaceType::Personal);
        $personal = $this->document($owner, 'Restricted');
        $this->createNote($owner, $personal);
        $other = $this->actor(WorkspaceType::Personal, 'Viewer', $owner->currentWorkspace);
        Sanctum::actingAs($other);
        $this->getJson($this->url($personal))->assertNotFound();
        $this->postJson($this->url($personal), ['body' => 'No', 'citations' => []])->assertNotFound();
    }

    public function test_stale_update_and_delete_return_conflict_only_to_author(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->createNote($user, $document);
        $this->patchJson($this->url($document, $id), ['body' => 'New', 'citations' => [], 'version' => 1])->assertOk();
        $this->patchJson($this->url($document, $id), ['body' => 'Stale', 'citations' => [], 'version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'NOTE_VERSION_CONFLICT')
            ->assertJsonPath('current.version', 2)->assertJsonPath('current.body', 'New');
        $this->deleteJson($this->url($document, $id))->assertStatus(428)->assertJsonPath('error.code', 'NOTE_VERSION_REQUIRED');
        $this->deleteJson($this->url($document, $id), [], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('current.version', 2);
        $this->assertSame('New', DocumentNote::findOrFail($id)->body);
    }

    public function test_citation_revision_and_source_must_belong_to_current_document(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $other = $this->document($user);
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published', 'pipeline_key' => 'notes-one']);
        $foreign = DocumentIntelligenceRevision::create(['workspace_id' => $other->workspace_id,
            'document_id' => $other->id, 'generation' => 1, 'state' => 'published', 'pipeline_key' => 'notes-two']);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id])->save();
        DocumentEvidence::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => 'notes-one', 'identity' => hash('sha256', 'source'), 'kind' => 'metric',
            'source_id' => 'owned-source', 'data' => [], 'sources' => []]);
        Sanctum::actingAs($user);
        $base = ['body' => 'Cited note', 'citations' => [['finding_label' => 'Metric', 'quote_snapshot' => 'User-supplied quote',
            'intelligence_revision_id' => $revision->id, 'source_id' => 'owned-source']]];
        $this->postJson($this->url($document), $base)->assertCreated()
            ->assertJsonPath('data.citations.0.verificationStatus', 'unverified_snapshot');
        $foreignInput = $base;
        $foreignInput['citations'][0]['intelligence_revision_id'] = $foreign->id;
        $this->postJson($this->url($document), $foreignInput)->assertStatus(422)
            ->assertJsonPath('error.code', 'CITATION_REVISION_STALE');
        $foreignInput['citations'][0]['intelligence_revision_id'] = $revision->id;
        $foreignInput['citations'][0]['source_id'] = 'foreign-source';
        $this->postJson($this->url($document), $foreignInput)->assertStatus(422)
            ->assertJsonPath('error.code', 'CITATION_SOURCE_NOT_OWNED');
        DocumentEvidence::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => 'notes-one', 'identity' => hash('sha256', 'duplicate'), 'kind' => 'metric',
            'source_id' => 'owned-source', 'data' => [], 'sources' => []]);
        $this->postJson($this->url($document), $base)->assertStatus(422)
            ->assertJsonPath('error.code', 'CITATION_SOURCE_NOT_OWNED');
        $newRevision = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'published', 'pipeline_key' => 'notes-three']);
        $document->forceFill(['published_intelligence_revision_id' => $newRevision->id])->save();
        $this->postJson($this->url($document), $base)->assertStatus(422)
            ->assertJsonPath('error.code', 'CITATION_REVISION_STALE');
    }

    public function test_database_guards_reject_mismatched_note_and_citation_ownership(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $other = $this->actor();
        $otherDocument = $this->document($other);
        $this->assertDatabaseRejected(function () use ($author, $document, $otherDocument): void {
            DB::table('document_notes')->insert(['id' => (string) Str::uuid(),
                'document_id' => $document->id, 'workspace_id' => $otherDocument->workspace_id,
                'user_id' => $author->id, 'body' => 'Wrong owner', 'version' => 1,
                'created_at' => now(), 'updated_at' => now()]);
        });
        $noteId = $this->createNote($author, $document);
        $this->assertDatabaseRejected(function () use ($noteId, $otherDocument): void {
            DB::table('document_note_citations')->insert(['id' => (string) Str::uuid(),
                'note_id' => $noteId, 'document_id' => $otherDocument->id,
                'finding_label' => 'Wrong document', 'quote_snapshot' => 'Wrong source',
                'created_at' => now()]);
        });
    }

    public function test_limits_flag_off_deleted_document_and_hard_delete(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->createNote($user, $document);
        $this->postJson($this->url($document), ['body' => str_repeat('x', 20001), 'citations' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_TOO_LONG');
        $this->postJson($this->url($document), ['body' => 'Bound', 'citations' => array_fill(0, 51,
            ['finding_label' => 'A', 'quote_snapshot' => 'B'])])->assertStatus(422)
            ->assertJsonPath('error.code', 'TOO_MANY_CITATIONS');
        $this->postJson($this->url($document), ['body' => 'Invalid citation', 'citations' => [[
            'finding_label' => 'A', 'quote_snapshot' => str_repeat('q', 1001),
        ]]])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CITATION');
        $this->postJson($this->url($document), ['body' => 'Unscoped source', 'citations' => [[
            'finding_label' => 'A', 'quote_snapshot' => 'B', 'source_id' => 'unscoped',
        ]]])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CITATION');
        config(['document_intelligence.notes_enabled' => false]);
        foreach (['get', 'post', 'patch', 'delete'] as $method) {
            $response = match ($method) {
                'get' => $this->getJson($this->url($document)),
                'post' => $this->postJson($this->url($document), ['body' => 'Off']),
                'patch' => $this->patchJson($this->url($document, $id), ['body' => 'Off', 'citations' => [], 'version' => 1]),
                'delete' => $this->deleteJson($this->url($document, $id)),
            };
            $response->assertNotFound();
        }
        $this->getJson('/api/capabilities')->assertOk()->assertJsonPath('data.documentNotes.enabled', false);
        config(['document_intelligence.notes_enabled' => true]);
        $document->delete();
        $this->getJson($this->url($document))->assertNotFound();
        $document->forceDelete();
        $this->assertDatabaseMissing('document_notes', ['id' => $id]);
    }

    public function test_read_and_write_throttles_apply_and_off_flag_stays_hidden(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson($this->url($document))->assertOk();
        }
        $this->getJson($this->url($document))->assertStatus(429);
        $writer = $this->actor();
        $writeDocument = $this->document($writer);
        Sanctum::actingAs($writer);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->url($writeDocument), ['body' => 'Note '.$i, 'citations' => []])->assertCreated();
        }
        $this->postJson($this->url($writeDocument), ['body' => 'Over limit', 'citations' => []])->assertStatus(429);
        config(['document_intelligence.notes_enabled' => false]);
        $this->postJson($this->url($writeDocument), ['body' => 'Still hidden', 'citations' => []])->assertNotFound();
    }

    public function test_note_body_is_absent_from_audit_search_context_and_report_and_database_errors_are_sanitized(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $secret = 'SECRET_NOTE_'.Str::random(24);
        $id = $this->createNote($user, $document, $secret);
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, $this->getJson('/api/documents/'.$document->id.'/report-snapshot')->assertOk()->getContent());
        $this->assertDatabaseMissing('document_embeddings', ['chunk_text' => $secret]);
        $this->assertSame(0, DB::table('document_evidence')->where('data', 'like', '%'.$secret.'%')->count());
        $vector = '['.implode(',', array_fill(0, 1024, 0.001)).']';
        DB::statement('INSERT INTO document_embeddings
            (id, workspace_id, document_id, intelligence_revision_id, chunk_index, chunk_text, provider, model, embedding, created_at, updated_at)
            VALUES (?, ?, ?, NULL, 0, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $document->workspace_id, $document->id,
                'Only benign document text.', 'voyage', 'test', $vector]);
        $this->mock(EntitlementService::class, fn ($mock) => $mock->shouldReceive('assertAiAccess')->andReturnNull());
        $this->mock(VoyageEmbeddingClient::class, fn ($mock) => $mock->shouldReceive('embed')
            ->andReturn(['embeddings' => [array_fill(0, 1024, 0.001)], 'model' => 'test']));
        $this->mock(AnthropicClient::class, function ($mock) use ($secret, $document): void {
            $mock->shouldReceive('forOperation')->once()->andReturnSelf();
            $mock->shouldReceive('answerDocumentQuestion')->once()
                ->withArgs(fn ($question, $context) => ! str_contains($context, $secret))
                ->andReturn(['answer' => 'Only benign data.', 'confidence' => 'high',
                    'cited_document_ids' => [$document->id]]);
        });
        $this->assertStringNotContainsString($secret,
            $this->getJson('/api/documents/search?q=private')->assertOk()->getContent());
        $this->assertStringNotContainsString($secret,
            $this->postJson('/api/documents/query', ['question' => 'What does the document say?'])->assertOk()->getContent());

        Log::spy();
        $rejected = $secret.'_changed';
        // A database exception can contain SQL and bindings. Notes middleware must never report it raw.
        DB::statement('ALTER TABLE document_notes ADD CONSTRAINT notes_test_reject CHECK (body <> '.$this->quote($rejected).')');
        $this->patchJson($this->url($document, $id), ['body' => $rejected, 'citations' => [], 'version' => 1])
            ->assertStatus(500)->assertJsonPath('error.code', 'NOTE_OPERATION_FAILED')
            ->assertDontSee($rejected);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'document note operation failed' && ! str_contains(json_encode($context), $rejected));
    }

    public function test_sentry_redactor_drops_note_request_content_and_query_string(): void
    {
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
        $event = Event::createEvent()->setRequest([
            'url' => 'https://example.test/api/documents/a/notes/b?body=secret',
            'query_string' => 'body=secret', 'data' => ['body' => 'secret'],
        ]);
        $redacted = NoteTelemetryRedactor::redact($event)->getRequest();
        $this->assertSame('https://example.test/api/documents/a/notes/b', $redacted['url']);
        $this->assertArrayNotHasKey('query_string', $redacted);
        $this->assertArrayNotHasKey('data', $redacted);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function assertDatabaseRejected(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database accepted an ownership mismatch.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
