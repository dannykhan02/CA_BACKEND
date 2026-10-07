<?php

namespace App\Services\Intelligence;

/**
 * One numeric observation reduced to the facts a chart needs: how much, measured in what, and
 * at which scale the document stated it.
 *
 * `magnitude` is always the value in the family's base unit (USD, not USD million), so
 * "9,800 USD million" and "12.4 USD billion" are directly comparable. `scale` only records how
 * the document wrote it, so presentation can pick one readable scale per series.
 *
 * `family` is the compatibility key: two observations may only share a chart when their family
 * is identical. It deliberately keeps a currency, a unit denominator and a measurement noun
 * apart ("usd", "usd per share", "tickets"), so percentages never meet currency, revenue never
 * meets headcount, and two ratios with different denominators never meet each other. Currencies
 * are never converted - a different currency is simply a different family.
 */
readonly class Measurement
{
    public function __construct(
        public float $magnitude,
        public string $kind,
        public ?string $currency,
        public string $family,
        public float $scale,
        public string $unitText,
    ) {}

    public function compatibleWith(self $other): bool
    {
        return $this->family === $other->family && $this->kind === $other->kind
            && $this->currency === $other->currency;
    }
}
