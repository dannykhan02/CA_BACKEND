<?php

namespace App\Services\Comparisons;

use App\Services\Intelligence\Measurement;
use App\Services\Intelligence\ReportingPeriod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Interpretation after the existing numeric identity, measurement and evidence gates. */
class FinancialPeriodDecision
{
    public const RULE = 'explicit-financial-period-m2b1-v1';

    public function decide(array $a, array $b, Measurement $ma, Measurement $mb, ReportingPeriod $pa, ReportingPeriod $pb): array
    {
        $ca = $a['context'];
        $cb = $b['context'];
        if ($ma->kind !== 'currency' || $ma->currency === null || $ma->currency === '$'
            || mb_strtolower($ma->family) !== mb_strtolower($ma->currency)) {
            return ['insufficient_evidence', 'unsupported_period_measurement'];
        }
        foreach (['concept_id', 'owner', 'subject', 'value_basis', 'aggregation', 'reporting_scope', 'accounting_basis', 'measurement_nature', 'metric_status'] as $field) {
            if (empty($ca[$field]) || empty($cb[$field]) || in_array($ca[$field], ['unknown', 'unspecified', 'ambiguous'], true)
                || in_array($cb[$field], ['unknown', 'unspecified', 'ambiguous'], true)) {
                return ['insufficient_evidence', 'unknown_'.$field];
            }
            if ($ca[$field] !== $cb[$field]) {
                return ['not_comparable', $field.'_mismatch'];
            }
        }
        if ($ca['metric_status'] !== 'actual' || ! in_array($ca['measurement_nature'], ['stock', 'flow'], true)) {
            return ['insufficient_evidence', 'unsupported_measurement_context'];
        }
        if (! in_array($ca['accounting_basis'], ['gaap', 'us gaap', 'ifrs', 'j-gaap', 'japanese gaap', 'uk gaap'], true)) {
            return ['insufficient_evidence', 'unsupported_accounting_basis'];
        }
        if ($pa->startDate === null || $pb->startDate === null || ! $pa->comparableWith($pb)) {
            return ['not_comparable', 'period_granularity_mismatch'];
        }
        $stock = $ca['measurement_nature'] === 'stock';
        if ($stock !== ($pa->granularity === 'point')) {
            return ['not_comparable', 'stock_flow_period_mismatch'];
        }
        foreach ([$a, $b] as $fact) {
            $source = $fact['source'];
            if (empty($source['intelligence_revision_id']) || empty($source['evidence_ids']) || empty($source['spans'])) {
                return ['insufficient_evidence', 'missing_period_provenance'];
            }
            $text = mb_strtolower(preg_replace('/\s+/u', ' ', implode(' ', array_column($source['spans'], 'quote'))));
            $period = mb_strtolower(preg_replace('/\s+/u', ' ', trim($fact['value']['period'])));
            if (! str_contains($text, $period)) {
                return ['insufficient_evidence', 'period_source_mismatch'];
            }
            $basis = preg_quote($fact['context']['accounting_basis'], '/');
            if (! preg_match('/(?<![\w-])'.$basis.'(?![\w-])/iu', $text)) {
                return ['insufficient_evidence', 'accounting_basis_source_mismatch'];
            }
        }
        $same = $pa->startDate === $pb->startDate && $pa->endDate === $pb->endDate;
        if (! $same && ! $stock) {
            // Equal fiscal boundaries across adjacent years, or adjacent full calendar quarters/months.
            $earlier = $pa->startDate < $pb->startDate ? $pa : $pb;
            $later = $earlier === $pa ? $pb : $pa;
            if ($earlier->endDate >= $later->startDate) {
                return ['not_comparable', 'overlapping_periods'];
            }
            $yearApart = (new \DateTimeImmutable($earlier->startDate))->modify('+1 year')->format('Y-m-d') === $later->startDate
                && substr($earlier->endDate, 5) === substr($later->endDate, 5);
            $adjacent = $pa->granularity !== 'year'
                && (new \DateTimeImmutable($earlier->endDate))->modify('+1 day')->format('Y-m-d') === $later->startDate;
            if (! $yearApart && ! $adjacent) {
                return ['not_comparable', 'period_boundaries_mismatch'];
            }
        }
        $av = BigDecimal::of($ma->exactMagnitude);
        $bv = BigDecimal::of($mb->exactMagnitude);
        $delta = $bv->minus($av);
        $relative = ! $same && $av->isGreaterThan(0) && $bv->isGreaterThanOrEqualTo(0)
            ? (string) $delta->multipliedBy(100)->dividedBy($av, 6, RoundingMode::HALF_UP) : null;
        $details = ['rule_id' => self::RULE, 'relationship' => $same ? 'same_period' : 'different_periods',
            'direction' => 'document_b_minus_document_a', 'measurement_nature' => $ca['measurement_nature'],
            'period_a' => ['original' => $a['value']['period'], 'start' => $pa->startDate, 'end' => $pa->endDate],
            'period_b' => ['original' => $b['value']['period'], 'start' => $pb->startDate, 'end' => $pb->endDate],
            'original_a' => $a['value'], 'original_b' => $b['value'],
            'canonical_unit' => $ma->currency, 'currency' => $ma->currency,
            'canonical_a' => (string) $av, 'canonical_b' => (string) $bv, 'absolute_difference' => (string) $delta,
            'relative_percent' => $relative, 'relative_rounding' => $relative === null ? null : '6 decimal places, half up',
            'limitations' => 'Reported observations only. No revision, contradiction, legal authority or independent factual verification is established.'
                .($relative === null ? ' Relative change is withheld for same periods, nonpositive baselines or negative target values.' : '')];

        return [$same ? ($delta->isZero() ? 'equivalent' : 'supported_change') : 'period_comparison',
            $same ? 'explicit_same_period_values' : 'explicit_period_performance', $details];
    }
}
