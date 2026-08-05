<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        // workspace_id as the outer filter — same non-negotiable pattern as
        // every other query in this app. Cosine distance (<=>) via pgvector;
        // 1 - distance gives a 0..1 similarity score that's more intuitive
        // for a frontend to display than raw distance.
        $rows = DB::select('
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
            ORDER BY de.embedding <=> ?
            LIMIT ?
        ', [$queryVector, $user->current_workspace_id, $queryVector, $limit]);

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
