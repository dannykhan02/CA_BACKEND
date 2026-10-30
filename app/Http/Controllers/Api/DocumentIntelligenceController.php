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
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\PublishedIntelligenceProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * No AI calls or job dispatch. Status reads may reconcile expired
 * processing_jobs attempts. Reuses the existing 'view' policy gate used by
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

    /** Read-only, bounded continuation of the published visual collections. */
    public function visuals(Request $request, Document $document, DocumentAnalysisComposer $composer): JsonResponse
    {
        $this->authorize('view', $document);
        $input = $request->validate([
            'family' => ['required', 'in:charts,datasets,kpis,comparisons,timeline'],
            'offset' => ['required', 'integer', 'min:0'],
            'revision' => ['required', 'string', 'max:100'],
        ]);
        $document = $this->service->loadIntelligence($document);
        $revision = $document->published_intelligence_revision_id ?? 'legacy';
        if ($input['revision'] !== $revision) {
            return response()->json(['message' => 'The published intelligence revision changed. Refresh this document.'], 409);
        }
        if (! in_array($document->status, ['Ready', 'Needs Review'], true)
            || ($document->published_intelligence_revision_id === null
                && (int) $document->intelligence_generation > 0)) {
            return response()->json(['message' => 'Published visual intelligence is unavailable.'], 409);
        }

        $family = $input['family'];
        $offset = (int) $input['offset'];
        $published = app(PublishedIntelligenceProjection::class)->read($document);
        $analysis = $composer->compose($document, null, $family, $offset, $published['metrics'], $published['evidenceRecords']);
        if ($family === 'charts') {
            $items = $analysis['visualAnalysis']['charts'];
            $eligible = $analysis['visualAnalysis']['diagnostics']['candidateVisualizations'];
            $renderableEligible = $analysis['visualAnalysis']['diagnostics']['renderableCandidates'];
            $unsupported = $analysis['visualAnalysis']['rejected'];
        } elseif ($family === 'datasets') {
            $items = $analysis['analyticalDatasets']['items'];
            $eligible = $analysis['analyticalDatasets']['diagnostics']['eligible'];
            $renderableEligible = null;
            $unsupported = $analysis['analyticalDatasets']['diagnostics']['unsupported'];
        } else {
            $items = $analysis['visualSummaries'][$family];
            $eligible = $analysis['visualSummaries']['diagnostics'][match ($family) {
                'kpis' => 'eligibleKpis', 'comparisons' => 'eligibleComparisons',
                'timeline' => 'eligibleEvents',
            }];
            $renderableEligible = null;
            $unsupported = $analysis['visualSummaries']['diagnostics']['unsupported'];
        }
        // Publication can complete while a long continuation is being assembled.
        // Return a conflict instead of mixing that old page into a new client revision.
        $current = Document::whereKey($document->id)->where('workspace_id', $document->workspace_id)
            ->whereNull('deleted_at')->first(['published_intelligence_revision_id']);
        if ($current === null || ($current->published_intelligence_revision_id ?? 'legacy') !== $revision) {
            return response()->json(['message' => 'The published intelligence revision changed. Refresh this document.'], 409);
        }

        return response()->json(['documentId' => $document->id,
            'workspaceId' => $document->workspace_id, 'intelligenceRevisionId' => $revision,
            'pipelineKey' => $document->ai_pipeline['key'] ?? null,
            'sourceRevisionHash' => hash('sha256', (string) $document->extracted_text),
            'family' => $family, 'offset' => $offset, 'returned' => count($items),
            'eligible' => $eligible, 'remaining' => max(0, $eligible - $offset - count($items)),
            'renderableEligible' => $renderableEligible,
            'unsupported' => $unsupported, 'items' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function entities(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $document = $this->service->publishedView($document);

        return DocumentEntityResource::collection(
            $document->entities()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        )->additional(['intelligenceRevisionId' => $document->published_intelligence_revision_id]);
    }

    public function risks(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $document = $this->service->publishedView($document);

        return DocumentRiskResource::collection(
            $document->risks()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        )->additional(['intelligenceRevisionId' => $document->published_intelligence_revision_id]);
    }

    public function deadlines(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $document = $this->service->publishedView($document);

        return DocumentDeadlineResource::collection(
            $document->deadlines()->orderByDesc('confidence')->paginate($perPage)->withQueryString()
        )->additional(['intelligenceRevisionId' => $document->published_intelligence_revision_id]);
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

        $document = $this->service->loadIntelligence($document);

        return response()->json([
            'intelligenceRevisionId' => $document->published_intelligence_revision_id,
            'data' => $document->intelligenceSummary
                ? new DocumentIntelligenceSummaryResource($document->intelligenceSummary)
                : null,
        ]);
    }
}
