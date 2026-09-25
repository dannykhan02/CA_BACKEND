<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Kpis\KpiIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceInsightsTrendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_grouping_preserves_source_labels_and_counts_distinct_documents(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');
        $a = $this->createReadyDocument($workspace);
        $b = $this->createReadyDocument($workspace);
        $resolver = app(KpiIdentityResolver::class);
        foreach ([[$a, 'Internal Service Charter Performance'], [$b, 'Internal Charter Performance'], [$b, 'Internal Charter Performance']] as [$doc, $label]) {
            $id = $resolver->resolve($workspace->id, ['label' => $label])['definition_id'];
            $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => $label, 'value' => '80', 'kpi_definition_id' => $id]);
        }
        // A legacy observation is not speculatively absorbed into a resolved identity.
        $a->kpis()->create(['workspace_id' => $workspace->id, 'label' => 'Internal Charter Performance', 'value' => '70']);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/workspace/insights/trends')->assertOk()->assertJsonCount(2, 'data.trends');
        $canonical = collect($response->json('data.trends'))->firstWhere('kpiDefinitionId', $id);
        $this->assertSame(2, $canonical['reportsIncluded']);
        $this->assertSame(2, $canonical['reportsTotal']);
        $this->assertTrue($canonical['isComplete']);
        $this->assertCount(3, $canonical['points']);
        $this->assertEqualsCanonicalizing(['Internal Service Charter Performance', 'Internal Charter Performance'], collect($canonical['points'])->pluck('label')->unique()->all());
        $legacy = collect($response->json('data.trends'))->firstWhere('kpiDefinitionId', null);
        $this->assertSame(1, $legacy['reportsIncluded']);
        $this->assertFalse($legacy['isComplete']);
    }

    public function test_unlinked_punctuation_variants_keep_the_existing_distinct_fallback_groups(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');
        foreach (['Internal Charter Performance', 'Internal — Charter Performance'] as $label) {
            $this->createReadyDocument($workspace)->kpis()->create(['workspace_id' => $workspace->id, 'label' => $label, 'value' => '80']);
        }
        Sanctum::actingAs($user);
        $this->getJson('/api/workspace/insights/trends')->assertOk()->assertJsonCount(2, 'data.trends');
    }

    public function test_kpis_with_identical_labels_are_grouped_into_one_trend(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');

        $docA = $this->createReadyDocument($workspace);
        $docB = $this->createReadyDocument($workspace);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docA->id,
            'label' => 'Internal Service Charter Performance',
            'value' => '82%',
            'value_numeric' => 82,
        ]);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docB->id,
            'label' => 'Internal Service Charter Performance',
            'value' => '88%',
            'value_numeric' => 88,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/workspace/insights/trends');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.trends');
        $response->assertJson([
            'data' => [
                'reportsTotal' => 2,
                'trends' => [
                    [
                        'label' => 'Internal Service Charter Performance',
                        'reportsIncluded' => 2,
                        'reportsTotal' => 2,
                        'isComplete' => true,
                    ],
                ],
            ],
        ]);
    }

    public function test_drifted_label_text_produces_an_honestly_incomplete_trend(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');

        $docA = $this->createReadyDocument($workspace);
        $docB = $this->createReadyDocument($workspace);
        $docC = $this->createReadyDocument($workspace);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docA->id,
            'label' => 'Internal Service Charter Performance',
            'value' => '82%',
        ]);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docB->id,
            'label' => 'Internal Service Charter Performance',
            'value' => '88%',
        ]);

        // Same real-world metric, but Claude phrased it differently — this
        // is exactly the drift the endpoint must NOT silently paper over.
        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docC->id,
            'label' => 'Internal Charter Performance',
            'value' => '90%',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/workspace/insights/trends');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.trends');

        $trends = collect($response->json('data.trends'));
        $matched = $trends->firstWhere('label', 'Internal Service Charter Performance');
        $drifted = $trends->firstWhere('label', 'Internal Charter Performance');

        $this->assertSame(2, $matched['reportsIncluded']);
        $this->assertSame(3, $matched['reportsTotal']);
        $this->assertFalse($matched['isComplete']);

        $this->assertSame(1, $drifted['reportsIncluded']);
        $this->assertFalse($drifted['isComplete']);
    }

    public function test_label_grouping_is_case_and_whitespace_normalized(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');

        $docA = $this->createReadyDocument($workspace);
        $docB = $this->createReadyDocument($workspace);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docA->id,
            'label' => 'Revenue Growth',
            'value' => '10%',
        ]);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $docB->id,
            'label' => '  revenue growth  ',
            'value' => '12%',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/workspace/insights/trends');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.trends');
        $response->assertJsonPath('data.trends.0.reportsIncluded', 2);
        $response->assertJsonPath('data.trends.0.isComplete', true);
    }

    public function test_kpis_from_another_workspace_are_excluded(): void
    {
        [$user, $workspace] = $this->createOrgUser('Administrator');
        [, $otherWorkspace] = $this->createOrgUser('Administrator');

        $ownDoc = $this->createReadyDocument($workspace);
        $foreignDoc = $this->createReadyDocument($otherWorkspace);

        DocumentKpi::create([
            'workspace_id' => $workspace->id,
            'document_id' => $ownDoc->id,
            'label' => 'Revenue Growth',
            'value' => '10%',
        ]);

        DocumentKpi::create([
            'workspace_id' => $otherWorkspace->id,
            'document_id' => $foreignDoc->id,
            'label' => 'Revenue Growth',
            'value' => '999%',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/workspace/insights/trends');

        $response->assertOk();
        $response->assertJsonPath('data.reportsTotal', 1);
        $response->assertJsonCount(1, 'data.trends');
        $response->assertJsonPath('data.trends.0.reportsIncluded', 1);
    }

    private function createReadyDocument(Workspace $workspace): Document
    {
        $uploader = User::factory()->create();

        return Document::create([
            'name' => 'Report ' . uniqid() . '.pdf',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $uploader->id,
            'last_updated_by' => $uploader->id,
        ]);
    }

    private function createOrgUser(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);

        $workspace = Workspace::create([
            'type' => WorkspaceType::Organization,
            'name' => 'Org ' . $user->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return [$user, $workspace];
    }
}
