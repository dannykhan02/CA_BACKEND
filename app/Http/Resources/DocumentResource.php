<?php

namespace App\Http\Resources;

use App\Models\DocumentEvidence;
use App\Services\DocumentIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource = app(DocumentIntelligenceService::class)->publishedView($this->resource);
        // Mutation endpoints return this resource without eager loading. Keep the
        // wire contract identical to show() while preserving existing results.
        $this->resource->loadMissing(['pageFlags', 'kpis']);
        // Eloquent builds eager-load relations on a blank model, which has no
        // revision pointer. Query charts from the pinned document instance.
        $this->resource->setRelation('charts', $this->resource->charts()->get());
        $request->attributes->set('chart_published_document', $this->resource);
        $key = $this->ai_pipeline['key'] ?? null;
        $kpiSourceIds = $this->kpis->map(fn ($kpi) => 'kpi:'.$kpi->id)->all();
        $kpiEvidence = is_string($key) && $kpiSourceIds !== []
            ? DocumentEvidence::where('workspace_id', $this->workspace_id)->where('document_id', $this->id)
                ->where('pipeline_key', $key)->whereIn('source_id', $kpiSourceIds)
                ->whereIn('kind', ['metric', 'normal_source'])->get()->keyBy('source_id')
            : collect();
        $request->attributes->set('kpi_evidence', $kpiEvidence);

        return [
            'id' => $this->id,
            'intelligenceRevisionId' => $this->published_intelligence_revision_id,
            'intelligenceRefreshInProgress' => $this->active_intelligence_revision_id !== null,
            'intelligenceProcessingStatus' => $this->getAttribute('intelligence_processing_status') ?? $this->status,
            'intelligenceProcessingError' => $this->getAttribute('intelligence_processing_error'),
            'name' => $this->name,
            'type' => $this->type,
            'sizeKb' => $this->size_kb,
            'status' => $this->status,
            'processingFailure' => app(DocumentIntelligenceService::class)->getDocumentFailure($this->resource),
            'classification' => $this->classification,
            'year' => $this->year,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'uploadedBy' => $this->uploader?->full_name,
            'lastUpdatedBy' => $this->lastUpdater?->full_name,
            'lastUpdatedAt' => $this->updated_at?->toIso8601String(),
            'pages' => $this->pages,
            'pageFlags' => PageFlagResource::collection($this->pageFlags),
            'hasStructuredData' => $this->has_structured_data,
            'powerBIStatus' => $this->power_bi_status,
            'kpis' => KpiResource::collection($this->kpis),
            'charts' => ChartResource::collection($this->charts),
            'insights' => $this->insights ?? [],
            'progress' => $this->progress,
            // Structured stage (key, label, optional step/total). Null once processing ends.
            'progressStage' => $this->status === 'Processing' ? ($this->ai_pipeline['progress_stage'] ?? null) : null,
            'errorMessage' => $this->error_message,
            // Single source of truth for these four actions — mirrors
            // DocumentPolicy exactly, including its Personal-workspace
            // ownership bypass, so the frontend never has to reconstruct
            // that rule (or risk it drifting out of sync) itself.
            'canApprove' => $request->user() ? Gate::forUser($request->user())->allows('approve', $this->resource) : false,
            'canReject' => $request->user() ? Gate::forUser($request->user())->allows('reject', $this->resource) : false,
            'canReprocess' => $request->user() ? Gate::forUser($request->user())->allows('reprocess', $this->resource) : false,
            'canDelete' => $request->user() ? Gate::forUser($request->user())->allows('delete', $this->resource) : false,
        ];
    }
}
