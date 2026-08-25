<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_document_in_another_workspace(): void
    {
        [$userA, $workspaceA] = $this->createOrgUser('Administrator');
        [, $workspaceB] = $this->createOrgUser('Administrator');

        $foreignDoc = Document::create([
            'name' => 'Foreign Workspace Report.pdf',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspaceB->id,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson("/api/documents/{$foreignDoc->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'errors' => [],
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString($workspaceB->id, $body);
        $this->assertStringNotContainsString('Foreign Workspace Report', $body);
    }

    public function test_document_index_excludes_other_workspaces(): void
    {
        [$userA, $workspaceA] = $this->createOrgUser('Administrator');
        [, $workspaceB] = $this->createOrgUser('Viewer');

        Document::create([
            'name' => 'Workspace A Doc.pdf',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspaceA->id,
            'uploaded_by' => $userA->id,
            'last_updated_by' => $userA->id,
        ]);

        Document::create([
            'name' => 'Workspace B Doc.pdf',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspaceB->id,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/documents');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Workspace A Doc.pdf', $response->json('data.0.name'));
    }

    public function test_personal_user_cannot_see_another_members_documents_in_shared_personal_workspace(): void
    {
        // Simulates the scenario the ownership-check fix targets: a Personal
        // workspace is meant to have exactly one member (enforced by
        // WorkspaceService::createPersonalWorkspaceFor, not by a DB
        // constraint), so this manually breaches that convention to prove
        // index() doesn't silently trust it.
        $userA = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($userA);

        $userB = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $userB->id,
            'role' => null,
            'joined_at' => now(),
        ]);
        $userB->forceFill(['current_workspace_id' => $workspace->id])->save();

        Document::create([
            'name' => 'User A Personal Doc.pdf',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Confidential',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $userA->id,
            'last_updated_by' => $userA->id,
        ]);

        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/documents');
        $response->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/documents');
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('User A Personal Doc.pdf', $response->json('data.0.name'));
    }

    public function test_organization_viewer_only_sees_permitted_classifications(): void
    {
        [$viewer, $workspace] = $this->createOrgUser('Viewer');

        $this->createDocInWorkspace($workspace->id, 'Public Doc.pdf', 'Public');
        $this->createDocInWorkspace($workspace->id, 'Internal Doc.pdf', 'Internal');
        $this->createDocInWorkspace($workspace->id, 'Confidential Doc.pdf', 'Confidential');
        $this->createDocInWorkspace($workspace->id, 'Restricted Doc.pdf', 'Restricted');

        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/documents');

        $response->assertOk()->assertJsonCount(2, 'data');
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Public Doc.pdf'));
        $this->assertTrue($names->contains('Internal Doc.pdf'));
        $this->assertFalse($names->contains('Confidential Doc.pdf'));
        $this->assertFalse($names->contains('Restricted Doc.pdf'));
    }

    public function test_organization_analyst_sees_confidential_but_not_restricted(): void
    {
        [$analyst, $workspace] = $this->createOrgUser('Analyst');

        $this->createDocInWorkspace($workspace->id, 'Confidential Doc.pdf', 'Confidential');
        $this->createDocInWorkspace($workspace->id, 'Restricted Doc.pdf', 'Restricted');

        Sanctum::actingAs($analyst);
        $response = $this->getJson('/api/documents');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Confidential Doc.pdf', $response->json('data.0.name'));
    }

    public function test_restricted_documents_only_visible_to_reviewer_and_administrator(): void
    {
        [$reviewer, $workspaceR] = $this->createOrgUser('Reviewer');
        $this->createDocInWorkspace($workspaceR->id, 'Restricted For Reviewer.pdf', 'Restricted');

        [$admin, $workspaceAd] = $this->createOrgUser('Administrator');
        $this->createDocInWorkspace($workspaceAd->id, 'Restricted For Admin.pdf', 'Restricted');

        [$viewer, $workspaceV] = $this->createOrgUser('Viewer');
        $this->createDocInWorkspace($workspaceV->id, 'Restricted For Viewer.pdf', 'Restricted');

        Sanctum::actingAs($reviewer);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($admin);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($viewer);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_classification_query_param_cannot_escalate_beyond_role_allowlist(): void
    {
        [$viewer, $workspace] = $this->createOrgUser('Viewer');
        $this->createDocInWorkspace($workspace->id, 'Restricted Doc.pdf', 'Restricted');

        Sanctum::actingAs($viewer);

        // Viewer explicitly requests a classification their role isn't
        // allowed to see — must not bypass the allow-list intersection.
        $response = $this->getJson('/api/documents?classification[]=Restricted');

        $response->assertOk()->assertJsonCount(0, 'data');
        $body = $response->getContent();
        $this->assertStringNotContainsString('Restricted Doc.pdf', $body);
    }

    private function createDocInWorkspace(string $workspaceId, string $name, string $classification): Document
    {
        return Document::create([
            'name' => $name,
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => $classification,
            'year' => 2026,
            'workspace_id' => $workspaceId,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
        ]);
    }

    /** @return array{0: User, 1: Workspace} */
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
