<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Exceptions\EmbeddingProviderException;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Workspace} */
    private function createOrgUser(string $role = 'Viewer'): array
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

    private function createDocInWorkspace(string $workspaceId, string $name = 'Test Doc.pdf'): Document
    {
        return Document::create([
            'name' => $name,
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspaceId,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
        ]);
    }

    public function test_validation_failure_returns_standard_envelope(): void
    {
        $response = $this->postJson('/api/auth/signup', []);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The given data was invalid.',
        ]);
        $this->assertIsArray($response->json('errors'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_unauthenticated_request_returns_standard_envelope(): void
    {
        $response = $this->getJson('/api/documents');

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'errors' => [],
        ]);
    }

    public function test_not_found_document_returns_standard_envelope(): void
    {
        [$user] = $this->createOrgUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/documents/' . (string) Str::uuid());

        $response->assertStatus(404)->assertExactJson([
            'success' => false,
            'message' => 'Resource not found.',
            'errors' => [],
        ]);
    }

    public function test_cross_workspace_document_access_returns_standard_envelope(): void
    {
        [$userA] = $this->createOrgUser();
        [, $workspaceB] = $this->createOrgUser();

        $foreignDoc = $this->createDocInWorkspace($workspaceB->id, 'Workspace B Confidential.pdf');

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/documents/' . $foreignDoc->id);

        $response->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'This action is unauthorized.',
            'errors' => [],
        ]);

        $this->assertStringNotContainsString('Workspace B Confidential', $response->getContent());
    }

    public function test_embedding_provider_failure_returns_503_without_leaking_upstream_detail(): void
    {
        [$user, $workspace] = $this->createOrgUser();
        $this->createDocInWorkspace($workspace->id);

        $this->mock(VoyageEmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')
                ->andThrow(new EmbeddingProviderException(
                    'Voyage API request failed with status 429: rate limit exceeded.'
                ));
        });

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/documents/query', ['question' => 'What is in this document?']);

        $response->assertStatus(503)->assertExactJson([
            'success' => false,
            'message' => 'Service temporarily unavailable.',
            'errors' => [],
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('429', $body);
        $this->assertStringNotContainsString('rate limit', $body);
        $this->assertStringNotContainsString('Voyage', $body);
    }
}
