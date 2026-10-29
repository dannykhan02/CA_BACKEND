<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewWorkingViewsTest extends TestCase
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

    public function test_risk_review_reuses_authorization_and_rejects_stale_status_and_revision(): void
    {
        $author = $this->actor();
        $author->update(['role' => 'Administrator']);
        $document = $this->document($author);
        $revision = $this->revision($document, 1, 'fact:review');
        $risk = DocumentRisk::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
            'title' => 'Review exposure', 'description' => 'Unverified conclusion', 'risk_type' => 'legal',
            'severity' => 'medium', 'confidence' => .9, 'status' => 'open', 'evidence' => 'Review exposure was reported', 'prompt_version' => 'fixture']);
        Sanctum::actingAs($author);
        $url = '/api/documents/'.$document->id.'/risks/'.$risk->id;
        $this->postJson($this->url($document), ['body' => 'Keep canonical identities', 'appendReferences' => [[
            'reference_type' => 'finding', 'finding_id' => 'risk:0'.$risk->id, 'intelligence_revision_id' => $revision->id,
        ]]])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'mitigated', 'expected_status' => 'open', 'intelligence_revision_id' => $revision->id])
            ->assertOk()->assertJsonPath('data.status', 'mitigated');
        $this->patchJson($url, ['status' => 'closed', 'expected_status' => 'open', 'intelligence_revision_id' => $revision->id])->assertConflict();
        $this->assertDatabaseHas('document_risks', ['id' => $risk->id, 'status' => 'mitigated']);
        $this->revision($document, 2, 'fact:next');
        $this->patchJson($url, ['status' => 'closed', 'intelligence_revision_id' => $revision->id])->assertConflict();
        Sanctum::actingAs($this->actor(Workspace::find($document->workspace_id)));
        $this->patchJson($url, ['status' => 'closed'])->assertForbidden();
    }

    public function test_tracking_is_idempotent_and_current_status_can_be_retrieved_without_inferring_completion(): void
    {
        $author = $this->actor();
        $author->update(['role' => 'Administrator']);
        $document = $this->document($author);
        $revision = $this->revision($document, 1, 'fact:payment');
        $deadline = DocumentDeadline::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
            'title' => 'Scheduled payment', 'description' => 'Scheduled KES 100', 'deadline_type' => 'obligation',
            'date_type' => 'explicit', 'due_date' => '2020-01-01', 'confidence' => .9, 'status' => 'open', 'evidence' => 'Payment schedule', 'prompt_version' => 'fixture']);
        Sanctum::actingAs($author);
        $payload = ['document_id' => $document->id, 'deadline_id' => $deadline->id];
        $first = $this->postJson('/api/tracked-items', $payload)->assertCreated();
        $this->postJson('/api/tracked-items', $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('tracked_items', 1);
        $this->getJson('/api/tracked-items?filter=all&document_id='.$document->id.'&deadline_ids='.$deadline->id)
            ->assertOk()->assertJsonPath('data.0.status', 'open');
        $next = $this->revision($document, 2, 'fact:next');
        $recreated = $deadline->replicate(['id']);
        $recreated->intelligence_revision_id = $next->id;
        $recreated->save();
        $this->getJson('/api/tracked-items?filter=all&document_id='.$document->id.'&deadline_ids='.$recreated->id)
            ->assertOk()->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.0.current_deadline_ids.0', $recreated->id);
        $this->postJson('/api/tracked-items', $payload)->assertNotFound();
    }

    public function test_deleted_finding_is_unavailable_without_removing_the_note_or_snapshot(): void
    {
        $author = $this->actor();
        $document = $this->document($author);
        $revision = $this->revision($document, 1, 'fact:removed');
        Sanctum::actingAs($author);
        $this->postJson($this->url($document), ['body' => 'Retained writing', 'appendReferences' => [[
            'intelligence_revision_id' => $revision->id, 'reference_type' => 'finding', 'finding_id' => 'fact:removed',
        ]]])->assertCreated();
        $risk = DocumentRisk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'intelligence_revision_id' => $revision->id, 'title' => 'Preserved risk', 'description' => 'Original interpretation',
            'risk_type' => 'legal', 'severity' => 'low', 'confidence' => .9, 'status' => 'open', 'evidence' => 'Original quote', 'prompt_version' => 'fixture']);
        $source = 'risk:'.$risk->id;
        DocumentEvidence::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $revision->pipeline_key, 'identity' => hash('sha256', $source), 'kind' => 'risk', 'source_id' => $source,
            'data' => ['label' => 'Preserved risk', 'quote' => 'Original quote'], 'sources' => []]);
        $this->postJson($this->url($document), ['body' => 'Risk writing retained', 'appendReferences' => [[
            'intelligence_revision_id' => $revision->id, 'reference_type' => 'finding', 'finding_id' => $source,
        ]]])->assertCreated();
        $risk->delete();
        $this->getJson($this->url($document).'?finding_id='.$source.'&revision='.$revision->id)->assertOk()
            ->assertJsonPath('data.0.references.0.state', 'unavailable');
        DocumentEvidence::where('document_id', $document->id)->delete();
        $this->getJson($this->url($document).'?finding_id=fact:removed&revision='.$revision->id)->assertOk()->assertJsonPath('data.0.body', 'Retained writing')
            ->assertJsonPath('data.0.references.0.state', 'unavailable');
    }
}
