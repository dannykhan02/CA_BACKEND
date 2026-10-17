<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentRisk;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalRouteProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_span_id_pins_the_second_identical_occurrence_without_quote_search(): void
    {
        config()->set('document_intelligence.revision_v1', true);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $sentence = 'The reserve is KES 7 million.';
        $text = $sentence."\f".$sentence;
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Reserve.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'reserve file'),
            'extracted_text' => $text, 'pages' => 2]);
        $revision = app(DocumentRevisionPublisher::class)->begin($document);
        $this->assertNotNull($revision);
        $key = 'normal:'.$revision->id;
        $serviceEligible = app(NormalRouteProvenance::class)->eligible($document, $text);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => $key,
            'normal_span_grounding' => $serviceEligible]])->save();
        $this->assertTrue($serviceEligible);
        $revision->forceFill(['pipeline_key' => $key])->save();

        $service = app(NormalRouteProvenance::class);
        $rendered = $service->promptText($document->fresh(), $text);
        $this->assertStringContainsString('[E001]', $rendered);
        $this->assertStringContainsString('[E002]', $rendered);
        $risk = DocumentRisk::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'intelligence_revision_id' => $revision->id,
            'title' => 'Reserve', 'description' => 'Reserve', 'severity' => 'medium',
            'confidence' => .9, 'evidence' => $sentence, 'status' => 'open',
            'prompt_version' => 'test']);
        $service->record($document->fresh(), 'risk', (string) $risk->id,
            ['source_span_id' => 'E002', 'evidence' => $sentence]);
        $service->record($document->fresh(), 'risk', '2',
            ['source_span_id' => 'E001', 'evidence' => 'An invented quotation']);
        $service->record($document->fresh(), 'risk', '3',
            ['source_span_id' => 'E999', 'evidence' => $sentence]);
        $this->assertSame(1, DocumentEvidence::where('document_id', $document->id)->count());

        $service->publish($document->fresh());
        $setId = DB::table('source_provenance_sets')
            ->where('document_id', $document->id)->value('id');
        $this->assertNotNull($setId);
        DocumentIntelligenceSummary::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'intelligence_revision_id' => $revision->id,
            'executive_summary' => 'The reserve is recorded.', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
                'stage' => $stage, 'status' => 'completed']);
        }
        app(DocumentRevisionPublisher::class)->publish($document, $revision->id);
        $this->assertSame($setId, $revision->fresh()->provenance_set_id);
        $sourceId = 'risk:'.$risk->id;
        $source = app(SourceReferenceResolver::class)->resolve($document->fresh(), $sourceId);
        $this->assertSame('CURRENT', $source['state']);
        $this->assertSame('EXACT', $source['matchState']);
        $this->assertSame(mb_strlen($sentence) + 1, $source['source']['range']['start']);
        $this->assertSame($revision->id, $source['intelligenceRevisionId']);
        $this->actingAs($user, 'sanctum')->getJson("/api/documents/{$document->id}/sources/{$sourceId}")
            ->assertOk()->assertJsonPath('data.matchState', 'EXACT')
            ->assertJsonPath('data.source.page', 2);
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);
        $this->actingAs($outsider->fresh(), 'sanctum')
            ->getJson("/api/documents/{$document->id}/sources/{$sourceId}")->assertForbidden();
    }
}
