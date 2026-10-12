<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentSearchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(WorkspaceType $type): User
    {
        $workspace = Workspace::create(['type' => $type, 'name' => 'Search']);
        $workspace->credits()->update(['documents_remaining' => 5]);
        $user = User::factory()->create(['role' => 'Administrator', 'current_workspace_id' => $workspace->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'Administrator', 'joined_at' => now()]);

        return $user;
    }

    private function document(User $user, string $name): Document
    {
        return Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => $name, 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => 'Public', 'year' => 2026]);
    }

    private function embed(Document $document, string $excerpt, ?string $revisionId = null, int $chunk = 0): void
    {
        $vector = '['.implode(',', array_fill(0, 1024, 0.001)).']';
        DB::statement('INSERT INTO document_embeddings
            (id, workspace_id, document_id, intelligence_revision_id, chunk_index, chunk_text, provider, model, embedding, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $document->workspace_id, $document->id, $revisionId, $chunk, $excerpt, 'voyage', 'test', $vector]);
    }

    public static function workspaceTypes(): array
    {
        return [[WorkspaceType::Personal], [WorkspaceType::Organization]];
    }

    #[DataProvider('workspaceTypes')]
    public function test_deleted_and_foreign_embeddings_cannot_leak_into_search(WorkspaceType $type): void
    {
        $actor = $this->actor($type);
        $active = $this->document($actor, 'Active.pdf');
        $deleted = $this->document($actor, 'Deleted secret.pdf');
        $foreign = $this->document($this->actor($type), 'Foreign secret.pdf');
        $this->embed($active, 'Active excerpt');
        $this->embed($deleted, 'Deleted secret excerpt');
        $this->embed($foreign, 'Foreign secret excerpt');
        $deleted->delete();

        $this->mock(VoyageEmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andReturn([
                'embeddings' => [array_fill(0, 1024, 0.001)], 'model' => 'test',
            ]);
        });
        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/documents/search?q=secret&limit=10')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($active->id, $response->json('data.0.document_id'));
        $this->assertStringNotContainsString('Deleted secret', $response->getContent());
        $this->assertStringNotContainsString('Foreign secret', $response->getContent());
    }

    public function test_search_returns_published_embedding_while_candidate_is_processing(): void
    {
        $actor = $this->actor(WorkspaceType::Personal);
        $document = $this->document($actor, 'Published.pdf');
        $published = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 1, 'state' => 'published']);
        $candidate = DocumentIntelligenceRevision::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'generation' => 2, 'state' => 'candidate']);
        $document->forceFill(['intelligence_generation' => 2,
            'published_intelligence_revision_id' => $published->id,
            'active_intelligence_revision_id' => $candidate->id, 'status' => 'Processing'])->save();
        $this->embed($document, 'Published excerpt', $published->id);
        $this->embed($document, 'Candidate secret excerpt', $candidate->id);

        $this->mock(VoyageEmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')->once()->andReturn([
                'embeddings' => [array_fill(0, 1024, 0.001)], 'model' => 'test',
            ]);
        });
        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/documents/search?q=excerpt')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Published excerpt', $response->json('data.0.excerpt'));
        $this->assertStringNotContainsString('Candidate secret', $response->getContent());
    }
}
