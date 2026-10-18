<?php

namespace App\Services\Intelligence;

/**
 * One accepted metric finding, read for charting. Carries the measurement, the period it belongs
 * to, the dimension it was measured on, and - always - the reference the rest of the product
 * already uses to reach its source evidence (`kpi:<id>`, the same `source_id` synthesis cites).
 */
readonly class MetricObservation
{
    public function __construct(
        public string $sourceId,
        public string $label,
        public string $rawValue,
        public Measurement $measurement,
        public ?ReportingPeriod $period,
        public string $subject,
        public float $confidence,
        public ?string $definitionId,
        public string $conceptKey,
        public string $measureKey,
        public ?int $page,
        public bool $isTotal,
        /** Numeric parsing never proves the source or the meaning of a unit. */
        public array $integrity = [],
        public string $semanticConcept = '',
        public string $valueBasis = '',
        public string $quantityKind = '',
        public string $aggregation = '',
        public string $sourceContext = '',
    ) {}

    /** Charts group on concept and measurement together; neither alone is enough. */
    public function groupKey(): string
    {
        return $this->conceptKey.'|'.$this->measureKey;
    }
}
