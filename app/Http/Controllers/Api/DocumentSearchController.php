<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceType;
use App\Http\Controllers\Controller;
use App\Policies\DocumentPolicy;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentSearchController extends Controller
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function search(Request $request, VoyageEmbeddingClient $client): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $user = $request->user();
        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;

        $result = $client->embed([$validated['q']], 'query');
        $queryVector = '[' . implode(',', $result['embeddings'][0]) . ']';

        // workspace_id remains the outer filter (non-negotiable, unchanged).
        // Inner boundary mirrors DocumentPolicy::view() exactly:
        // - Personal workspace: visibility is ownership-only, classification
        //   is irrelevant (matches view()'s Personal branch).
        // - Organization workspace: classification must be in the caller's
        //   allowed list (matches view()'s MIN_ROLE_FOR_CLASSIFICATION
        //   branch via the policy's own allowedClassificationsFor()), so
        //   this can never drift from what GET /documents/{id} would allow.
        $workspace = $user->currentWorkspace;
        $isPersonal = $workspace?->type === WorkspaceType::Personal;

        // Audit finding (MEDIUM) — previously an unconditional top-N with no
        // relevance floor, so irrelevant queries still returned whatever was
        // nearest, however low the actual similarity. Wrapped in a subquery
        // since `similarity` is a computed alias and can't be referenced
        // directly in WHERE.
        $minSimilarity = config('document_processing.min_search_similarity');

        if ($isPersonal) {
            $rows = DB::select('
                SELECT * FROM (
                    SELECT
                        de.document_id,
                        d.name AS document_name,
                        d.classification,
                        de.chunk_text,
                        de.chunk_index,
                        1 - (de.embedding <=> ?) AS similarity
                    FROM document_embeddings de
                    JOIN documents d ON d.id = de.document_id
                    WHERE de.workspace_id = ?
                      AND d.uploaded_by = ?
                    ORDER BY de.embedding <=> ?
                    LIMIT ?
                ) matched
                WHERE matched.similarity >= ?
                ORDER BY matched.similarity DESC
            ', [$queryVector, $user->current_workspace_id, $user->id, $queryVector, $limit, $minSimilarity]);
        } else {
            $allowedClassifications = DocumentPolicy::allowedClassificationsFor($user);

            if (empty($allowedClassifications)) {
                $rows = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($allowedClassifications), '?'));
                $rows = DB::select("
                    SELECT * FROM (
                        SELECT
                            de.document_id,
                            d.name AS document_name,
                            d.classification,
                            de.chunk_text,
                            de.chunk_index,
                            1 - (de.embedding <=> ?) AS similarity
                        FROM document_embeddings de
                        JOIN documents d ON d.id = de.document_id
                        WHERE de.workspace_id = ?
                          AND d.classification IN ({$placeholders})
                        ORDER BY de.embedding <=> ?
                        LIMIT ?
                    ) matched
                    WHERE matched.similarity >= ?
                    ORDER BY matched.similarity DESC
                ", [$queryVector, $user->current_workspace_id, ...$allowedClassifications, $queryVector, $limit, $minSimilarity]);
            }
        }

        app(\App\Services\AuditLogger::class)->log(
            $user,
            'document.searched',
            null,
            ['query' => $validated['q'], 'result_count' => count($rows)],
            $user->current_workspace_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Search completed.',
            'data' => array_map(fn ($row) => [
                'document_id' => $row->document_id,
                'document_name' => $row->document_name,
                'classification' => $row->classification,
                'excerpt' => $row->chunk_text,
                'chunk_index' => $row->chunk_index,
                'similarity' => round((float) $row->similarity, 4),
            ], $rows),
        ]);
    }
}
