<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentDeadlineResource;
use App\Http\Resources\DocumentEntityResource;
use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\DocumentIntelligenceSummaryResource;
use App\Http\Resources\DocumentRiskResource;
use App\Models\Document;
use App\Services\DocumentIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Entirely read-only. No AI calls, no job dispatch, no writes to
 * entities/risks/deadlines/document_intelligence_summaries/document_ai_runs/
 * processing_jobs — reuses the existing 'view' policy gate used by
 * DocumentController::show(), so authorization/workspace isolation is not
 * reimplemented here.
 */
class DocumentIntelligenceController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    public function __construct(private DocumentIntelligenceService $service) {}

    public function show(Request $request, Document $document): DocumentIntelligenceResource
    {
        $this->authorize('view', $document);

        $document = $this->service->loadIntelligence($document);

        return new DocumentIntelligenceResource($document);
    }

    public function entities(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        return DocumentEntityResource::collection(
            $document->entities()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        );
    }

    public function risks(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        return DocumentRiskResource::collection(
            $document->risks()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        );
    }

    public function deadlines(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        return DocumentDeadlineResource::collection(
            $document->deadlines()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        );
    }

    /**
     * Returns 200 with data: null when no summary has been generated yet
     * (GenerateDocumentSummaryJob hasn't run), rather than 404 — the
     * document itself exists and is accessible, only the summary sub-
     * resource is absent, consistent with "empty results, not errors"
     * for partial/not-yet-run intelligence stages.
     */
    public function summary(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->loadMissing('intelligenceSummary');

        return response()->json([
            'data' => $document->intelligenceSummary
                ? new DocumentIntelligenceSummaryResource($document->intelligenceSummary)
                : null,
        ]);
    }
}
