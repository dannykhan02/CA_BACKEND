<?php

namespace App\Http\Resources;

use App\Services\DocumentIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Composite read-only response. Reuses DocumentTypeClassificationResource/
 * DocumentEntityResource/DocumentRiskResource/DocumentDeadlineResource/
 * DocumentIntelligenceSummaryResource for consistency with the dedicated
 * single-purpose endpoints — same field shapes everywhere, not a second
 * formatting convention.
 */
class DocumentIntelligenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(DocumentIntelligenceService::class);

        return [
            'document' => [
                'id' => $this->id,
                'name' => $this->name,
                'type' => $this->type,
                'status' => $this->status,
            ],
            'documentType' => $this->whenLoaded(
                'documentTypeClassification',
                fn () => $this->documentTypeClassification
                    ? new DocumentTypeClassificationResource($this->documentTypeClassification)
                    : null
            ),
            'summary' => [
                'entities' => $this->whenLoaded('entities', fn () => $this->entities->count(), 0),
                'risks' => $this->whenLoaded('risks', fn () => $this->risks->count(), 0),
                'deadlines' => $this->whenLoaded('deadlines', fn () => $this->deadlines->count(), 0),
            ],
            'entities' => DocumentEntityResource::collection($this->whenLoaded('entities')),
            'risks' => DocumentRiskResource::collection($this->whenLoaded('risks')),
            'deadlines' => DocumentDeadlineResource::collection($this->whenLoaded('deadlines')),
            'intelligenceSummary' => $this->whenLoaded(
                'intelligenceSummary',
                fn () => $this->intelligenceSummary
                    ? new DocumentIntelligenceSummaryResource($this->intelligenceSummary)
                    : null
            ),
            'processing' => $service->getProcessingStatus($this->resource),
        ];
    }
}
