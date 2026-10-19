<?php

namespace App\Services\Intelligence;

use App\Services\Kpis\KpiLabelNormalizer;
use Brick\Math\BigDecimal;

/** Deterministic answers from compatible, source-verified observations. */
class AnalyticalComparisonBuilder
{
    public function __construct(private KpiLabelNormalizer $normalizer,
        private SourceChartContract $sourceCharts) {}

    /** @param list<MetricObservation> $observations */
    public function build(array $observations): array
    {
        $eligible = array_values(array_filter($observations, fn ($observation) => ($observation->integrity['analyticalEligible'] ?? false) && $observation->period !== null
            && $observation->semanticConcept !== '' && $observation->quantityKind !== 'change'));
        $groups = [];
        foreach ($eligible as $observation) {
            $key = json_encode([$this->concept($observation), $this->normalizer->normalize($observation->subject),
                $observation->period->label, $observation->period->basis,
                $observation->measurement->family, $observation->measurement->currency,
                $observation->measurement->kind, $observation->aggregation]);
            $groups[$key][] = $observation;
        }
        $answers = [];
        foreach ($groups as $group) {
            $actual = array_values(array_filter($group, fn ($o) => $o->valueBasis === 'actual'
                && ! preg_match('/\b(?:proposed|proposal|forecast|projected|budgeted|target|goal|threshold)\b/iu', $o->sourceContext)));
            foreach (['target' => '/\b(?:target|goal|threshold)\b/iu',
                'budget' => '/\b(?:budget|budgeted)\b/iu'] as $basis => $pattern) {
                $reference = array_values(array_filter($group, fn ($o) => $o->valueBasis === $basis
                    && preg_match($pattern, $o->sourceContext)));
                if (count($actual) !== 1 || count($reference) !== 1
                    || ! $actual[0]->measurement->compatibleWith($reference[0]->measurement)) {
                    continue;
                }
                $answers[] = $this->difference($actual[0], $reference[0],
                    $basis === 'budget' ? 'actual_vs_budget' : 'actual_vs_target');
            }
        }

        // A period comparison uses the exact measure key and subject. Two values in one
        // quarter, mixed fiscal/calendar periods, or a gap in the sequence are never a trend.
        $series = [];
        foreach ($eligible as $observation) {
            if ($observation->valueBasis !== 'actual' || preg_match('/\b(?:proposed|forecast|target|budget)\b/iu', $observation->sourceContext)) {
                continue;
            }
            $key = $observation->groupKey().'|'.$this->normalizer->normalize($observation->subject);
            $series[$key][$observation->period->label][] = $observation;
        }
        foreach ($series as $periods) {
            if (count($periods) < 2 || count(array_filter($periods, fn ($items) => count($items) !== 1)) > 0) {
                continue;
            }
            $points = array_map(fn ($items) => $items[0], array_values($periods));
            usort($points, fn ($a, $b) => $a->period->sortKey <=> $b->period->sortKey);
            [$previous, $current] = array_slice($points, -2);
            if ($previous->period->granularity !== $current->period->granularity
                || $previous->period->basis !== $current->period->basis
                || ! $this->sourceCharts->continuousPeriods([$previous->period->label, $current->period->label])) {
                continue;
            }
            $answers[] = $this->difference($current, $previous, 'period_change');
        }

        foreach ($this->financialReconciliations($eligible) as $reconciliation) {
            $answers[] = $reconciliation;
        }

        return $answers;
    }

