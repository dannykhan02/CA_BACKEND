<?php

namespace App\Services\AI;

use App\DataObjects\DocumentContext;
use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Support\Facades\DB;

/**
 * Day 9 Batch 2 — single responsibility: given a query + authenticated
 * user, return the relevant chunks and document metadata needed to answer
 * a question, with authorization enforced INSIDE this service, not left to
 * the controller.
 *
 * Workspace scope is deliberately derived from $user->current_workspace_id,
 * never accepted as a separate parameter — a caller-supplied workspace ID
 * independent of the authenticated user is exactly the class of bug
 * DocumentPolicy::view() was already patched to prevent (see its own
 * comment on the outer-tenant-boundary fix). The vector search query
 * mirrors DocumentSearchController::search() exactly — same outer
 * workspace_id filter, same inner Personal-ownership vs.
 * Organization-classification branch, same allowedClassificationsFor()
 * source of truth — so this can never authorize something a direct search
 * or document view would not.
 */
class DocumentContextRetriever
{
    public function __construct(
        private VoyageEmbeddingClient $embeddingClient,
    ) {}

    public function retrieve(string $query, User $user, int $topK = 5): DocumentContext
    {
        $workspace = $user->currentWorkspace;
        $isPersonal = $workspace?->type === WorkspaceType::Personal;

        $result = $this->embeddingClient->embed([$query], 'query');
        $queryVector = '[' . implode(',', $result['embeddings'][0]) . ']';

        // Audit finding (MEDIUM) — same missing relevance floor as
        // DocumentSearchController; previously always handed Claude
        // whatever chunk was nearest, however irrelevant. Test B/Test C
        // passed only because Claude's own judgment + ResponseValidator's
        // hallucination guard caught it — this adds a system-level floor
        // instead of relying entirely on the AI layer.
        $minSimilarity = config('document_processing.min_search_similarity');

        if ($isPersonal) {
            $rows = DB::select('
                SELECT * FROM (
                    SELECT
                        de.document_id,
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
            ', [$queryVector, $user->current_workspace_id, $user->id, $queryVector, $topK, $minSimilarity]);
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
                ", [$queryVector, $user->current_workspace_id, ...$allowedClassifications, $queryVector, $topK, $minSimilarity]);
            }
        }

        $documentIds = collect($rows)->pluck('document_id')->unique()->values()->all();

        if (empty($documentIds)) {
            return new DocumentContext([], [], [], [], []);
        }

        // Re-fetches by ID rather than trusting the vector search alone for
        // metadata — the WHERE clauses above already enforced the
        // authorization boundary, so this is a plain lookup, not a second
        // authorization check.
        $documents = Document::with(['documentTypeClassification', 'entities', 'risks', 'deadlines'])
            ->whereIn('id', $documentIds)
            ->get();

        $documentsMeta = $documents->map(fn (Document $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'document_type' => $d->documentTypeClassification?->document_type,
            'classification' => $d->classification,
        ])->all();

        $entities = $documents->flatMap(
            fn (Document $d) => $d->entities->map(fn ($e) => [
                'document_id' => $d->id, 'type' => $e->entity_type, 'value' => $e->value,
            ])
        )->all();

        $risks = $documents->flatMap(
            fn (Document $d) => $d->risks->map(fn ($r) => [
                'document_id' => $d->id, 'title' => $r->title, 'severity' => $r->severity, 'description' => $r->description,
            ])
        )->all();

        $deadlines = $documents->flatMap(
            fn (Document $d) => $d->deadlines->map(fn ($dl) => [
                'document_id' => $d->id, 'title' => $dl->title, 'date_type' => $dl->date_type,
                'due_date' => $dl->due_date?->toDateString(), 'relative_text' => $dl->relative_text,
            ])
        )->all();

        $relevantChunks = collect($rows)->map(fn ($row) => [
            'document_id' => $row->document_id,
            'text' => $row->chunk_text,
            'score' => round((float) $row->similarity, 4),
        ])->all();

        return new DocumentContext($documentsMeta, $entities, $risks, $deadlines, $relevantChunks);
    }
}
