<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentNoteAppendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.notes_enabled' => true, 'document_intelligence.notes_rich_text_enabled' => true]);
    }

    private function actor(?Workspace $workspace = null): User
    {
        $workspace ??= Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Note append test']);
        $user = User::factory()->create(['role' => 'Viewer', 'current_workspace_id' => $workspace->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'Viewer', 'joined_at' => now()]);

        return $user;
    }

    private function document(User $user): Document
    {
        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => 'Internal', 'year' => 2026]);
    }

    private function revision(Document $document, int $generation, string $source): DocumentIntelligenceRevision
    {
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => $generation, 'state' => 'published',
            'pipeline_key' => 'note-append-'.$document->id.'-'.$generation]);
        DocumentEvidence::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $revision->pipeline_key, 'identity' => hash('sha256', $source),
            'kind' => 'metric', 'source_id' => $source, 'data' => [], 'sources' => []]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function content(string $text, array $references = []): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => array_merge(
            [['type' => 'text', 'text' => $text]],
            array_map(fn (string $id) => ['type' => 'citationRef', 'attrs' => ['citationId' => $id]], $references),
        )]]];
    }

    private function citation(DocumentIntelligenceRevision $revision, string $source, string $draft): array
    {
        return ['client_ref' => $draft, 'intelligence_revision_id' => $revision->id,
            'source_id' => $source, 'finding_label' => $source, 'quote_snapshot' => 'Quote from '.$source];
    }

    private function url(Document $document, ?string $note = null): string
    {
        return '/api/documents/'.$document->id.'/notes'.($note === null ? '' : '/'.$note);
    }

    public function test_detach_preserves_historical_sibling_references_and_requires_note_ownership_and_version(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $revision = $this->revision($document, 1, 'old-source');
        Sanctum::actingAs($author);
        $one = 'draft:'.Str::uuid();
        $two = 'draft:'.Str::uuid();
        $created = $this->postJson($this->url($document), ['content' => $this->content('My writing', [$one, $two]),
            'contentSchemaVersion' => 1, 'citations' => [$this->citation($revision, 'old-source', $one),
                $this->citation($revision, 'old-source', $two)]])->assertCreated();
        $id = $created->json('data.id');
        $first = $created->json('data.citations.0.id');
        $second = $created->json('data.citations.1.id');
        $this->revision($document, 2, 'new-source');
        $payload = ['version' => 1, 'removeCitationIds' => [$first],
            'content' => $this->content('My writing', [$second]), 'contentSchemaVersion' => 1];
        $this->patchJson($this->url($document, $id), [...$payload, 'removeCitationIds' => [(string) Str::uuid()]])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CITATION');
        $this->patchJson($this->url($document, $id), [...$payload, 'content' => $this->content('My writing', [$first, $second])])
            ->assertStatus(422);
        $this->assertDatabaseHas('document_note_citations', ['id' => $first]);
        $this->patchJson($this->url($document, $id), $payload)->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonCount(1, 'data.citations')
            ->assertJsonPath('data.citations.0.id', $second);
        $this->assertDatabaseMissing('document_note_citations', ['id' => $first]);
        $this->patchJson($this->url($document, $id), $payload)->assertStatus(409);
        Sanctum::actingAs($this->actor($author->currentWorkspace));
        $this->patchJson($this->url($document, $id), [...$payload, 'version' => 2])->assertNotFound();
    }

    public function test_existing_replacement_contract_rejects_historical_citation_during_append_attempt(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $old = $this->revision($document, 1, 'old-source');
        Sanctum::actingAs($author);
        $oldDraft = 'draft:'.Str::uuid();
        $created = $this->postJson($this->url($document), ['content' => $this->content('Meeting', [$oldDraft]),
            'contentSchemaVersion' => 1, 'citations' => [$this->citation($old, 'old-source', $oldDraft)]])->assertCreated();
        $id = $created->json('data.id');
        $oldId = $created->json('data.citations.0.id');
        $current = $this->revision($document, 2, 'current-source');
        $newDraft = 'draft:'.Str::uuid();

        $this->patchJson($this->url($document, $id), ['version' => 1,
            'content' => $this->content('Meeting', [$oldId, $newDraft]), 'contentSchemaVersion' => 1,
            'citations' => [$this->citation($old, 'old-source', $oldDraft),
                $this->citation($current, 'current-source', $newDraft)]])
            ->assertStatus(422)->assertJsonPath('error.code', 'CITATION_REVISION_STALE');
        $this->assertDatabaseHas('document_note_citations', ['id' => $oldId, 'quote_snapshot' => 'Quote from old-source']);
    }

    public function test_append_preserves_historical_snapshot_and_both_inline_references(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $old = $this->revision($document, 1, 'old-source');
        Sanctum::actingAs($author);
        $oldDraft = 'draft:'.Str::uuid();
        $created = $this->postJson($this->url($document), ['content' => $this->content('Meeting', [$oldDraft]),
            'contentSchemaVersion' => 1, 'citations' => [$this->citation($old, 'old-source', $oldDraft)]])->assertCreated();
        $id = $created->json('data.id');
        $oldId = $created->json('data.citations.0.id');
        $current = $this->revision($document, 2, 'current-source');
        $secondSource = 'current-source-two';
        DocumentEvidence::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $current->pipeline_key, 'identity' => hash('sha256', $secondSource),
            'kind' => 'metric', 'source_id' => $secondSource, 'data' => [], 'sources' => []]);
        $firstDraft = 'draft:'.Str::uuid();
        $secondDraft = 'draft:'.Str::uuid();
        $appended = $this->patchJson($this->url($document, $id), ['version' => 1,
            'content' => $this->content('Meeting updated', [$oldId, $firstDraft, $secondDraft]),
            'contentSchemaVersion' => 1, 'appendCitations' => [
                $this->citation($current, 'current-source', $firstDraft),
                $this->citation($current, $secondSource, $secondDraft),
            ]])->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(3, 'data.citations');
        $citations = collect($appended->json('data.citations'))->keyBy('sourceId');
        $this->assertSame($oldId, $citations['old-source']['id']);
        $this->assertSame($old->id, $citations['old-source']['intelligenceRevisionId']);
        $this->assertSame('Quote from old-source', $citations['old-source']['quoteSnapshot']);
        $this->assertSame('unverified_snapshot', $citations['old-source']['verificationStatus']);
        $this->assertSame($current->id, $citations['current-source']['intelligenceRevisionId']);
        $ids = [$oldId, $citations['current-source']['id'], $citations[$secondSource]['id']];
        $this->assertSame($ids, array_map(fn (array $node) => $node['attrs']['citationId'],
            array_slice($appended->json('data.content.content.0.content'), 1)));
        $this->assertSame(3, DocumentNote::findOrFail($id)->citations()->count());
        $reloaded = $this->getJson($this->url($document))->assertOk()->json('data.0');
        $this->assertEquals($appended->json('data.content'), $reloaded['content']);
        $this->assertSame($oldId, collect($reloaded['citations'])->firstWhere('sourceId', 'old-source')['id']);

        $this->patchJson($this->url($document, $id), ['version' => 1,
            'appendCitations' => [$this->citation($current, 'current-source', $firstDraft)]])
            ->assertStatus(409)->assertJsonPath('current.version', 2);
        $this->assertSame(3, DocumentNote::findOrFail($id)->citations()->count());
    }

    public function test_append_validates_new_evidence_scope_and_rolls_back_every_failure(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $current = $this->revision($document, 2, 'owned-source');
        $otherDocument = $this->document($author);
        $foreignRevision = $this->revision($otherDocument, 1, 'foreign-source');
        $outsider = $this->actor();
        $otherWorkspaceRevision = $this->revision($this->document($outsider), 1, 'other-workspace-source');
        Sanctum::actingAs($author);
        $id = $this->postJson($this->url($document), ['content' => $this->content('Private'),
            'contentSchemaVersion' => 1])->assertCreated()->json('data.id');
        $stale = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published',
            'pipeline_key' => 'old-append-'.$document->id]);
        $cases = [
            [$this->citation($foreignRevision, 'foreign-source', 'draft:'.Str::uuid()), 'CITATION_REVISION_STALE'],
            [$this->citation($otherWorkspaceRevision, 'other-workspace-source', 'draft:'.Str::uuid()), 'CITATION_REVISION_STALE'],
            [$this->citation($current, 'foreign-source', 'draft:'.Str::uuid()), 'CITATION_SOURCE_NOT_OWNED'],
            [$this->citation($stale, 'old-source', 'draft:'.Str::uuid()), 'CITATION_REVISION_STALE'],
            [$this->citation($current, 'owned-source', 'draft:not-a-uuid'), 'INVALID_CITATION'],
        ];
        foreach ($cases as [$citation, $error]) {
            $this->patchJson($this->url($document, $id), ['version' => 1,
                'appendCitations' => [$citation]])->assertStatus(422)->assertJsonPath('error.code', $error);
            $this->assertSame(1, DocumentNote::findOrFail($id)->version);
            $this->assertSame(0, DocumentNote::findOrFail($id)->citations()->count());
        }

        $draft = 'draft:'.Str::uuid();
        $this->patchJson($this->url($document, $id), ['version' => 1,
            'content' => $this->content('Changed', [$draft]), 'contentSchemaVersion' => 1,
            'appendCitations' => [$this->citation($current, 'owned-source', 'draft:'.Str::uuid())]])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_CITATION_REFERENCE_INVALID');
        $this->assertSame('Private', DocumentNote::findOrFail($id)->body);
        $this->assertSame(0, DocumentNote::findOrFail($id)->citations()->count());

        $foreignCitationId = $this->postJson($this->url($otherDocument), ['body' => 'Other document',
            'citations' => [['finding_label' => 'Other', 'quote_snapshot' => 'Other quote']]])
            ->assertCreated()->json('data.citations.0.id');
        $this->patchJson($this->url($document, $id), ['version' => 1,
            'content' => $this->content('Changed', [$foreignCitationId, $draft]), 'contentSchemaVersion' => 1,
            'appendCitations' => [$this->citation($current, 'owned-source', $draft)]])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_CITATION_REFERENCE_INVALID');
        $this->assertSame(0, DocumentNote::findOrFail($id)->citations()->count());

        $this->patchJson($this->url($document, $id), ['version' => 1, 'citations' => [],
            'appendCitations' => [$this->citation($current, 'owned-source', $draft)]])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CITATION');

        Sanctum::actingAs($outsider);
        $this->patchJson($this->url($document, $id), ['version' => 1,
            'appendCitations' => [$this->citation($current, 'owned-source', $draft)]])->assertNotFound();
        $sameWorkspaceOtherAuthor = $this->actor($author->currentWorkspace);
        Sanctum::actingAs($sameWorkspaceOtherAuthor);
        $this->patchJson($this->url($document, $id), ['version' => 1,
            'appendCitations' => [$this->citation($current, 'owned-source', $draft)]])->assertNotFound();
        $this->assertSame(0, DocumentNote::findOrFail($id)->citations()->count());
    }

    public function test_plain_note_append_keeps_legacy_body_updates_and_versioned_retries(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $current = $this->revision($document, 1, 'owned-source');
        Sanctum::actingAs($author);
        $id = $this->postJson($this->url($document), ['body' => 'Plain text', 'citations' => []])
            ->assertCreated()->json('data.id');
        $draft = 'draft:'.Str::uuid();
        $payload = ['version' => 1, 'body' => 'Edited plain text',
            'appendCitations' => [$this->citation($current, 'owned-source', $draft)]];
        $saved = $this->patchJson($this->url($document, $id), $payload)->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.body', 'Edited plain text')
            ->assertJsonPath('data.contentFormat', 'plain_text')->assertJsonCount(1, 'data.citations');
        $citationId = $saved->json('data.citations.0.id');
        $this->patchJson($this->url($document, $id), $payload)->assertStatus(409)
            ->assertJsonPath('error.code', 'NOTE_VERSION_CONFLICT')->assertJsonPath('current.version', 2);
        $this->assertSame(1, DocumentNote::findOrFail($id)->citations()->count());
        $this->patchJson($this->url($document, $id), ['version' => 2, 'body' => 'Another plain edit'])
            ->assertOk()->assertJsonPath('data.citations.0.id', $citationId)
            ->assertJsonPath('data.content', null);
    }

    public function test_append_observes_the_combined_fifty_citation_limit(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $current = $this->revision($document, 1, 'owned-source');
        Sanctum::actingAs($author);
        $existing = array_fill(0, 50, ['finding_label' => 'Snapshot', 'quote_snapshot' => 'Existing quote']);
        $id = $this->postJson($this->url($document), ['body' => 'Full note', 'citations' => $existing])
            ->assertCreated()->json('data.id');
        $this->patchJson($this->url($document, $id), ['version' => 1,
            'appendCitations' => [$this->citation($current, 'owned-source', 'draft:'.Str::uuid())]])
            ->assertStatus(422)->assertJsonPath('error.code', 'TOO_MANY_CITATIONS');
        $this->assertSame(50, DocumentNote::findOrFail($id)->citations()->count());
        $this->assertSame(1, DocumentNote::findOrFail($id)->version);
    }
}
