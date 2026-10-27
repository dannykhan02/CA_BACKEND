<?php

namespace App\Services\Intelligence;

/** One immutable, revision-owned set of reported measurements and its allowed local views. */
readonly class AnalyticalDataset
{
    public function __construct(
        public string $id,
        public string $candidateId,
        public string $workspaceId,
        public string $documentId,
        public ?string $intelligenceRevisionId,
        public ?string $pipelineKey,
        public string $sourceRevisionHash,
        public string $measureKey,
        public string $title,
        public string $dimension,
        public ?string $scope,
        public string $measurementBasis,
        public string $aggregation,
        public string $quantityKind,
        public string $unit,
        public string $unitVerification,
        public ?string $currency,
        public float $displayScale,
        public array $points,
        public array $views,
        public array $unsupportedReasons,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'candidateId' => $this->candidateId,
            'workspaceId' => $this->workspaceId, 'documentId' => $this->documentId,
            'intelligenceRevisionId' => $this->intelligenceRevisionId,
            'pipelineKey' => $this->pipelineKey, 'measureKey' => $this->measureKey,
            'sourceRevisionHash' => $this->sourceRevisionHash,
            'title' => $this->title, 'dimension' => $this->dimension,
            'scope' => $this->scope, 'measurementBasis' => $this->measurementBasis,
            'aggregation' => $this->aggregation, 'quantityKind' => $this->quantityKind,
            'unit' => $this->unit, 'unitVerification' => $this->unitVerification,
            'currency' => $this->currency, 'displayScale' => $this->displayScale,
            'points' => $this->points, 'views' => $this->views,
            'unsupportedReasons' => $this->unsupportedReasons,
        ];
    }
}
