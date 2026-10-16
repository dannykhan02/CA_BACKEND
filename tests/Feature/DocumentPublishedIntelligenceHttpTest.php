<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEntity;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentPublishedIntelligenceHttpTest extends TestCase
{
    use RefreshDatabase;

    private function publishedDocument(string $type = 'DOCX'): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $text = 'The document records five risks, one deadline, and nineteen entities.';
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Intelligence.'.$type, 'type' => $type,
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'extracted_text' => $text]);
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published',
            'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text),
            'ai_pipeline' => ['route' => 'normal', 'evidence_trimmed' => false]]);
        $document->forceFill(['intelligence_generation' => 1,
            'published_intelligence_revision_id' => $revision->id,
            'ai_pipeline' => ['route' => 'normal', 'evidence_trimmed' => false]])->save();
        DocumentIntelligenceSummary::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'intelligence_revision_id' => $revision->id,
            'executive_summary' => str_repeat('Summary. ', 38).'Done.',
            'key_findings' => ['A documented finding'], 'critical_risks' => [],
            'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        for ($i = 1; $i <= 5; $i++) {
            DocumentRisk::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'intelligence_revision_id' => $revision->id, 'title' => 'Risk '.$i,
                'description' => 'Recorded risk '.$i, 'severity' => 'medium', 'confidence' => .9,
                'evidence' => 'Recorded risk '.$i, 'status' => 'open', 'prompt_version' => 'test']);
        }
        DocumentDeadline::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revision->id, 'title' => 'Deadline',
            'description' => 'Recorded deadline', 'due_date' => '2026-12-01', 'date_type' => 'explicit',
            'confidence' => .9, 'evidence' => 'Recorded deadline', 'status' => 'open',
            'prompt_version' => 'test']);
        for ($i = 1; $i <= 19; $i++) {
            DocumentEntity::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'intelligence_revision_id' => $revision->id, 'entity_type' => 'organization',
                'value' => 'Entity '.$i, 'confidence' => .9, 'context' => 'Entity '.$i,
                'prompt_version' => 'test']);
        }

        return [$user, $document, $revision];
    }

    public function test_ready_normal_docx_returns_published_intelligence(): void
    {
        [$user, $document, $revision] = $this->publishedDocument();
        $this->assertPublishedResponse($user, $document, $revision);
    }

    public function test_ready_normal_pdf_returns_published_intelligence(): void
    {
        [$user, $document, $revision] = $this->publishedDocument('PDF');
        $this->assertPublishedResponse($user, $document, $revision);
    }

    private function assertPublishedResponse(User $user, Document $document, DocumentIntelligenceRevision $revision): void
    {
        config(['intelligence_v2.enabled' => true]);
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        $response->assertJsonPath('data.intelligenceRevisionId', $revision->id)
            ->assertJsonPath('data.summary', ['entities' => 19, 'risks' => 5, 'deadlines' => 1]);
        $this->assertNotNull($response->json('data.intelligenceSummary'));
        $this->assertCount(5, $response->json('data.risks'));
        $this->assertCount(1, $response->json('data.deadlines'));
        $this->assertCount(19, $response->json('data.entities'));
        $response->assertJsonPath('data.processingDetails.route', 'normal')
            ->assertJsonPath('data.processingDetails.evidenceTrimmed', false)
            ->assertJsonPath('data.analysis.attention.coverage.state', 'unavailable')
            ->assertJsonPath('data.analysis.attention.coverage.evidence_total', null)
            ->assertJsonPath('data.analysis.attention.coverage.total_chunks', null)
            ->assertJsonPath('data.intelligenceSummary.materialFindings', []);
        $this->assertNotEmpty($response->json('data.intelligenceSummary.keyFindings'));
        $this->assertNull($response->json('data.intelligenceSummary.executiveAssessment'));
    }

    public function test_foreign_workspace_cannot_read_published_intelligence(): void
    {
        [, $document] = $this->publishedDocument();
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);

        $this->actingAs($outsider->fresh(), 'sanctum')
            ->getJson("/api/documents/{$document->id}/intelligence")->assertForbidden();
    }

    public function test_stale_revision_and_other_document_rows_are_excluded(): void
    {
        [$user, $document, $revision] = $this->publishedDocument();
        $stale = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'superseded']);
        [, $otherDocument, $otherRevision] = $this->publishedDocument('PDF');
        foreach ([[$document, $stale], [$otherDocument, $otherRevision]] as [$rowDocument, $rowRevision]) {
            DocumentRisk::create(['document_id' => $rowDocument->id, 'workspace_id' => $rowDocument->workspace_id,
                'intelligence_revision_id' => $rowRevision->id, 'title' => 'Excluded risk',
                'description' => 'Excluded risk', 'severity' => 'high', 'confidence' => .9,
                'evidence' => 'Excluded risk', 'status' => 'open', 'prompt_version' => 'test']);
            DocumentDeadline::create(['document_id' => $rowDocument->id, 'workspace_id' => $rowDocument->workspace_id,
                'intelligence_revision_id' => $rowRevision->id, 'title' => 'Excluded deadline',
                'description' => 'Excluded deadline', 'due_date' => '2026-12-02',
                'date_type' => 'explicit', 'confidence' => .9, 'evidence' => 'Excluded deadline',
                'status' => 'open', 'prompt_version' => 'test']);
            DocumentEntity::create(['document_id' => $rowDocument->id, 'workspace_id' => $rowDocument->workspace_id,
                'intelligence_revision_id' => $rowRevision->id, 'entity_type' => 'organization',
                'value' => 'Excluded entity', 'confidence' => .9, 'context' => 'Excluded entity',
                'prompt_version' => 'test']);
            if ($rowRevision->id === $stale->id) {
                DocumentIntelligenceSummary::create(['document_id' => $rowDocument->id, 'workspace_id' => $rowDocument->workspace_id,
                    'intelligence_revision_id' => $rowRevision->id, 'executive_summary' => 'Excluded summary',
                    'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
                    'important_entities' => [], 'recommended_attention' => [], 'prompt_version' => 'test']);
            }
        }

        $this->assertPublishedResponse($user, $document, $revision);
        $response = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        $this->assertStringNotContainsString('Excluded', $response->getContent());
    }

    public function test_normal_route_quotes_have_revision_ownership_but_no_exact_location(): void
    {
        [$user, $document, $revision] = $this->publishedDocument();
        $this->actingAs($user, 'sanctum');
        $risk = $document->risks()->firstOrFail();
        $deadline = $document->deadlines()->firstOrFail();
        foreach (["risk:{$risk->id}" => 'Recorded risk 1',
            "deadline:{$deadline->id}" => 'Recorded deadline'] as $reference => $quote) {
            $this->getJson("/api/documents/{$document->id}/sources/{$reference}")->assertOk()
                ->assertJsonPath('data.intelligenceRevisionId', $revision->id)
                ->assertJsonPath('data.source.text', $quote)
                ->assertJsonPath('data.source.range', null)
                ->assertJsonPath('data.matchState', 'UNAVAILABLE');
            $this->getJson("/api/documents/{$document->id}/sources/{$reference}/docx-window?expected_revision_id={$revision->id}")
                ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
        }
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.intelligenceSummary.keyFindings.0', 'A documented finding');
    }

    public function test_normal_route_pdf_quote_does_not_claim_an_exact_page(): void
    {
        [$user, $document, $revision] = $this->publishedDocument('PDF');
        $risk = $document->risks()->firstOrFail();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}/sources/risk:{$risk->id}")->assertOk()
            ->assertJsonPath('data.intelligenceRevisionId', $revision->id)
            ->assertJsonPath('data.source.page', null)
            ->assertJsonPath('data.source.range', null)
            ->assertJsonPath('data.matchState', 'UNAVAILABLE');
    }

    public function test_normal_route_pdf_with_stored_offsets_exposes_only_its_verified_page(): void
    {
        [$user, $document, $revision] = $this->publishedDocument('PDF');
        $risk = $document->risks()->firstOrFail();
        $quote = 'The document records five risks';
        DocumentEvidence::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'pipeline_key' => 'legacy', 'identity' => hash('sha256', 'risk-quote'), 'kind' => 'risk',
            'source_id' => 'risk:'.$risk->id, 'data' => ['value' => $quote],
            'sources' => [['quote' => $quote, 'start_offset' => 0,
                'end_offset' => mb_strlen($quote), 'page' => 1]]]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}/sources/risk:{$risk->id}")->assertOk()
            ->assertJsonPath('data.intelligenceRevisionId', $revision->id)
            ->assertJsonPath('data.matchState', 'EXACT')
            ->assertJsonPath('data.source.page', 1)
            ->assertJsonPath('data.source.range.start', 0)
            ->assertJsonPath('data.source.text', $quote);
    }
}
