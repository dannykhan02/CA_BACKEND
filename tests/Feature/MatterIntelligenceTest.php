<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\CompareDocumentsJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\SendTrackedDeadlineReminder;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\DocumentDeadline;
use App\Models\DocumentRelationship;
use App\Models\Matter;
use App\Models\ProcessingJob;
use App\Models\Subscription;
use App\Models\TrackedItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\TrackedDeadlineReminder;
use App\Services\AI\ResponseValidator;
use App\Services\AnthropicClient;
use App\Services\DocumentComparisonService;
use App\Services\IntelligenceAccess;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\TrackingService;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentComparisonPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MatterIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matter_kpis_expose_canonical_identity_without_collapsing_source_observations(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user);
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id, 'created_by' => $user->id, 'name' => 'Charter']);
        foreach (['Internal Service Charter Performance', 'Internal Charter Performance'] as $label) {
            $doc = $this->document($user, ['matter_id' => $matter->id]);
            $id = app(\App\Services\Kpis\KpiIdentityResolver::class)->resolve($user->current_workspace_id, ['label' => $label])['definition_id'];
            $doc->kpis()->create(['workspace_id' => $user->current_workspace_id, 'label' => $label, 'value' => '80', 'kpi_definition_id' => $id]);
        }
        $response = $this->getJson("/api/matters/{$matter->id}/intelligence?kind=kpis")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$id, $id], array_column($response->json('data'), 'kpi_definition_id'));
        $this->assertSame(['Internal Service Charter Performance', 'Internal Charter Performance'], array_column($response->json('data'), 'label'));
    }

    private function actor(bool $personal = true, string $role = 'Administrator'): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($personal) {
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        } else {
            $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Team']);
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
            $user->update(['current_workspace_id' => $workspace->id]);
        }

        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return $user;
    }

    private function document(User $user, array $extra = []): Document
    {
        $doc = Document::create($extra + ['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Contract.pdf', 'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026]);
        foreach (['entities', 'risks', 'deadlines'] as $stage) {
            ProcessingJob::create(['document_id' => $doc->id, 'workspace_id' => $doc->workspace_id, 'stage' => $stage, 'status' => 'completed']);
        }

        return $doc;
    }

    private function deadline(Document $doc): DocumentDeadline
    {
        return $doc->deadlines()->create(['workspace_id' => $doc->workspace_id, 'title' => 'Notice', 'description' => 'Give notice',
            'due_date' => '2027-01-02', 'date_type' => 'explicit', 'confidence' => .9, 'evidence' => 'Notice by 2 January 2027', 'status' => 'open', 'prompt_version' => '1']);
    }

    public function test_matter_crud_membership_and_safe_delete(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user);
        $doc = $this->document($user);
        $id = $this->postJson('/api/matters', ['name' => 'Supplier', 'type' => 'custom-type'])->assertCreated()->json('data.id');
        $this->patchJson("/api/matters/$id", ['name' => 'Supplier review', 'type' => null])->assertOk();
        $this->getJson('/api/matters')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/matters/$id/documents/{$doc->id}")->assertNoContent();
        $this->getJson("/api/matters/$id")->assertOk()->assertJsonPath('overview.documents', 1);
        $this->deleteJson("/api/matters/$id/documents/{$doc->id}")->assertNoContent();
        $this->assertNull($doc->fresh()->matter_id);
        $this->postJson("/api/matters/$id/documents/{$doc->id}")->assertNoContent();
        $this->deleteJson("/api/matters/$id")->assertNoContent();
        $this->assertNull($doc->fresh()->matter_id);
        $this->assertNotNull($doc->fresh());
    }

    public function test_cross_workspace_endpoints_never_expose_or_accept_foreign_ids(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $docA = $this->document($a);
        $docB = $this->document($b);
        $docB2 = $this->document($b);
        $matter = Matter::create(['workspace_id' => $b->current_workspace_id, 'created_by' => $b->id, 'name' => 'Secret']);
        $relationship = DocumentRelationship::create(['workspace_id' => $b->current_workspace_id, 'from_document_id' => $docB->id, 'to_document_id' => $docB2->id, 'relationship_type' => 'amends']);
        $tracked = app(TrackingService::class)->track($b, $docB->id, $this->deadline($docB)->id);
        Bus::fake();
        $comparison = app(DocumentComparisonService::class)->create($b, $docB->id, $docB2->id);
        Sanctum::actingAs($a);
        $this->getJson("/api/matters/{$matter->id}")->assertNotFound();
        $this->patchJson("/api/matters/{$matter->id}", ['name' => 'stolen'])->assertNotFound();
        $this->deleteJson("/api/matters/{$matter->id}")->assertNotFound();
        $this->getJson("/api/matters/{$matter->id}/intelligence")->assertNotFound();
        $this->postJson("/api/matters/{$matter->id}/documents/{$docA->id}")->assertNotFound();
        $this->postJson('/api/document-relationships', ['from_document_id' => $docA->id, 'to_document_id' => $docB->id, 'relationship_type' => 'related'])->assertNotFound();
        $this->deleteJson("/api/document-relationships/{$relationship->id}")->assertNotFound();
        $this->patchJson("/api/document-relationships/{$relationship->id}", ['relationship_type' => 'supports'])->assertNotFound();
        $this->postJson('/api/document-comparisons', ['base_document_id' => $docA->id, 'compared_document_id' => $docB->id])->assertNotFound();
        $this->getJson("/api/document-comparisons/{$comparison->id}")->assertNotFound();
        $this->patchJson("/api/tracked-items/{$tracked->id}", ['status' => 'completed'])->assertNotFound();
        foreach (['matters', 'document-relationships', 'document-comparisons', 'tracked-items'] as $path) {
            $this->getJson("/api/$path")->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_relationship_direction_duplicates_edit_and_self_link_validation(): void
    {
        $u = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($u);
        $data = ['from_document_id' => $a->id, 'to_document_id' => $b->id, 'relationship_type' => 'amends'];
        $id = $this->postJson('/api/document-relationships', $data)->assertCreated()->json('data.id');
        $this->postJson('/api/document-relationships', $data)->assertOk();
        $this->postJson('/api/document-relationships', ['from_document_id' => $b->id, 'to_document_id' => $a->id, 'relationship_type' => 'amends'])->assertCreated();
        $this->postJson('/api/document-relationships', array_replace($data, ['to_document_id' => $a->id]))->assertUnprocessable();
        $this->patchJson("/api/document-relationships/$id", ['relationship_type' => 'supplements', 'note' => 'Review this'])->assertOk();
        $this->getJson('/api/document-relationships')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/document-relationships/$id")->assertNoContent();
    }

    public function test_tracking_survives_reextraction_and_supports_correction_and_completion(): void
    {
        $u = $this->actor();
        Sanctum::actingAs($u);
        $doc = $this->document($u);
        $deadline = $this->deadline($doc);
        $id = $this->postJson('/api/tracked-items', ['document_id' => $doc->id, 'deadline_id' => $deadline->id])->assertCreated()->json('data.id');
        $deadline->delete();
        $replacement = $this->deadline($doc);
        $this->postJson('/api/tracked-items', ['document_id' => $doc->id, 'deadline_id' => $replacement->id])->assertCreated()->assertJsonPath('data.id', $id);
        $this->patchJson("/api/tracked-items/$id", ['due_date' => '2027-01-03', 'notes' => 'Confirmed by owner', 'status' => 'completed'])->assertOk();
        $tracked = TrackedItem::find($id);
        $this->assertNotNull($tracked->completed_at);
        $this->assertSame('2027-01-02', $tracked->source['extracted_due_date']);
        $this->getJson('/api/tracked-items?filter=completed')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/tracked-items/$id", ['status' => 'dismissed'])->assertOk();
        $this->assertNull($tracked->fresh()->completed_at);
    }

    public function test_comparison_is_cached_queued_and_preserves_before_after_sources(): void
    {
        Bus::fake();
        $u = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($u);
        foreach ([[$a, '500'], [$b, '800']] as [$doc, $value]) {
            $doc->kpis()->create(['workspace_id' => $u->current_workspace_id, 'label' => 'Budget', 'value' => $value, 'unit' => 'KES']);
        }
        $data = ['base_document_id' => $a->id, 'compared_document_id' => $b->id];
        $id = $this->postJson('/api/document-comparisons', $data)->assertAccepted()->json('data.id');
        $this->postJson('/api/document-comparisons', $data)->assertAccepted()->assertJsonPath('data.id', $id);
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 1);
        (new CompareDocumentsJob($id))->handle(app(DocumentComparisonService::class));
        $this->getJson("/api/document-comparisons/$id")->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.changes.0.before.0.value.value', '500')->assertJsonPath('data.changes.0.after.0.value.value', '800')
            ->assertJsonPath('data.changes.0.before.0.source.document_id', $a->id);
        $b->kpis()->update(['value' => '900']);
        $next = $this->postJson('/api/document-comparisons', $data)->assertAccepted()->json('data.id');
        $this->assertNotSame($id, $next);
        $b->update(['status' => 'Failed']);
        $this->postJson('/api/document-comparisons', $data)->assertUnprocessable();
    }

    public function test_soft_and_hard_document_deletion_remove_links_comparisons_and_tracking(): void
    {
        Bus::fake();
        $u = $this->actor();
        Sanctum::actingAs($u);
        foreach ([false, true] as $hard) {
            $a = $this->document($u);
            $b = $this->document($u);
            DocumentRelationship::create(['workspace_id' => $a->workspace_id, 'from_document_id' => $a->id, 'to_document_id' => $b->id, 'relationship_type' => 'amends']);
            app(DocumentComparisonService::class)->create($u, $a->id, $b->id);
            app(TrackingService::class)->track($u, $a->id, $this->deadline($a)->id);
            if ($hard) {
                $a->forceDelete();
            } else {
                $this->deleteJson("/api/documents/{$a->id}")->assertOk();
            }
            $this->assertDatabaseMissing('document_relationships', ['from_document_id' => $a->id]);
            $this->assertDatabaseMissing('document_comparisons', ['base_document_id' => $a->id]);
            $this->assertDatabaseMissing('tracked_items', ['document_id' => $a->id]);
        }
    }

    public function test_org_viewer_cannot_write_and_restricted_intelligence_is_hidden(): void
    {
        $u = $this->actor(false, 'Viewer');
        Sanctum::actingAs($u);
        $matter = Matter::create(['workspace_id' => $u->current_workspace_id, 'created_by' => $u->id, 'name' => 'Review']);
        $doc = $this->document($u, ['classification' => 'Restricted', 'matter_id' => $matter->id]);
        $this->deadline($doc);
        $this->postJson('/api/matters', ['name' => 'Disallowed'])->assertForbidden();
        $this->postJson("/api/matters/{$matter->id}/documents/{$doc->id}")->assertForbidden();
        $this->getJson("/api/matters/{$matter->id}")->assertOk()->assertJsonPath('overview.documents', 0);
        $this->getJson("/api/matters/{$matter->id}/intelligence?kind=deadlines")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_membership_is_required_even_if_current_workspace_id_matches(): void
    {
        $u = $this->actor();
        WorkspaceMember::where('user_id', $u->id)->delete();
        Sanctum::actingAs($u);
        $this->getJson('/api/matters')->assertForbidden();
        $this->getJson('/api/tracked-items')->assertForbidden();
    }

    public function test_suggestions_require_user_action_and_dismissals_persist(): void
    {
        $u = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($u);
        foreach ([$a, $b] as $d) {
            $d->entities()->create(['workspace_id' => $d->workspace_id, 'entity_type' => 'reference', 'value' => 'KE-4492', 'normalized_value' => 'ke-4492', 'confidence' => .9, 'prompt_version' => '1']);
        }
        $this->getJson("/api/documents/{$a->id}/context")->assertOk()->assertJsonCount(1, 'suggestions');
        $this->assertDatabaseCount('document_relationships', 0);
        $this->postJson("/api/documents/{$a->id}/suggestions/{$b->id}/dismiss")->assertNoContent();
        $this->getJson("/api/documents/{$a->id}/context")->assertOk()->assertJsonCount(0, 'suggestions');
    }

    public function test_opt_in_reminder_sends_once_and_never_sends_after_completion(): void
    {
        Notification::fake();
        $u = $this->actor();
        $doc = $this->document($u);
        $tracked = app(TrackingService::class)->track($u, $doc->id, $this->deadline($doc)->id);
        $job = new SendTrackedDeadlineReminder($tracked->id);
        $job->handle(app(IntelligenceAccess::class));
        Notification::assertNothingSent();
        $tracked->update(['remind_at' => now()->subMinute()]);
        $job->handle(app(IntelligenceAccess::class));
        $job->handle(app(IntelligenceAccess::class));
        Notification::assertSentToTimes($u, TrackedDeadlineReminder::class, 1);
        $tracked->update(['status' => 'completed', 'reminded_at' => null]);
        $job->handle(app(IntelligenceAccess::class));
        Notification::assertSentToTimes($u, TrackedDeadlineReminder::class, 1);
    }

    public function test_ai_terms_comparison_reuses_provider_and_rejects_fabricated_quotes(): void
    {
        Bus::fake();
        $this->seed(DocumentComparisonPromptSeeder::class);
        $u = $this->actor();
        $sub = Subscription::create(['workspace_id' => $u->current_workspace_id, 'user_id' => $u->id, 'plan_key' => 'starter', 'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $sub->periods()->create(['period_start' => now(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20, 'comparisons_allowed' => 5, 'storage_bytes' => 1073741824]);
        Sanctum::actingAs($u);
        $a = $this->document($u, ['extracted_text' => 'Payment is due Net 45.']);
        $b = $this->document($u, ['extracted_text' => 'Payment is due Net 30.']);
        $answer = ['changes' => [['label' => 'Payment terms', 'category' => 'terms', 'description' => 'Appears to modify payment terms.',
            'before' => ['value' => 'Net 45', 'chunk_id' => 'chunk-0', 'quote' => 'Payment is due Net 45.'],
            'after' => ['value' => 'Net 30', 'chunk_id' => 'chunk-0', 'quote' => 'Payment is due Net 30.']]]];
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($answer)]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ])]);
        $id = $this->postJson('/api/document-comparisons', ['base_document_id' => $a->id, 'compared_document_id' => $b->id, 'include_terms' => true])->assertAccepted()->json('data.id');
        (new CompareDocumentsJob($id))->handle(app(DocumentComparisonService::class));
        $this->getJson("/api/document-comparisons/$id")->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.changes.0.before.0.source.evidence', 'Payment is due Net 45.')
            ->assertJsonPath('data.changes.0.after.0.source.document_id', $b->id);
        $this->assertDatabaseHas('document_ai_runs', ['document_id' => $a->id, 'purpose' => 'document_comparison']);
        $answer['changes'][0]['after']['quote'] = 'An invented clause';
        $this->expectException(\RuntimeException::class);
        app(ResponseValidator::class)->validateComparison($answer, DocumentComparison::find($id)->metadata['ai_context']);
    }

    public function test_comparison_failure_is_explicit_and_incomplete_extraction_is_rejected(): void
    {
        Bus::fake();
        $u = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($u);
        $item = app(DocumentComparisonService::class)->create($u, $a->id, $b->id);
        (new CompareDocumentsJob($item->id))->failed(new \RuntimeException('Private provider detail'));
        $this->getJson("/api/document-comparisons/{$item->id}")->assertOk()->assertJsonPath('data.status', 'failed')->assertDontSee('Private provider detail');
        $b->processingJobs()->where('stage', 'risks')->update(['status' => 'failed']);
        $this->postJson('/api/document-comparisons', ['base_document_id' => $a->id, 'compared_document_id' => $b->id])->assertUnprocessable();
    }

    public function test_suggested_pair_assignment_is_atomic_if_second_document_is_forbidden(): void
    {
        $u = $this->actor();
        $foreign = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($foreign);
        $matter = Matter::create(['workspace_id' => $u->current_workspace_id, 'created_by' => $u->id, 'name' => 'Review']);
        $this->postJson("/api/matters/{$matter->id}/documents/{$a->id}", ['related_document_id' => $b->id])->assertNotFound();
        $this->assertNull($a->fresh()->matter_id);
    }

    public function test_risk_dismissal_is_authorized_and_preserved_when_identical_evidence_is_reextracted(): void
    {
        $u = $this->actor();
        Sanctum::actingAs($u);
        $a = $this->document($u, ['extracted_text' => 'Penalty applies.']);
        $risk = $a->risks()->create(['workspace_id' => $a->workspace_id, 'title' => 'Penalty', 'description' => 'Penalty applies',
            'severity' => 'high', 'confidence' => .9, 'evidence' => 'Penalty applies.', 'status' => 'open', 'prompt_version' => '1']);
        $this->patchJson("/api/documents/{$a->id}/risks/{$risk->id}", ['status' => 'closed'])->assertOk();
        $client = \Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->once()->andReturn(['prompt_version' => 1, 'risks' => [
            ['title' => 'Penalty', 'description' => 'Penalty applies', 'severity' => 'high', 'confidence' => .9, 'evidence' => 'Penalty applies.'],
        ]]);
        (new DetectDocumentRisksJob($a->id, true))->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('closed', $a->risks()->first()->status);
        $other = $this->actor();
        Sanctum::actingAs($other);
        $this->patchJson("/api/documents/{$a->id}/risks/{$a->risks()->first()->id}", ['status' => 'open'])->assertNotFound();
    }

    public function test_comparison_access_is_revoked_when_either_document_is_reclassified(): void
    {
        Bus::fake();
        $u = $this->actor(false, 'Analyst');
        Sanctum::actingAs($u);
        $a = $this->document($u);
        $b = $this->document($u);
        $comparison = app(DocumentComparisonService::class)->create($u, $a->id, $b->id);
        $this->getJson("/api/document-comparisons/{$comparison->id}")->assertOk();
        $b->update(['classification' => 'Restricted']);
        $this->getJson("/api/document-comparisons/{$comparison->id}")->assertNotFound();
        $this->getJson('/api/document-comparisons')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_missing_intelligence_can_be_retried_without_rebuilding_kpis_or_upload(): void
    {
        Bus::fake();
        $u = $this->actor();
        Sanctum::actingAs($u);
        $doc = $this->document($u, ['extracted_text' => 'Notice required.']);
        $doc->kpis()->create(['workspace_id' => $doc->workspace_id, 'label' => 'Budget', 'value' => '500']);
        $this->postJson("/api/documents/{$doc->id}/reprocess", ['intelligence_only' => true])->assertAccepted();
        $this->assertSame('Ready', $doc->fresh()->status);
        $this->assertSame(1, $doc->kpis()->count());
        Bus::assertNotDispatched(GenerateInsightsJob::class);
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 4);
        $this->postJson("/api/documents/{$doc->id}/reprocess", ['intelligence_only' => true])->assertUnprocessable();
    }
}
