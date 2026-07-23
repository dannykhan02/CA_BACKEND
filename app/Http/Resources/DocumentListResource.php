<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentListResource extends JsonResource
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
            'hasStructuredData' => $this->has_structured_data,
            'powerBIStatus' => $this->power_bi_status,
            'progress' => $this->progress,
            'errorMessage' => $this->error_message,
            'pageFlags' => [],
            'kpis' => [],
            'charts' => [],
            'insights' => [],
        ];
    }
}