<?php

namespace App\Http\Resources;

use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\SourceChartAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->source_metadata;
        $source = is_array($source) && ($source['origin'] ?? null) === 'document_visual' ? $source : null;
        $document = $source ? ($request->attributes->get('chart_published_document')
            ?? app(DocumentIntelligenceService::class)->publishedView($this->document)) : null;
        $image = $document && app(SourceChartAccess::class)->path($document, $this->resource)
            ? route('documents.charts.image', ['document' => $document->id, 'chart' => $this->id], false) : null;

        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'data' => $this->data,
            'sourcePage' => $source['page'] ?? null,
            'sourceImageUrl' => $image,
            'sourceImageMediaType' => $image ? $source['media_type'] : null,
            'sourceOrigin' => $source['origin'] ?? null,
            'valueStatus' => $source['value_status'] ?? 'unavailable',
            'dimensionsVerified' => $source['dimensions_verified'] ?? false,
            'valuesVerified' => $source['values_verified'] ?? false,
            'basis' => $source['basis'] ?? null,
            'axis' => $source['axis'] ?? null,
            'series' => $source['series'] ?? null,
            'valueFormat' => isset($source['unit']) ? ['unit' => $source['unit']] : null,
        ];
    }
}
