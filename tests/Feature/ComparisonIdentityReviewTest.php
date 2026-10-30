<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\ComparisonIdentityLink;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentKpi;
use App\Models\KpiDefinition;
use App\Models\Matter;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIdentityPair;
use Tests\TestCase;

class ComparisonIdentityReviewTest extends TestCase
{
    use BuildsIdentityPair;
    use RefreshDatabase;

    public function test_review_is_versioned_audited_and_future_results_use_pinned_decisions(): void
    {
        [$user, $a, $b, $old] = $this->identityPair();
        $path = '/api/document-comparisons/'.$old->id.'/identity';
        $candidate = $this->getJson($path)->assertOk()->assertJsonPath('data.candidates.0.state', 'candidate')->json('data.candidates.0');
        $this->assertSame('Buyer', $candidate['a']['context']['responsible_party']);
        $this->assertNotEmpty($candidate['a']['source']['evidence_ids']);
        $input = ['key' => $candidate['key'], 'version' => 0, 'state' => 'confirmed', 'reason' => 'Reviewed Clause 4 in both originals.'];
        $this->patchJson($path, $input)->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson($path, $input)->assertStatus(409);
        $this->getJson($path)->assertJsonPath('data.freshness', 'decisions_changed')->assertJsonPath('data.candidates.0.snapshot_state', 'candidate');
        $service = app(DocumentComparisonService::class);
        $reviewed = $service->create($user, $a->id, $b->id);
        $this->assertNotSame($old->fingerprint, $reviewed->fingerprint);
        $this->assertSame($reviewed->id, $service->create($user, $a->id, $b->id)->id);
        $this->patchJson($path, [...$input, 'version' => 1, 'state' => 'revoked'])->assertOk()->assertJsonPath('data.version', 2);
        (new CompareDocumentsJob($old->id, $old->dispatch_token))->handle($service);
        (new CompareDocumentsJob($reviewed->id, $reviewed->dispatch_token))->handle($service);
        $this->assertSame('supported_change', $reviewed->fresh()->changes[0]['outcome']);
        $this->assertSame('confirmed', $reviewed->fresh()->changes[0]['identity_basis']['state']);
        $this->getJson('/api/document-comparisons/'.$reviewed->id)->assertOk()
            ->assertJsonPath('data.identity_usage.0.state', 'confirmed')
            ->assertJsonPath('data.identity_usage.0.decision.version', 1);
        $this->assertEmpty(array_filter($old->fresh()->changes, fn ($c) => $c['outcome'] === 'supported_change'));
        $future = $service->create($user, $a->id, $b->id);
        (new CompareDocumentsJob($future->id, $future->dispatch_token))->handle($service);
        $this->assertEmpty(array_filter($future->fresh()->changes, fn ($c) => $c['outcome'] === 'supported_change'));
        $this->assertSame(2, DB::table('comparison_identity_events')->count());
    }

    public function test_rejection_is_not_automatically_reaccepted_and_missing_evidence_cannot_be_reviewed(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->json('data.candidates.0');
        $input = ['key' => $candidate['key'], 'version' => 0, 'state' => 'rejected', 'reason' => 'These provisions do not correspond.'];
        $this->patchJson($path, $input)->assertOk();
        $this->patchJson($path, [...$input, 'state' => 'confirmed', 'version' => 1])->assertStatus(409);
        $next = app(DocumentComparisonService::class)->create($user, $a->id, $b->id);
        $this->assertSame('rejected', $next->metadata['identity_context']['candidates'][0]['state']);
        DocumentEvidence::where('document_id', $a->id)->delete();
        $this->patchJson($path, [...$input, 'version' => 1])->assertStatus(409);
        $this->getJson($path)->assertJsonPath('data.candidates.0.stale', true);
    }

