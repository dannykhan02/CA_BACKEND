<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentRisk;
use App\Models\Matter;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\DocumentComparisonService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MatterPublishedRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function revision(Document $document, int $generation, string $state): DocumentIntelligenceRevision
    {
        return DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => $generation, 'state' => $state,
            'extracted_text' => 'Test source', 'extracted_text_hash' => hash('sha256', 'Test source')]);
    }

    private function risk(Document $document, ?string $revisionId, string $title): DocumentRisk
    {
        return DocumentRisk::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'intelligence_revision_id' => $revisionId,
            'title' => $title, 'description' => $title, 'severity' => 'high',
            'confidence' => .9, 'evidence' => $title, 'status' => 'open', 'prompt_version' => 'test']);
    }

    public function test_matter_uses_each_documents_published_revision_through_regeneration_and_removal(): void
    {
        $user = User::factory()->create(['role' => 'Administrator']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Review']);
        $makeDocument = fn (string $name) => Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'matter_id' => $matter->id, 'name' => $name,
            'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Public',
            'size_kb' => 1, 'year' => 2026]);
        $first = $makeDocument('First.pdf');
        $second = $makeDocument('Second.pdf');
        $legacy = $makeDocument('Legacy.pdf');
        $old = $this->revision($first, 1, 'published');
        $new = $this->revision($first, 2, 'candidate');
        $other = $this->revision($second, 1, 'published');
        $first->forceFill(['intelligence_generation' => 2, 'published_intelligence_revision_id' => $old->id,
            'active_intelligence_revision_id' => $new->id, 'status' => 'Processing'])->save();
        $second->forceFill(['intelligence_generation' => 1, 'published_intelligence_revision_id' => $other->id])->save();
        $this->risk($first, $old->id, 'Old published risk');
        $this->risk($first, $new->id, 'Hidden candidate risk');
        $this->risk($second, $other->id, 'Other published risk');
        $this->risk($legacy, null, 'Legacy risk');

        $this->actingAs($user, 'sanctum');
        $url = "/api/matters/{$matter->id}/intelligence?kind=risks";
        $current = $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing(['Old published risk', 'Other published risk', 'Legacy risk'],
            array_column($current->json('data'), 'title'));
        $this->getJson("/api/matters/{$matter->id}")->assertJsonPath('overview.risks', 3);

        $new->update(['state' => 'published']);
        $first->forceFill(['published_intelligence_revision_id' => $new->id,
            'active_intelligence_revision_id' => null, 'status' => 'Ready'])->save();
        $current = $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing(['Hidden candidate risk', 'Other published risk', 'Legacy risk'],
            array_column($current->json('data'), 'title'));

        $second->update(['matter_id' => null]);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/matters/{$matter->id}")->assertJsonPath('overview.risks', 2);
    }

    public function test_saved_comparison_pins_published_sources_and_reports_when_outdated(): void
    {
        Bus::fake();
        $user = User::factory()->create(['role' => 'Administrator']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $user->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Comparison']);
        $docs = [];
        foreach (['Base', 'Compared'] as $name) {
            $doc = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'matter_id' => $matter->id, 'name' => $name,
                'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Public',
                'size_kb' => 1, 'year' => 2026]);
            $revision = $this->revision($doc, 1, 'published');
            $doc->forceFill(['intelligence_generation' => 1,
                'published_intelligence_revision_id' => $revision->id])->save();
            $this->risk($doc, $revision->id, $name.' published risk');
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id,
                    'document_id' => $doc->id, 'intelligence_revision_id' => $revision->id,
                    'stage' => $stage, 'status' => 'completed']);
            }
            $docs[] = [$doc, $revision];
        }
        [[$base, $baseRevision], [$compared, $comparedRevision]] = $docs;
        $candidate = $this->revision($base, 2, 'candidate');
        $base->forceFill(['intelligence_generation' => 2,
            'active_intelligence_revision_id' => $candidate->id, 'status' => 'Processing'])->save();
        $this->risk($base, $candidate->id, 'Candidate risk');

        $comparison = app(DocumentComparisonService::class)->create($user, $base->id, $compared->id);
        $this->assertSame($baseRevision->id, $comparison->metadata['source_revisions']['base']);
        $this->assertSame($comparedRevision->id, $comparison->metadata['source_revisions']['compared']);
        $this->assertArrayNotHasKey('Candidate risk', $comparison->metadata['base']);
        $this->assertStringNotContainsString('Candidate risk', json_encode($comparison->metadata));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/document-comparisons/{$comparison->id}")
            ->assertOk()->assertJsonPath('data.revision_status', 'current');
        $candidate->update(['state' => 'published']);
        $base->forceFill(['published_intelligence_revision_id' => $candidate->id,
            'active_intelligence_revision_id' => null, 'status' => 'Ready'])->save();
        $this->getJson("/api/document-comparisons/{$comparison->id}")
            ->assertOk()->assertJsonPath('data.revision_status', 'outdated');
        $this->assertSame($baseRevision->id, $comparison->fresh()->metadata['source_revisions']['base']);
    }
}
