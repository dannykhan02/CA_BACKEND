<?php

namespace App\Http\Resources;

use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\ModelChartStructure;
use App\Services\Intelligence\SourceChartAccess;
use App\Services\Intelligence\SourceChartContract;
use App\Services\Intelligence\VerifiedPdfChartReadModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->source_metadata;
        if (($metadata['origin'] ?? null) === 'verified_pdf_table') {
            $document = $request->attributes->get('chart_published_document')
                ?? app(DocumentIntelligenceService::class)->publishedView($this->document);
            $reader = $request->attributes->get('verified_pdf_chart_reader');
            if (! $reader instanceof VerifiedPdfChartReadModel) {
                $reader = app(VerifiedPdfChartReadModel::class);
                $request->attributes->set('verified_pdf_chart_reader', $reader);
            }
            $verified = $reader->candidate($document, $this->resource);
            $valid = $verified !== null;
            $strategy = $metadata['verification_strategy'] ?? 'financial.v1';
            $points = is_array($this->data) ? $this->data : [];
            $ids = array_values(array_unique(array_merge(...array_map(
                static fn ($point) => $point['sourceIds'] ?? [], $points))));
            $unit = $verified['unit'] ?? null;
            if ($valid && $strategy === 'categorical.v1') {
                $scaleLabel = match ($verified['scale']) {
                    1000 => 'thousand', 1000000 => 'million', 1000000000 => 'billion',
                    default => null,
                };
                if ($scaleLabel !== null) {
                    $unit .= ' '.$scaleLabel;
                }
            }

            return [
                'id' => (string) $this->id, 'logicalChartId' => $metadata['candidate_id'] ?? null,
                'type' => $this->type, 'title' => $this->title,
                'description' => $this->description, 'data' => $valid ? $points : [],
                'sourceIds' => $valid ? $ids : [],
                'sourcePage' => $valid ? ($points[0]['source']['page'] ?? null) : null,
                'sourceImageUrl' => null, 'sourceImageMediaType' => null,
                'sourceOrigin' => 'verified_pdf_table',
                'evidenceStatus' => $valid ? 'source_verified' : 'unavailable',
                'evidenceLabel' => $valid ? 'Source-verified' : null,
                'verificationStrategy' => $strategy,
                'valueStatus' => $valid ? 'exact' : 'unavailable',
                'dimensionsVerified' => $valid, 'valuesVerified' => $valid,
                'basis' => $strategy === 'categorical.v1' ? 'categorical' : 'time_series',
                'axis' => null, 'series' => null,
                'valueFormat' => $valid ? ['unit' => $unit,
                    'kind' => $verified['unitKind'], 'scale' => $verified['scale'],
                    'sourceUnit' => $verified['sourceUnit'] ?? null,
                    'unitVerification' => 'verified'] : null,
                'sourceRevision' => $this->intelligence_revision_id,
                'recommendedType' => $this->type, 'renderEligible' => $valid,
                'eligibilityReason' => $valid ? ($strategy === 'categorical.v1'
                    ? 'Current source-verified category and value cells'
                    : 'Current source-verified PDF table cells') : null,
                'unavailabilityReason' => $valid ? null
                    : 'PDF table source proof is unavailable for the current document revision.',
                'exportSupport' => ['csv' => $valid, 'png' => $valid,
                    'word' => $valid, 'originalImage' => false],
            ];
        }
        $origin = is_array($metadata) && in_array($metadata['origin'] ?? null,
            ['document_visual', 'model_unverified'], true) ? $metadata['origin'] : null;
        $modelStructure = $origin === 'model_unverified'
            ? app(ModelChartStructure::class)->inspect((string) $this->type, $this->data,
                (string) $this->title, (string) $this->description, is_array($metadata) ? $metadata : []) : null;
        $source = $origin === 'document_visual' ? $metadata : null;
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
        if ($origin === 'model_unverified') {
            $renderEligible = $modelStructure['eligible'];
        }
        $unavailabilityReason = $renderEligible ? null : ($source === null
            ? ($origin === 'model_unverified'
                ? $modelStructure['reason']
                : 'The origin of this historical chart is unavailable.')
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
            'sourceOrigin' => $origin,
            'evidenceStatus' => $origin === 'model_unverified' ? 'ai_interpreted'
                : ($origin === 'document_visual' ? 'image_transcribed' : 'unavailable'),
            'evidenceLabel' => $origin === 'model_unverified'
                ? 'AI-interpreted · Not independently verified' : null,
            'valueStatus' => $source['value_status'] ?? 'unavailable',
            'dimensionsVerified' => $source['dimensions_verified'] ?? false,
            'valuesVerified' => $source['values_verified'] ?? false,
            'basis' => $source['basis'] ?? null,
            'axis' => $source['axis'] ?? null,
            'series' => $source['series'] ?? null,
            'valueFormat' => isset($source['unit']) ? ['unit' => $source['unit']]
                : ($origin === 'model_unverified' && $modelStructure['unit'] !== null
                    ? ['unit' => $modelStructure['unit'], 'unitVerification' => 'unit_unverified'] : null),
            'sourceRevision' => $this->intelligence_revision_id,
            'recommendedType' => $this->type,
            'renderEligible' => $renderEligible,
            'eligibilityReason' => $renderEligible ? ($origin === 'model_unverified'
                ? 'Model chart passed structural rendering checks; source relationships remain unverified.'
                : 'Retained source image, exact printed values and verified dimensions') : null,
            'unavailabilityReason' => $unavailabilityReason,
            'exportSupport' => ['csv' => $origin === 'model_unverified' ? false
                : (($source['values_verified'] ?? false) && ($source['value_status'] ?? null) === 'exact'),
                'png' => $origin === 'model_unverified' ? false : $renderEligible,
                'word' => $source !== null, 'originalImage' => $image !== null],
        ];
    }
}
