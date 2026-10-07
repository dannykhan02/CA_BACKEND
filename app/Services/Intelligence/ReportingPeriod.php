<?php

namespace App\Services\Intelligence;

/**
 * A reporting period DocIntel could read with certainty, reduced to what ordering a time series
 * needs: how precise it is, whether it is a calendar or a fiscal period, and a sortable key.
 *
 * Granularity and basis are both part of comparability. Quarters are not plotted against years,
 * and FY2024 is not plotted against calendar 2024, because neither comparison is one the document
 * actually made.
 */
readonly class ReportingPeriod
{
    public function __construct(
        public string $granularity,
        public string $basis,
        public float $sortKey,
        public string $label,
    ) {}

    public function comparableWith(self $other): bool
    {
        return $this->granularity === $other->granularity && $this->basis === $other->basis;
    }
}
