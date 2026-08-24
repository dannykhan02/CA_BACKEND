<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
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
