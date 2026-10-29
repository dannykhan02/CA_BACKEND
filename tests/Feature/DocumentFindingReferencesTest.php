<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentFindingReferencesTest extends TestCase
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
            'document_id' => $document->id, 'generation' => $generation, 'state' => 'published', 'extracted_text' => '', 'extracted_text_hash' => hash('sha256', ''),
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

    public function test_finding_references_are_durable_idempotent_private_revision_pinned_and_detachable(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $revision = $this->revision($document, 1, 'fact:stable');
        Sanctum::actingAs($author);
        $reference = ['intelligence_revision_id' => $revision->id, 'reference_type' => 'finding', 'finding_id' => 'fact:stable'];
        $created = $this->postJson($this->url($document), ['body' => 'My research', 'appendReferences' => [$reference, $reference]])
            ->assertCreated()->assertJsonCount(1, 'data.references');
        $id = $created->json('data.id');
        $ref = $created->json('data.references.0.id');
        $this->getJson($this->url($document))->assertOk()->assertJsonPath('data.0.references.0.findingId', 'fact:stable');
        $this->getJson('/api/documents/'.$document->id.'/note-references?revision='.$revision->id)
            ->assertOk()->assertJsonPath('data.fact:stable', 1);
        $this->patchJson($this->url($document, $id), ['version' => 1, 'appendReferences' => [$reference]])
            ->assertOk()->assertJsonCount(1, 'data.references')->assertJsonPath('data.version', 2);
        $this->patchJson($this->url($document, $id), ['version' => 1, 'removeReferenceIds' => [$ref]])->assertConflict();
        $other = $this->actor(Workspace::find($author->current_workspace_id));
        Sanctum::actingAs($other);
        $this->getJson($this->url($document))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->url($document, $id), ['version' => 2, 'removeReferenceIds' => [$ref]])->assertNotFound();
        $this->getJson('/api/documents/'.$document->id.'/note-references?revision='.$revision->id)->assertOk()->assertJsonPath('data', []);
        Sanctum::actingAs($author);
        $this->revision($document, 2, 'fact:new');
        $this->getJson($this->url($document))->assertOk()->assertJsonPath('data.0.references.0.state', 'earlier_revision');
        $this->patchJson($this->url($document, $id), ['version' => 2, 'appendReferences' => [$reference]])->assertUnprocessable();
        $this->patchJson($this->url($document, $id), ['version' => 2, 'body' => 'Still my writing', 'removeReferenceIds' => [$ref]])
            ->assertOk()->assertJsonCount(0, 'data.references')->assertJsonPath('data.body', 'Still my writing');
        $this->deleteJson($this->url($document, $id), [], ['If-Match' => '"3"'])->assertOk();
        $this->getJson($this->url($document))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_references_cannot_cross_document_or_user_boundaries_and_legacy_notes_remain_usable(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $foreign = $this->document($author);
        $revision = $this->revision($foreign, 1, 'fact:foreign');
        Sanctum::actingAs($author);
        $this->postJson($this->url($document), ['body' => 'Research', 'appendReferences' => [[
            'reference_type' => 'finding', 'finding_id' => 'fact:foreign', 'intelligence_revision_id' => $revision->id,
        ]]])->assertUnprocessable();
        $note = $this->postJson($this->url($document), ['body' => 'Legacy content'])->assertCreated()->assertJsonCount(0, 'data.references');
        $this->patchJson($this->url($document, $note->json('data.id')), ['version' => 1, 'body' => 'Edited legacy content'])->assertOk();
        Sanctum::actingAs($this->actor());
        $this->getJson($this->url($document))->assertNotFound();
        $this->getJson('/api/documents/'.$document->id.'/note-references')->assertNotFound();
    }
}
