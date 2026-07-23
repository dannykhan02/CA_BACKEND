<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        ];
    }
}
