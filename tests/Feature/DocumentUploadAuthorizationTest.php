<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Version 1 correction: a Personal-workspace user's database role (still
 * 'Viewer' by default at signup) must not block Personal-workspace uploads,
 * while Organization-workspace role restrictions remain exactly as they
 * were. Reuses the createOrgUser()/createDocInWorkspace() convention from
 * DocumentAuthorizationTest.php and the mockEmbeddingClient()/
 * insertEmbeddingRow() convention from DocumentQaTest.php rather than
 * inventing new ones.
 */
class DocumentUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFile(): UploadedFile
    {
        return UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
    }

    private function createPersonalUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return $user->fresh();
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

    private function createDocInWorkspace(string $workspaceId, string $uploadedBy, string $name = 'Test Doc.pdf', string $classification = 'Public'): Document
    {
        return Document::create([
            'name' => $name,
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => $classification,
            'year' => 2026,
            'workspace_id' => $workspaceId,
            'uploaded_by' => $uploadedBy,
            'last_updated_by' => $uploadedBy,
        ]);
    }

    // ---------------------------------------------------------------
    // Change 1 / Tests 1-6: upload authorization
    // ---------------------------------------------------------------

    public function test_default_viewer_in_personal_workspace_can_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        // Matches signup's actual default — role is explicitly 'Viewer',
        // not elevated for this test.
        $user = $this->createPersonalUser('Viewer');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('documents', [
            'workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id,
        ]);
    }

    public function test_administrator_in_personal_workspace_can_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        $user = $this->createPersonalUser('Administrator');
        Sanctum::actingAs($user);

        $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ])->assertStatus(202);
    }

    public function test_reviewer_in_personal_workspace_can_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        $user = $this->createPersonalUser('Reviewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ])->assertStatus(202);
    }

    public function test_viewer_in_organization_workspace_cannot_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        [$user] = $this->createOrgUser('Viewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ])->assertStatus(403);
    }

    public function test_administrator_in_organization_workspace_can_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        [$user] = $this->createOrgUser('Administrator');
        Sanctum::actingAs($user);

        $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ])->assertStatus(202);
    }

    public function test_reviewer_in_organization_workspace_can_upload(): void
    {
        Storage::fake('documents');
        Queue::fake();

        [$user] = $this->createOrgUser('Reviewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/documents', [
            'file' => $this->fakeFile(),
            'classification' => 'Public',
        ])->assertStatus(202);
    }

    // ---------------------------------------------------------------
    // Change 5 / Tests 7-9: Personal workspace isolation
    // ---------------------------------------------------------------

    public function test_personal_workspace_view_isolation(): void
    {
        $userA = $this->createPersonalUser('Viewer');
        $userB = $this->createPersonalUser('Viewer');

        $docA = $this->createDocInWorkspace($userA->current_workspace_id, $userA->id, 'User A Personal Doc.pdf');

        Sanctum::actingAs($userB);
        $response = $this->getJson("/api/documents/{$docA->id}");

        $response->assertStatus(403);
        $this->assertStringNotContainsString('User A Personal Doc', $response->getContent());

        Sanctum::actingAs($userA);
        $this->getJson("/api/documents/{$docA->id}")->assertOk();
    }

    public function test_personal_workspace_search_isolation(): void
    {
        $userA = $this->createPersonalUser('Viewer');
        $userB = $this->createPersonalUser('Viewer');

        $docA = $this->createDocInWorkspace($userA->current_workspace_id, $userA->id, 'User A Confidential Report.pdf');

        $vector = array_fill(0, 1024, 0.001);
        $vectorLiteral = '[' . implode(',', $vector) . ']';

        DB::statement(
            'INSERT INTO document_embeddings
                (id, workspace_id, document_id, chunk_index, chunk_text, provider, model, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $userA->current_workspace_id, $docA->id, 0, 'User A confidential figures.', 'voyage', 'voyage-test', $vectorLiteral]
        );

        $this->mock(VoyageEmbeddingClient::class, function ($mock) use ($vector) {
            $mock->shouldReceive('embed')
                ->andReturn(['embeddings' => [$vector], 'model' => 'voyage-test']);
        });

        // User B searches — must not retrieve anything from User A's
        // Personal workspace, despite identical vector similarity.
        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/documents/search?q=confidential+figures');

        $response->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString($docA->id, $response->getContent());

        // User A searches their own workspace — must find it.
        Sanctum::actingAs($userA);
        $this->getJson('/api/documents/search?q=confidential+figures')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_personal_workspace_qa_isolation(): void
    {
        $userA = $this->createPersonalUser('Viewer');
        $userB = $this->createPersonalUser('Viewer');

        $docA = $this->createDocInWorkspace($userA->current_workspace_id, $userA->id, 'User A Secret Plan.pdf');

        $vector = array_fill(0, 1024, 0.001);
        $vectorLiteral = '[' . implode(',', $vector) . ']';

        DB::statement(
            'INSERT INTO document_embeddings
                (id, workspace_id, document_id, chunk_index, chunk_text, provider, model, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $userA->current_workspace_id, $docA->id, 0, 'User A secret plan details.', 'voyage', 'voyage-test', $vectorLiteral]
        );

        $this->mock(VoyageEmbeddingClient::class, function ($mock) use ($vector) {
            $mock->shouldReceive('embed')
                ->andReturn(['embeddings' => [$vector], 'model' => 'voyage-test']);
        });

        // Must never reach Claude with User A's content on User B's behalf.
        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldNotReceive('answerDocumentQuestion');
        });

        Sanctum::actingAs($userB);
        $response = $this->postJson('/api/documents/query', ['question' => 'What is the secret plan?']);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'No answer.',
        ]);
        $this->assertStringNotContainsString($docA->id, $response->getContent());
        $this->assertStringNotContainsString('Secret Plan', $response->getContent());
    }
}
