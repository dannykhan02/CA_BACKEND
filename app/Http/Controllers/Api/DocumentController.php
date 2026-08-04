<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\IndexDocumentsRequest;
use App\Http\Resources\DocumentListResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Support\EscapesLikeWildcards;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController extends Controller
{
    use EscapesLikeWildcards;

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    private const SORT_COLUMN_MAP = [
        'uploaded_at' => 'created_at',
        'name' => 'name',
        'size_kb' => 'size_kb',
        'year' => 'year',
        'status' => 'status',
        'pages' => 'pages',
    ];

    public function index(IndexDocumentsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $user = $request->user();

        // workspace_id is the outer filter, applied before any classification
        // logic — the one line that stops a Personal-workspace user from
        // seeing every other Personal user's Public/Internal documents
        // system-wide (every signup defaults to a role whose allow-list is
        // ['Public', 'Internal'] with no workspace boundary otherwise), and
        // that stops an Organization Administrator from ever seeing another
        // Organization's Restricted documents just because their role matches.
        $query = Document::query()
            ->with(['uploader:id,full_name', 'lastUpdater:id,full_name'])
            ->where('workspace_id', $user->current_workspace_id);

        $isPersonal = $user->currentWorkspace?->type === WorkspaceType::Personal;
        $allowedClassifications = $isPersonal ? null : DocumentPolicy::allowedClassificationsFor($user);

        if (! $isPersonal) {
            $query->whereIn('classification', $allowedClassifications);
        }

        if (! empty($validated['q'])) {
            $term = $this->escapeLike(mb_strtolower($validated['q']));
            $query->whereRaw(
                "LOWER(name) LIKE ? ESCAPE '" . $this->likeEscapeChar() . "'",
                ['%' . $term . '%']
            );
        }

        if (! empty($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        if (! empty($validated['classification'])) {
            if ($isPersonal) {
                // Owner can see every classification in their own workspace —
                // no allow-list to intersect against.
                $query->whereIn('classification', $validated['classification']);
            } else {
                $requestedAllowed = array_intersect($validated['classification'], $allowedClassifications);
                $query->whereIn('classification', $requestedAllowed ?: ['__none__']);
            }
        }

        if (! empty($validated['year'])) {
            $query->where('year', $validated['year']);
        }

        if (! empty($validated['author'])) {
            $author = $this->escapeLike(mb_strtolower($validated['author']));
            $escapeChar = $this->likeEscapeChar();
            $query->whereHas('uploader', function ($q) use ($author, $escapeChar) {
                $q->whereRaw("LOWER(full_name) LIKE ? ESCAPE '{$escapeChar}'", ['%' . $author . '%']);
            });
        }

        $sortByParam = $validated['sort_by'] ?? 'uploaded_at';
        $sortColumn = self::SORT_COLUMN_MAP[$sortByParam] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDir);

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $documents = $query->paginate($perPage)->withQueryString();

        return DocumentListResource::collection($documents);
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        $document->load(['kpis', 'charts', 'pageFlags', 'uploader:id,full_name', 'lastUpdater:id,full_name']);

        return new DocumentResource($document);
    }
}