    /** A small financial interpretation profile; the arithmetic remains generic and exact. */
    private function financialReconciliations(array $observations): array
    {
        $groups = [];
        foreach ($observations as $item) {
            if ($item->valueBasis !== 'actual' || $item->measurement->kind !== 'currency'
                || preg_match('/\b(?:proposed|proposal|forecast|budget|target)\b/iu', $item->sourceContext)) {
                continue;
            }
            $concept = $this->concept($item);
            $role = match (true) {
                preg_match('/^(?:operating )?revenue$/u', $concept) === 1 => 'revenue',
                preg_match('/^operating costs?$/u', $concept) === 1 => 'costs',
                preg_match('/^operating profit$/u', $concept) === 1 => 'profit',
                default => null,
            };
            if ($role === null) {
                continue;
            }
            $key = json_encode([$item->period->label, $item->period->basis,
                $this->normalizer->normalize($item->subject), $item->measurement->currency]);
            $groups[$key][$role][] = $item;
        }
        $results = [];
        foreach ($groups as $roles) {
            if (count($roles['revenue'] ?? []) !== 1 || count($roles['costs'] ?? []) !== 1
                || count($roles['profit'] ?? []) !== 1) {
                continue;
            }
            [$revenue, $costs, $profit] = [$roles['revenue'][0], $roles['costs'][0], $roles['profit'][0]];
            if (! $revenue->measurement->compatibleWith($costs->measurement)
                || ! $revenue->measurement->compatibleWith($profit->measurement)) {
                continue;
            }
            if ($revenue->measurement->exactMagnitude === null
                || $costs->measurement->exactMagnitude === null
                || $profit->measurement->exactMagnitude === null) {
                continue;
            }
            $calculated = BigDecimal::of($revenue->measurement->exactMagnitude)
                ->minus($costs->measurement->exactMagnitude);
            if (! $calculated->isEqualTo($profit->measurement->exactMagnitude)) {
                continue;
            }
            $scale = $profit->measurement->scale;
            $results[] = ['id' => substr(hash('sha256', implode('|', [$revenue->sourceId,
                $costs->sourceId, $profit->sourceId])), 0, 20),
                'kind' => 'financial_reconciliation', 'title' => 'Operating profit check',
                'period' => $profit->period->label,
                'left' => ['label' => $revenue->label, 'rawValue' => $revenue->rawValue,
                    'rawUnit' => $revenue->measurement->unitText, 'sourceId' => $revenue->sourceId],
                'right' => ['label' => $costs->label, 'rawValue' => $costs->rawValue,
                    'rawUnit' => $costs->measurement->unitText, 'sourceId' => $costs->sourceId],
                'reported' => ['label' => $profit->label, 'rawValue' => $profit->rawValue,
                    'rawUnit' => $profit->measurement->unitText, 'sourceId' => $profit->sourceId],
                'gap' => round((float) (string) $calculated / $scale, 6), 'gapUnit' => $profit->measurement->unitText,
                'direction' => 'at', 'sourceIds' => [$revenue->sourceId, $costs->sourceId, $profit->sourceId],
                'verification' => 'source_verified', 'calculation' => 'revenue_minus_operating_costs_equals_operating_profit'];
        }

        return $results;
    }

    private function concept(MetricObservation $observation): string
    {
        return trim((string) preg_replace('/\b(?:actual|target|goal|threshold|budgeted|budget|forecast|projected)\b/u',
            ' ', $observation->semanticConcept));
    }

    private function difference(MetricObservation $left, MetricObservation $right, string $kind): array
    {
        $gapDecimal = $left->measurement->exactMagnitude !== null
            && $right->measurement->exactMagnitude !== null
            ? BigDecimal::of($left->measurement->exactMagnitude)
                ->minus($right->measurement->exactMagnitude) : null;
        $gap = $gapDecimal === null
            ? $left->measurement->magnitude - $right->measurement->magnitude
            : (float) (string) $gapDecimal;
        $percent = $left->measurement->kind === 'percent';
        $sameScale = $left->measurement->scale === $right->measurement->scale;
        $scale = $sameScale ? $left->measurement->scale : 1.0;
        $unit = $percent ? 'percentage points' : ($sameScale
            ? $left->measurement->unitText : $left->measurement->canonicalUnit);
        $title = in_array($kind, ['actual_vs_target', 'actual_vs_budget'], true)
            ? $this->concept($left).' against '.($kind === 'actual_vs_budget' ? 'budget' : 'target')
            : $this->concept($left).' since '.$right->period->label;

        return ['id' => substr(hash('sha256', $kind.'|'.$left->sourceId.'|'.$right->sourceId), 0, 20),
            'kind' => $kind, 'title' => ucfirst($title), 'period' => $left->period->label,
            'left' => ['label' => $left->label, 'rawValue' => $left->rawValue,
                'rawUnit' => $left->measurement->unitText, 'sourceId' => $left->sourceId],
            'right' => ['label' => $right->label, 'rawValue' => $right->rawValue,
                'rawUnit' => $right->measurement->unitText, 'sourceId' => $right->sourceId],
            'gap' => round($gap / $scale, 6), 'gapUnit' => $unit,
            'direction' => ($gapDecimal?->compareTo('0') ?? ($gap <=> 0)) > 0
                ? 'above' : (($gapDecimal?->compareTo('0') ?? ($gap <=> 0)) < 0 ? 'below' : 'at'),
            'sourceIds' => [$left->sourceId, $right->sourceId],
            'verification' => 'source_verified', 'calculation' => 'left_minus_right'];
    }
}
