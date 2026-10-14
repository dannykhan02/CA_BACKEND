<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\EntitlementService;
use App\Support\NoteTelemetryRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Sentry\Event;
use Tests\TestCase;

class DocumentRichNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.notes_enabled' => true, 'document_intelligence.notes_rich_text_enabled' => true]);
    }

    private function actor(?Workspace $workspace = null, string $role = 'Viewer'): User
    {
        $workspace ??= Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Rich notes test']);
        $user = User::factory()->create(['role' => $role, 'current_workspace_id' => $workspace->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => $workspace->type === WorkspaceType::Personal ? null : $role, 'joined_at' => now()]);

        return $user;
    }

    private function document(User $user, string $classification = 'Internal'): Document
    {
        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Rich.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => $classification, 'year' => 2026]);
    }

    private function url(Document $document, ?string $id = null): string
    {
        return '/api/documents/'.$document->id.'/notes'.($id === null ? '' : '/'.$id);
    }

    private function rich(string $text = 'Private rich text'): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function create(User $user, Document $document, ?array $content = null): string
    {
        Sanctum::actingAs($user);

        return $this->postJson($this->url($document), ['content' => $content ?? $this->rich(),
            'contentSchemaVersion' => 1, 'citations' => []])->assertCreated()->json('data.id');
    }

    public function test_legacy_and_rich_create_update_and_omission_contract(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $legacy = $this->postJson($this->url($document), ['body' => 'Legacy', 'citations' => []])
            ->assertCreated()->assertJsonPath('data.content', null)
            ->assertJsonPath('data.contentFormat', 'plain_text');
        $legacyId = $legacy->json('data.id');
        $this->patchJson($this->url($document, $legacyId), ['version' => 1, 'body' => 'Legacy edit'])
            ->assertOk()->assertJsonPath('data.body', 'Legacy edit');

        $content = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [
                ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and italic', 'marks' => [['type' => 'italic']]],
            ]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'List item']]],
            ]]]],
        ]];
        $created = $this->postJson($this->url($document), ['content' => $content,
            'contentSchemaVersion' => 1, 'body' => 'UNTRUSTED CLIENT BODY'])->assertCreated()
            ->assertJsonPath('data.contentFormat', 'tiptap_v1')
            ->assertJsonPath('data.contentSchemaVersion', 1)
            ->assertJsonPath('data.body', "Title\nBold and italic\nList item");
        $id = $created->json('data.id');
        $this->assertEquals($content, $created->json('data.content'));
        $preserved = $this->patchJson($this->url($document, $id), ['version' => 1])->assertOk()
            ->assertJsonPath('data.version', 2);
        $this->assertEquals($content, $preserved->json('data.content'));
        $this->patchJson($this->url($document, $id), ['version' => 2, 'body' => 'Old client'])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_FORMAT_CHANGE_REQUIRED');
        $this->patchJson($this->url($document, $id), ['version' => 2, 'content' => null, 'body' => 'Plain again'])
            ->assertOk()->assertJsonPath('data.content', null)->assertJsonPath('data.body', 'Plain again')
            ->assertJsonPath('data.contentFormat', 'plain_text');
        $this->getJson($this->url($document))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_supported_blocks_marks_and_safe_links(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $content = ['type' => 'doc', 'content' => [
            ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Done']]],
                ['type' => 'orderedList', 'attrs' => ['start' => 2], 'content' => [['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nested']]],
                ]]]],
            ]]]],
            ['type' => 'callout', 'attrs' => ['kind' => 'warning'], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Read', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example.org/source']],
                    ['type' => 'highlight', 'attrs' => ['color' => 'yellow']],
                    ['type' => 'underline'], ['type' => 'strike'],
                ]]]],
            ]],
            ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote']]]]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'print(1)']]],
            ['type' => 'horizontalRule'],
        ]];
        $id = $this->create($user, $document, $content);
        $this->assertEquals($content, $this->getJson($this->url($document))->assertOk()->json('data.0.content'));
        $this->assertEquals($content, DocumentNote::findOrFail($id)->content);
    }

    public function test_invalid_structures_are_rejected_without_partial_rows(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $invalid = [
            ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.org/x']]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'script']]]]]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)'], 'content' => []]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>', 'html' => true]]]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 20001)]]]]],
            ['type' => 'doc', 'content' => array_fill(0, 2001, ['type' => 'paragraph', 'content' => []])],
        ];
        foreach ($invalid as $content) {
            $this->postJson($this->url($document), ['content' => $content, 'contentSchemaVersion' => 1])
                ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        }
        $this->postJson($this->url($document), ['content' => $this->rich(), 'contentSchemaVersion' => 2])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        $this->postJson($this->url($document), ['content' => 'bad', 'contentSchemaVersion' => 1])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        $this->postJson($this->url($document), ['content' => $this->rich(str_repeat('x', 130000)), 'contentSchemaVersion' => 1])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        $raw = json_encode(['content' => $this->rich('Small'), 'contentSchemaVersion' => 1,
            'padding' => str_repeat('a', 262144)]);
        $this->call('POST', $this->url($document), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        $this->assertDatabaseCount('document_notes', 0);
    }

    public function test_nested_list_limit_and_rejected_html_like_attrs(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Deep']]];
        for ($i = 0; $i < 7; $i++) {
            $node = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$node]]]];
        }
        $this->postJson($this->url($document), ['content' => ['type' => 'doc', 'content' => [$node]],
            'contentSchemaVersion' => 1])->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
        $content = $this->rich();
        $content['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => [
            'href' => 'https://example.org', 'onclick' => 'alert(1)',
        ]]];
        $this->postJson($this->url($document), ['content' => $content, 'contentSchemaVersion' => 1])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_INVALID_CONTENT');
    }

    public function test_references_are_remapped_to_owned_snapshot_rows_and_replacement_is_atomic(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $draft = 'draft:'.Str::uuid();
        $content = $this->rich();
        $content['content'][0]['content'][] = ['type' => 'citationRef', 'attrs' => ['citationId' => $draft]];
        $created = $this->postJson($this->url($document), ['content' => $content, 'contentSchemaVersion' => 1,
            'citations' => [['client_ref' => $draft, 'finding_label' => 'Snapshot', 'quote_snapshot' => 'Frozen']]])
            ->assertCreated()->assertJsonPath('data.citations.0.verificationStatus', 'unverified_snapshot');
        $id = $created->json('data.id');
        $citationId = $created->json('data.citations.0.id');
        $this->assertSame($citationId, $created->json('data.content.content.0.content.1.attrs.citationId'));
        $this->patchJson($this->url($document, $id), ['version' => 1, 'citations' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_CITATION_REFERENCE_INVALID');
        $this->assertDatabaseHas('document_note_citations', ['id' => $citationId, 'quote_snapshot' => 'Frozen']);
        $this->assertSame(1, DocumentNote::findOrFail($id)->version);

        $next = 'draft:'.Str::uuid();
        $replacement = $this->rich('Replaced');
        $replacement['content'][0]['content'][] = ['type' => 'citationRef', 'attrs' => ['citationId' => $next]];
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $replacement,
            'contentSchemaVersion' => 1, 'citations' => [['client_ref' => $next,
                'finding_label' => 'New snapshot', 'quote_snapshot' => 'Frozen new']]])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseMissing('document_note_citations', ['id' => $citationId]);
        $this->assertDatabaseCount('document_note_citations', 1);
    }

    public function test_foreign_and_dangling_citation_references_are_rejected(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        Sanctum::actingAs($user);
        $foreign = $this->postJson($this->url($document), ['body' => 'Other', 'citations' => [[
            'finding_label' => 'Other', 'quote_snapshot' => 'Other quote',
        ]]])->assertCreated()->json('data.citations.0.id');
        $otherDocumentForSameUser = $this->document($user);
        $foreignDocumentCitation = $this->postJson($this->url($otherDocumentForSameUser), ['body' => 'Other document',
            'citations' => [['finding_label' => 'Other document', 'quote_snapshot' => 'Other quote']]])
            ->assertCreated()->json('data.citations.0.id');
        $otherUser = $this->actor();
        $otherDocument = $this->document($otherUser);
        Sanctum::actingAs($otherUser);
        $foreignWorkspaceCitation = $this->postJson($this->url($otherDocument), ['body' => 'Other workspace',
            'citations' => [['finding_label' => 'Other workspace', 'quote_snapshot' => 'Other quote']]])
            ->assertCreated()->json('data.citations.0.id');
        Sanctum::actingAs($user);
        foreach ([$foreign, $foreignDocumentCitation, $foreignWorkspaceCitation, (string) Str::uuid()] as $id) {
            $content = $this->rich();
            $content['content'][0]['content'][] = ['type' => 'citationRef', 'attrs' => ['citationId' => $id]];
            $this->postJson($this->url($document), ['content' => $content, 'contentSchemaVersion' => 1])
                ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_CITATION_REFERENCE_INVALID');
        }
    }

    public function test_existing_snapshot_reference_does_not_follow_reprocessing(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $first = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published', 'pipeline_key' => 'rich-old']);
        $document->forceFill(['published_intelligence_revision_id' => $first->id])->save();
        Sanctum::actingAs($user);
        $draft = 'draft:'.Str::uuid();
        $content = $this->rich();
        $content['content'][0]['content'][] = ['type' => 'citationRef', 'attrs' => ['citationId' => $draft]];
        $created = $this->postJson($this->url($document), ['content' => $content, 'contentSchemaVersion' => 1,
            'citations' => [['client_ref' => $draft, 'intelligence_revision_id' => $first->id,
                'finding_label' => 'Old finding', 'quote_snapshot' => 'Old quote']]])->assertCreated();
        $id = $created->json('data.id');
        $citationId = $created->json('data.citations.0.id');
        $second = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'published', 'pipeline_key' => 'rich-new']);
        $document->forceFill(['published_intelligence_revision_id' => $second->id])->save();
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $created->json('data.content'),
            'contentSchemaVersion' => 1])->assertOk()->assertJsonPath('data.citations.0.intelligenceRevisionId', $first->id)
            ->assertJsonPath('data.citations.0.quoteSnapshot', 'Old quote')
            ->assertJsonPath('data.content.content.0.content.1.attrs.citationId', $citationId);
        $this->patchJson($this->url($document, $id), ['version' => 2, 'content' => $this->rich('New content'),
            'contentSchemaVersion' => 1, 'citations' => [['intelligence_revision_id' => $first->id,
                'finding_label' => 'Stale', 'quote_snapshot' => 'Stale']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'CITATION_REVISION_STALE');
        $this->assertDatabaseHas('document_note_citations', ['id' => $citationId, 'quote_snapshot' => 'Old quote']);
    }

    public function test_personal_uploader_and_restricted_visibility_apply_to_rich_notes(): void
    {
        $workspace = Workspace::create(['type' => WorkspaceType::Personal]);
        $owner = $this->actor($workspace);
        $document = $this->document($owner, 'Restricted');
        $id = $this->create($owner, $document);
        $other = $this->actor($workspace);
        Sanctum::actingAs($other);
        $this->getJson($this->url($document))->assertNotFound();
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich('No'),
            'contentSchemaVersion' => 1])->assertNotFound();
        $organizationViewer = $this->actor();
        $restricted = $this->document($organizationViewer, 'Restricted');
        Sanctum::actingAs($organizationViewer);
        $this->postJson($this->url($restricted), ['content' => $this->rich('No'),
            'contentSchemaVersion' => 1])->assertNotFound();
    }

    public function test_authorization_and_flag_still_bound_rich_content(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->create($user, $document);
        $administrator = $this->actor($user->currentWorkspace, 'Administrator');
        Sanctum::actingAs($administrator);
        $this->getJson($this->url($document))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich('Attack'),
            'contentSchemaVersion' => 1])->assertNotFound();
        $outsider = $this->actor();
        Sanctum::actingAs($outsider);
        $this->getJson($this->url($document))->assertNotFound();
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich('Attack'),
            'contentSchemaVersion' => 1])->assertNotFound();
        Sanctum::actingAs($user);
        $document->forceFill(['classification' => 'Restricted'])->save();
        $this->getJson($this->url($document))->assertNotFound();
        $document->forceFill(['classification' => 'Internal'])->save();
        WorkspaceMember::where('workspace_id', $document->workspace_id)->where('user_id', $user->id)->delete();
        $this->getJson($this->url($document))->assertNotFound();
        WorkspaceMember::create(['workspace_id' => $document->workspace_id, 'user_id' => $user->id,
            'role' => 'Viewer', 'joined_at' => now()]);
        $document->delete();
        $this->getJson($this->url($document))->assertNotFound();
        config(['document_intelligence.notes_enabled' => false]);
        $this->getJson($this->url($document))->assertNotFound();
        $this->postJson($this->url($document), ['content' => $this->rich(), 'contentSchemaVersion' => 1])->assertNotFound();
        $this->patchJson($this->url($document, $id), ['version' => 1])->assertNotFound();
        $this->deleteJson($this->url($document, $id))->assertNotFound();
    }

    public function test_stale_update_does_not_change_content_or_citations(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->create($user, $document);
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich('Winner'),
            'contentSchemaVersion' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich('Loser'),
            'contentSchemaVersion' => 1, 'citations' => [['finding_label' => 'No', 'quote_snapshot' => 'No']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'NOTE_VERSION_CONFLICT')
            ->assertJsonPath('current.body', 'Winner');
        $this->assertSame('Winner', DocumentNote::findOrFail($id)->body);
        $this->assertDatabaseCount('document_note_citations', 0);
    }

    public function test_rich_content_stays_out_of_audit_and_telemetry_and_rich_flag_is_separate(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $secret = 'RICH_SECRET_'.Str::random(20);
        $this->create($user, $document, $this->rich($secret));
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($secret,
            $this->getJson('/api/documents/'.$document->id.'/report-snapshot')->assertOk()->getContent());
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
            $this->getJson('/api/documents/search?q=rich')->assertOk()->getContent());
        $this->assertStringNotContainsString($secret,
            $this->postJson('/api/documents/query', ['question' => 'What does it say?'])->assertOk()->getContent());
        $event = Event::createEvent()->setRequest(['url' => 'https://example.test/api/documents/a/notes/b?content='.$secret,
            'query_string' => 'content='.$secret, 'data' => ['content' => $secret]]);
        $request = NoteTelemetryRedactor::redact($event)->getRequest();
        $this->assertStringNotContainsString($secret, json_encode($request));
        config(['document_intelligence.notes_rich_text_enabled' => false]);
        $this->getJson('/api/capabilities')->assertOk()->assertJsonPath('data.documentNotes.richTextEnabled', false);
        $this->postJson($this->url($document), ['content' => $this->rich(), 'contentSchemaVersion' => 1])
            ->assertNotFound();
        $this->postJson($this->url($document), ['body' => 'Old client'])->assertCreated();
    }

    public function test_database_exception_with_rich_payload_is_sanitized(): void
    {
        $user = $this->actor();
        $document = $this->document($user);
        $id = $this->create($user, $document);
        $secret = 'RICH_DB_SECRET_'.Str::random(20);
        DB::statement("ALTER TABLE document_notes ADD CONSTRAINT rich_notes_test_reject CHECK (body <> '".$secret."')");
        Log::spy();
        $this->patchJson($this->url($document, $id), ['version' => 1, 'content' => $this->rich($secret),
            'contentSchemaVersion' => 1])->assertStatus(500)->assertJsonPath('error.code', 'NOTE_OPERATION_FAILED')
            ->assertDontSee($secret);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'document note operation failed' && ! str_contains(json_encode($context), $secret));
        $this->assertSame(1, DocumentNote::findOrFail($id)->version);
    }
}