    public function test_new_publication_and_disappearing_records_do_not_inherit_confirmation(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->json('data.candidates.0');
        $input = ['key' => $candidate['key'], 'state' => 'confirmed', 'version' => 0, 'reason' => 'Reviewed both source records.'];
        $this->patchJson($path, $input)->assertOk();
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $a->workspace_id, 'document_id' => $a->id,
            'generation' => 2, 'state' => 'published', 'extracted_text' => 'New source', 'extracted_text_hash' => hash('sha256', 'New source')]);
        $a->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('data.candidates.0.reviewable', false)->assertJsonPath('data.candidates.0.stale', true);
        $this->patchJson($path, [...$input, 'version' => 1])->assertStatus(409);
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertJsonPath('data.revision_status', 'outdated');
        // Stale historical confirmations can still be explicitly revoked.
        $this->patchJson($path, [...$input, 'version' => 1, 'state' => 'revoked'])->assertOk();
    }

    public function test_mapping_endpoints_and_saved_results_reauthorize_both_sources(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->json('data.candidates.0');
        $input = ['key' => $candidate['key'], 'version' => 0, 'state' => 'confirmed', 'reason' => 'Reviewed both sources.'];
        $other = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($other);
        $this->actingAs($other->fresh(), 'sanctum')->getJson($path)->assertNotFound();
        $this->patchJson($path, $input)->assertNotFound();
        $this->actingAs($user, 'sanctum');
        $b->forceFill(['uploaded_by' => $other->id])->save();
        $this->getJson($path)->assertNotFound();
        $this->patchJson($path, $input)->assertNotFound();
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertNotFound();
        $this->assertSame(0, ComparisonIdentityLink::count());
    }

    public function test_legacy_rows_have_no_reusable_review_binding(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $a->forceFill(['published_intelligence_revision_id' => null, 'intelligence_generation' => 0])->save();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->json('data.candidates.0');
        $this->patchJson($path, ['key' => $candidate['key'], 'version' => 0, 'state' => 'confirmed', 'reason' => 'Attempt an unbound decision.'])->assertStatus(409);
    }

    public function test_organization_role_change_hides_private_identity_evidence_and_export(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $user->currentWorkspace->update(['type' => 'Organization']);
        $b->update(['classification' => 'Restricted']);
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->assertOk()->json('data.candidates.0');
        $matter = Matter::create(['workspace_id' => $user->current_workspace_id, 'created_by' => $user->id, 'name' => 'Review']);
        $comparison->update(['matter_id' => $matter->id]);
        $a->update(['matter_id' => $matter->id]);
        $b->update(['matter_id' => $matter->id]);
        $user->update(['role' => 'Viewer']);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson($path)->assertNotFound();
        $this->patchJson($path, ['key' => $candidate['key'], 'version' => 0, 'state' => 'confirmed', 'reason' => 'No longer authorized.'])->assertForbidden();
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertNotFound();
        $this->getJson('/api/matters/'.$matter->id.'/report-snapshot')->assertOk()->assertJsonCount(0, 'data.comparisons');
    }

    public function test_revocation_during_execution_does_not_rewrite_the_running_snapshot(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $key = $comparison->metadata['identity_context']['candidates'][0]['key'];
        $identity = app(ComparisonIdentityService::class);
        $identity->decide($user, $comparison, $key, 'confirmed', 0, 'Reviewed original provisions.');
        $real = app(DocumentComparisonService::class);
        $reviewed = $real->create($user, $a->id, $b->id);
        $saved = $reviewed->metadata;
        $service = \Mockery::mock(DocumentComparisonService::class);
        $service->shouldReceive('changes')->once()->with($saved['base'], $saved['compared'], $saved['identity_context'])
            ->andReturnUsing(function ($before, $after, $context) use ($identity, $user, $comparison, $key, $real) {
                $identity->decide($user, $comparison, $key, 'revoked', 1, 'Revoked while comparison runs.');

                return $real->changes($before, $after, $context);
            });
        (new CompareDocumentsJob($reviewed->id, $reviewed->dispatch_token))->handle($service);
        $this->assertSame('supported_change', $reviewed->fresh()->changes[0]['outcome']);
        $this->assertSame($saved, $reviewed->fresh()->metadata);
        $this->assertSame('revoked', ComparisonIdentityLink::sole()->state);
    }

    public static function productionIdentityModes(): array
    {
        return ['reviewed correspondence' => [false], 'source-bound identifier' => [true]];
    }

    #[DataProvider('productionIdentityModes')]
    public function test_production_snapshot_uses_reviewed_reporting_owner_and_keeps_all_numeric_gates(bool $system): void
    {
        Bus::fake();
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $this->actingAs($user, 'sanctum');
        $docs = [];
        $definition = KpiDefinition::create(['workspace_id' => $user->current_workspace_id,
            'canonical_name' => 'Revenue', 'normalized_name' => 'revenue', 'matching_metadata' => [], 'identity_key' => hash('sha256', 'revenue')]);
        foreach (['51.0', '52.0'] as $i => $value) {
            $identifier = $system ? ' (LEI: 5493001KJTIIGC8Y1R12)' : '';
            $text = "ACME HOLDINGS PLC{$identifier} Consolidated Metric | Q3 2026 | KES million\nRevenue | {$value} | Actual";
            $pipeline = ['route' => 'normal', 'key' => 'normal:identity-'.$i, 'normal_span_grounding' => true];
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => 'Report '.$i, 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
                'year' => 2026, 'pages' => 1, 'extracted_text' => $text, 'file_hash' => hash('sha256', $text)]);
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'generation' => 1, 'state' => 'candidate', 'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text), 'ai_pipeline' => $pipeline]);
            $doc->forceFill(['ai_pipeline' => $pipeline, 'active_intelligence_revision_id' => $revision->id])->save();
            $kpi = DocumentKpi::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'intelligence_revision_id' => $revision->id, 'kpi_definition_id' => $definition->id,
                'label' => 'Revenue', 'value' => $value, 'unit' => 'KES million', 'period' => 'Q3 2026',
                'identity_metadata' => ['concept' => 'revenue', 'scope' => 'ACME HOLDINGS PLC', 'metric_type' => 'amount', 'value_basis' => 'actual', 'aggregation' => 'total']]);
            $this->assertNull(app(NormalKpiGrounder::class)->record($doc, $kpi,
                ['source_context' => ['value_span_id' => 'E002', 'unit_span_id' => 'E001', 'period_span_id' => 'E001', 'subject_span_id' => 'E001']]));
            $revision->update(['state' => 'published']);
            $doc->forceFill(['status' => 'Ready', 'intelligence_generation' => 1, 'published_intelligence_revision_id' => $revision->id, 'active_intelligence_revision_id' => null])->save();
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                    'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
            }
            $docs[] = $doc;
        }
        $service = app(DocumentComparisonService::class);
        $before = $service->create($user, $docs[0]->id, $docs[1]->id);
        $candidate = $before->metadata['identity_context']['candidates'][0];
        $this->assertTrue($candidate['a']['context']['analytical_eligible']);
        $this->assertNull($candidate['a']['context']['owner']);
        $path = '/api/document-comparisons/'.$before->id.'/identity';
        if (! $system) {
            $this->patchJson($path, ['key' => $candidate['key'], 'state' => 'confirmed', 'version' => 0, 'reason' => 'Both sources report the same company.'])->assertOk();
        }
        $after = $service->create($user, $docs[0]->id, $docs[1]->id);
        foreach ([$before, $after] as $item) {
            (new CompareDocumentsJob($item->id, $item->dispatch_token))->handle($service);
        }
        $this->assertSame($system ? 'supported_change' : 'insufficient_evidence', $before->fresh()->changes[0]['outcome']);
        $this->assertSame('supported_change', $after->fresh()->changes[0]['outcome']);
        $this->assertSame($system ? 'established' : 'confirmed', $after->fresh()->changes[0]['identity_basis']['state']);
        $raw = $after->metadata;
        [$raw['base'], $raw['compared']] = app(IdentityCandidates::class)->apply($raw['base'], $raw['compared'], $raw['identity_context']);
        $key = array_key_first($raw['compared']);
        $raw['compared'][$key][0]['value']['period'] = 'Q3 2025';
        $this->assertSame('not_comparable', $service->changes($raw['base'], $raw['compared'])[0]['outcome']);
        $raw['compared'][$key][0]['value']['period'] = 'Q3 2026';
        $raw['compared'][$key][0]['value']['unit'] = 'USD million';
        $this->assertSame('not_comparable', $service->changes($raw['base'], $raw['compared'])[0]['outcome']);
    }
}
