<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers POST /documents/query end to end. VoyageEmbeddingClient and
 * AnthropicClient are both mocked — this suite proves the retrieval
 * authorization boundary, the isEmpty()-short-circuit cost guard, and the
 * controller's response contract, NOT the real Voyage/Anthropic
 * integrations. Real-API verification is a manual pre-deploy smoke-test
 * step (see docs/DEPLOYMENT_RUNBOOK.md), deliberately not part of
 * php artisan test, to keep CI fast, deterministic, and free of live API
 * cost on every push.
 *
 * Reuses the exact createOrgUser()/createDocInWorkspace() convention from
 * DocumentAuthorizationTest.php rather than inventing a new one.
 */
class DocumentQaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * pgvector's embedding column is vector(1024) (Voyage voyage-3
     * dimensionality per the migration's own comment). Using an identical
     * vector for both the query and the stored chunk guarantees cosine
     * similarity = 1.0, trivially clearing document_processing.
     * min_search_similarity regardless of its configured value — the point
     * of these tests is authorization and control flow, not tuning
     * relevance scoring.
     */
    private function fakeVector(): array
    {
        return array_fill(0, 1024, 0.001);
    }

    private function mockEmbeddingClient(): void
    {
        $this->mock(VoyageEmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')
                ->andReturn(['embeddings' => [$this->fakeVector()], 'model' => 'voyage-test']);
        });
    }

    /**
     * Raw insert, not the Eloquent model — the embedding column was added
     * via raw SQL (ALTER TABLE ... ADD COLUMN embedding vector(1024)), not
     * a Blueprint column, and has no Eloquent cast defined. Explicit
     * ::vector cast on the bound parameter, matching how the column itself
     * is typed, rather than relying on an implicit text->vector cast.
     */
    private function insertEmbeddingRow(string $workspaceId, string $documentId, string $chunkText = 'Synthetic test chunk for Q&A retrieval.'): void
    {
        $vectorLiteral = '[' . implode(',', $this->fakeVector()) . ']';

        DB::statement(
            'INSERT INTO document_embeddings
                (id, workspace_id, document_id, chunk_index, chunk_text, provider, model, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $workspaceId, $documentId, 0, $chunkText, 'voyage', 'voyage-test', $vectorLiteral]
        );
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

    private function createDocInWorkspace(string $workspaceId, string $name = 'Test Doc.pdf', string $classification = 'Public'): Document
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

    // ---------------------------------------------------------------

    public function test_query_requires_authentication(): void
    {
        $this->postJson('/api/documents/query', ['question' => 'What is the total budget?'])
            ->assertStatus(401);
    }

    public function test_question_too_short_returns_422_with_envelope(): void
    {
        [$user] = $this->createOrgUser('Viewer');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents/query', ['question' => 'hi']);

        $response->assertStatus(422)
            ->assertJson(['message' => 'The given data was invalid.'])
            ->assertJsonStructure(['errors' => ['question']]);
    }

    public function test_grounded_question_returns_cited_answer(): void
    {
        [$user, $workspace] = $this->createOrgUser('Viewer');
        $doc = $this->createDocInWorkspace($workspace->id, 'Q3 Budget Report.pdf', 'Public');

        $this->mockEmbeddingClient();
        $this->insertEmbeddingRow($workspace->id, $doc->id, 'Total budget for Q3 was 64.2 million.');

        $this->mock(AnthropicClient::class, function ($mock) use ($doc) {
            $mock->shouldReceive('answerDocumentQuestion')
                ->once()
                ->andReturn([
                    'answer' => 'The Q3 budget was 64.2 million.',
                    'confidence' => 'high',
                    'cited_document_ids' => [$doc->id],
                    'prompt_version' => '1',
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                ]);
        });

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/documents/query', ['question' => 'What was the Q3 budget?']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Answer generated.',
                'data' => [
                    'answer' => 'The Q3 budget was 64.2 million.',
                    'confidence' => 'high',
                    'cited_document_ids' => [$doc->id],
                ],
            ]);
    }

    public function test_no_relevant_context_returns_no_answer_without_calling_ai(): void
    {
        [$user, $workspace] = $this->createOrgUser('Viewer');
        // Deliberately no document_embeddings rows inserted at all.

        $this->mockEmbeddingClient();

        // Proves the isEmpty() short-circuit in DocumentQaController::ask()
        // actually prevents a wasted/costly Anthropic call — not just that
        // the response happens to look right.
        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldNotReceive('answerDocumentQuestion');
        });

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/documents/query', ['question' => 'What was the Q3 budget?']);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'No answer.',
            'data' => [
                'confidence' => 'none',
                'cited_document_ids' => [],
            ],
        ]);
    }

    public function test_cross_workspace_documents_never_retrieved(): void
    {
        [$userA, $workspaceA] = $this->createOrgUser('Viewer');
        [, $workspaceB] = $this->createOrgUser('Viewer');

        $foreignDoc = $this->createDocInWorkspace($workspaceB->id, 'Workspace B Secret.pdf', 'Public');

        $this->mockEmbeddingClient();
        // Embedding lives under workspace B only — userA's workspace_id
        // filter (the outer boundary DocumentContextRetriever's own
        // comment insists on) must exclude this row entirely, regardless
        // of vector similarity.
        $this->insertEmbeddingRow($workspaceB->id, $foreignDoc->id, 'Workspace B confidential figures.');

        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldNotReceive('answerDocumentQuestion');
        });

        Sanctum::actingAs($userA);
        $response = $this->postJson('/api/documents/query', ['question' => 'What are the confidential figures?']);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'No answer.',
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString($foreignDoc->id, $body);
        $this->assertStringNotContainsString('Workspace B Secret', $body);
    }

    public function test_ai_provider_failure_returns_generic_message_no_leak(): void
    {
        [$user, $workspace] = $this->createOrgUser('Viewer');
        $doc = $this->createDocInWorkspace($workspace->id);

        $this->mockEmbeddingClient();
        $this->insertEmbeddingRow($workspace->id, $doc->id);

        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldReceive('answerDocumentQuestion')
                ->once()
                ->andThrow(new \RuntimeException('Anthropic API request failed with status 529.'));
        });

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/documents/query', ['question' => 'What is in this document?']);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Unable to answer this question right now.',
            ]);

        // The real exception message (status code, provider detail) must
        // never reach the client — same discipline as the
        // EmbeddingProviderException -> 503 mapping in bootstrap/app.php.
        $body = $response->getContent();
        $this->assertStringNotContainsString('529', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }
}
