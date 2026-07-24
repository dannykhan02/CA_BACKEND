<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\IndexDocumentsRequest;
use App\Http\Resources\DocumentListResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController extends Controller
{
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

        $query = Document::query()
            ->with(['uploader:id,full_name', 'lastUpdater:id,full_name']);

        $allowedClassifications = DocumentPolicy::allowedClassificationsFor($request->user());
        $query->whereIn('classification', $allowedClassifications);

        if (! empty($validated['q'])) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($validated['q']) . '%']);
        }

        if (! empty($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        if (! empty($validated['classification'])) {
            $requestedAllowed = array_intersect($validated['classification'], $allowedClassifications);
            $query->whereIn('classification', $requestedAllowed ?: ['__none__']);
        }

        if (! empty($validated['year'])) {
            $query->where('year', $validated['year']);
        }

        if (! empty($validated['author'])) {
            $author = $validated['author'];
            $query->whereHas('uploader', function ($q) use ($author) {
                $q->whereRaw('LOWER(full_name) LIKE ?', ['%' . mb_strtolower($author) . '%']);
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