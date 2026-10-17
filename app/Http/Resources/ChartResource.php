<?php

namespace App\Http\Resources;

use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\SourceChartAccess;
use App\Services\Intelligence\SourceChartContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->source_metadata;
        $source = is_array($source) && ($source['origin'] ?? null) === 'document_visual' ? $source : null;
        if ($source !== null) {
            // Recheck old persisted metadata under the current deterministic policy. No chart is
            // promoted just because a previous version stored dimensions_verified=true.
            $source = app(SourceChartContract::class)->metadata([
                'type' => $this->type, 'basis' => $source['basis'] ?? null,
                'axis' => $source['axis'] ?? null, 'unit' => $source['unit'] ?? null,
                'valueStatus' => $source['value_status'] ?? 'unavailable',
                'data' => $this->data, 'series' => $source['series'] ?? null,
            ], $source);
        }
        $document = $source ? ($request->attributes->get('chart_published_document')
            ?? app(DocumentIntelligenceService::class)->publishedView($this->document)) : null;
        $image = $document && app(SourceChartAccess::class)->path($document, $this->resource)
            ? route('documents.charts.image', ['document' => $document->id, 'chart' => $this->id], false) : null;
        $renderEligible = $source !== null && $image !== null && $source['dimensions_verified']
            && $source['values_verified'] && $source['value_status'] === 'exact';
        $unavailabilityReason = $renderEligible ? null : ($source === null
            ? 'The origin of this historical chart is unavailable.'
            : ($image === null ? 'The original chart image is unavailable for this document revision.'
                : (! $source['values_verified'] ? 'The numeric values were not verified as exact printed labels.'
                    : ($source['unit'] === null ? 'The measurement unit is unavailable.'
                        : 'The chart dimensions, periods or composition could not be verified.'))));

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
            'sourceRevision' => $this->intelligence_revision_id,
            'recommendedType' => $this->type,
            'renderEligible' => $renderEligible,
            'eligibilityReason' => $renderEligible ? 'Retained source image, exact printed values and verified dimensions' : null,
            'unavailabilityReason' => $unavailabilityReason,
            'exportSupport' => ['csv' => ($source['values_verified'] ?? false) && ($source['value_status'] ?? null) === 'exact',
                'png' => $renderEligible,
                'word' => true, 'originalImage' => $image !== null],
        ];
    }
}
