<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'sizeKb' => $this->size_kb,
            'status' => $this->status,
            'classification' => $this->classification,
            'year' => $this->year,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'uploadedBy' => $this->uploader?->full_name,
            'lastUpdatedBy' => $this->lastUpdater?->full_name,
            'lastUpdatedAt' => $this->updated_at?->toIso8601String(),
            'pages' => $this->pages,
            'pageFlags' => PageFlagResource::collection($this->whenLoaded('pageFlags')),
            'hasStructuredData' => $this->has_structured_data,
            'powerBIStatus' => $this->power_bi_status,
            'kpis' => KpiResource::collection($this->whenLoaded('kpis')),
            'charts' => ChartResource::collection($this->whenLoaded('charts')),
            'insights' => $this->insights ?? [],
            'progress' => $this->progress,
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