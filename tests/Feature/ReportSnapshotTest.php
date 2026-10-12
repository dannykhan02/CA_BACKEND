<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentRisk;
use App\Models\Matter;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $user, Matter $matter, string $name): Document
    {
        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'matter_id' => $matter->id, 'name' => $name,
            'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Public',
            'size_kb' => 1, 'year' => 2026]);
    }

    public function test_matter_snapshot_includes_all_pages_and_only_published_intelligence(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Export']);
        for ($i = 0; $i < 23; $i++) {
            $this->document($user, $matter, 'Document '.$i);
        }
        $document = $this->document($user, $matter, 'Reprocessing');
        $old = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published']);
        $candidate = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'candidate']);
        $document->forceFill(['intelligence_generation' => 2, 'published_intelligence_revision_id' => $old->id,
            'active_intelligence_revision_id' => $candidate->id, 'status' => 'Processing'])->save();
        foreach ([$old->id => 'Published', $candidate->id => 'Candidate'] as $revisionId => $title) {
            DocumentRisk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revisionId, 'title' => $title,
                'description' => $title, 'severity' => 'high', 'confidence' => .9,
                'evidence' => $title, 'status' => 'open', 'prompt_version' => 'test']);
        }
        $deleted = $this->document($user, $matter, 'Deleted');
        $deleted->delete();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/matters/{$matter->id}/report-snapshot")->assertOk();
        $this->assertCount(24, $response->json('data.documents'));
        $this->assertSame(['Published'], array_column($response->json('data.sections.risks'), 'title'));
        $this->assertSame($old->id, $response->json("data.revisionSnapshot.{$document->id}"));
        $this->assertStringNotContainsString('Candidate', json_encode($response->json('data.sections')));
        $this->assertStringNotContainsString('Deleted', json_encode($response->json('data.documents')));
    }

    public function test_foreign_user_cannot_request_a_matter_snapshot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($owner);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($other);
        $matter = Matter::create(['workspace_id' => $owner->fresh()->current_workspace_id,
            'created_by' => $owner->id, 'name' => 'Private']);

        $this->actingAs($other->fresh(), 'sanctum')
            ->getJson("/api/matters/{$matter->id}/report-snapshot")->assertNotFound();
    }

    public function test_document_snapshot_pairs_document_and_intelligence_from_one_read(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Export']);
        $document = $this->document($user, $matter, 'Source.pdf');
        $document->update(['extracted_text' => 'Notice is due tomorrow.']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}/report-snapshot")->assertOk();
        $this->assertSame($document->id, $response->json('data.document.id'));
        $this->assertSame($document->id, $response->json('data.intelligence.document.id'));
        $this->assertNull($response->json('data.intelligenceRevisionId'));
        $this->assertContains('Legacy intelligence has no immutable revision identity.',
            $response->json('data.limitations'));
    }

    public function test_matter_snapshot_reports_failed_legacy_document_as_incomplete(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Incomplete export']);
        $failed = $this->document($user, $matter, 'Failed.pdf');
        $failed->update(['status' => 'Failed']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/matters/{$matter->id}/report-snapshot")->assertOk();

        $this->assertFalse($response->json('data.complete'));
        $this->assertContains('Some documents have no published intelligence.',
            $response->json('data.limitations'));
    }

    public function test_matter_snapshot_checks_partial_state_from_published_revision(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id,
            'created_by' => $user->id, 'name' => 'Refreshing export']);
        $document = $this->document($user, $matter, 'Refreshing.pdf');
        $published = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published',
            'ai_pipeline' => ['partial' => false]]);
        $document->forceFill(['published_intelligence_revision_id' => $published->id,
            'intelligence_generation' => 2, 'status' => 'Processing',
            'ai_pipeline' => ['partial' => true]])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/matters/{$matter->id}/report-snapshot")->assertOk();

        $this->assertTrue($response->json('data.complete'));
        $this->assertSame($published->id, $response->json("data.revisionSnapshot.{$document->id}"));
    }
}
