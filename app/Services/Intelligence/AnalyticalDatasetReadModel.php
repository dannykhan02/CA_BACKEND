<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Adds reusable data to accepted chart groupings without another collector, planner or query. */
class AnalyticalDatasetReadModel
{
    private const MAX_DATASETS = 12;

    private const MAX_POINTS = 24;

    public function __construct(private SourceChartContract $sourceCharts) {}

    /** @param list<MetricObservation> $observations @param list<array<string,mixed>> $candidates */
    public function build(Document $document, array $observations, array $candidates, Collection $evidence): array
    {
        $revision = $document->intelligenceRevisionForRelations();
        if (($document->published_intelligence_revision_id !== null
                && $revision !== $document->published_intelligence_revision_id)
            || ($document->published_intelligence_revision_id === null
                && (int) $document->intelligence_generation > 0)) {
            return ['items' => [], 'diagnostics' => ['eligible' => 0, 'returned' => 0,
                'omittedByLimit' => 0, 'unsupported' => ['unpublished_revision' => 1]]];
        }

        $bySource = [];
        foreach ($observations as $observation) {
            if (($observation->integrity['analyticalEligible'] ?? false) === true) {
                $bySource[$observation->sourceId] = $observation;
            }
        }
        $pdfCandidates = [];
        foreach ($evidence as $row) {
            if ($row instanceof DocumentEvidence && ($row->data['source_candidate'] ?? false) === true) {
                $pdfCandidates[$row->source_id] = true;
            }
        }

        $items = [];
        $unsupported = [];
        foreach ($candidates as $candidate) {
            [$dataset, $reason] = $this->fromCandidate($document, $candidate, $bySource, $pdfCandidates);
            if ($dataset === null) {
                $unsupported[$reason] = ($unsupported[$reason] ?? 0) + 1;
            } else {
                $items[$dataset->id] = $dataset;
            }
        }
        $ordered = array_values($items);
        $count = count($ordered);
        ksort($unsupported);

        return ['items' => array_map(static fn (AnalyticalDataset $item) => $item->toArray(),
            array_slice($ordered, 0, self::MAX_DATASETS)),
            'diagnostics' => ['eligible' => $count, 'returned' => min($count, self::MAX_DATASETS),
                'omittedByLimit' => max(0, $count - self::MAX_DATASETS), 'unsupported' => $unsupported]];
    }

    private function fromCandidate(Document $document, array $candidate, array $bySource,
        array $pdfCandidates): array
    {
        $series = $candidate['series'] ?? [];
        if (count($series) > 1) {
            return [null, 'multiple_series_unsupported'];
        }
        $points = $series !== [] ? ($series[0]['data'] ?? []) : ($candidate['points'] ?? []);
        if (count($points) < 2 || count($points) > self::MAX_POINTS) {
            return [null, 'point_count_unsupported'];
        }
        if (! in_array($candidate['basis'] ?? null, ['time_series', 'categorical', 'composition'], true)) {
            return [null, 'dimension_unsupported'];
        }
        $sourceIds = [];
        $observations = [];
        foreach ($points as $point) {
            $ids = $point['sourceIds'] ?? [];
            if (count($ids) !== 1 || ! isset($bySource[$ids[0]])) {
                return [null, 'source_observation_unavailable'];
            }
            if (isset($pdfCandidates[$ids[0]])) {
                // PDF artifacts have stronger read-time anchor proof than a metric observation.
                // Keep their existing ChartResource path authoritative until that proof is reused.
                return [null, 'pdf_artifact_proof_required'];
            }
            $sourceIds[] = $ids[0];
            $observations[] = $bySource[$ids[0]];
        }
        $first = $observations[0];
        if (count(array_unique(array_map(static fn (MetricObservation $item) => $item->groupKey(), $observations))) !== 1
            || count(array_unique($sourceIds)) !== count($sourceIds)) {
            return [null, 'group_or_source_conflict'];
        }
        $time = $candidate['basis'] === 'time_series';
        if ($time && (count(array_unique(array_map(static fn (MetricObservation $item) => ($item->period?->granularity ?? '').'/'.($item->period?->basis ?? ''), $observations))) !== 1
            || in_array(null, array_map(static fn (MetricObservation $item) => $item->period, $observations), true))) {
            return [null, 'incomparable_periods'];
        }
        if (! $time && count(array_unique(array_map(static fn (MetricObservation $item) => $item->period?->label ?? '', $observations))) !== 1) {
            return [null, 'incomparable_periods'];
        }

        $rows = [];
        $safe = true;
        foreach ($points as $index => $point) {
            $observation = $observations[$index];
            $exact = $observation->measurement->exactMagnitude;
            if ($exact === null) {
                return [null, 'exact_decimal_unavailable'];
            }
            $display = $this->safeCoordinate($exact, (float) $candidate['scale']);
            $safe = $safe && $display !== null;
            $rows[] = [
                'label' => (string) $point['label'],
                'exactValue' => $exact,
                'reportedValue' => $observation->rawValue,
                'reportedUnit' => $observation->measurement->unitText,
                'reportedScale' => $observation->measurement->scale,
                'displayValue' => $display,
                'period' => $observation->period?->label,
                'granularity' => $observation->period?->granularity,
                'periodBasis' => $observation->period?->basis,
                'category' => $time ? null : (string) $point['label'],
                'valueStatus' => $this->valueStatus($observation),
                'sourceIds' => [$observation->sourceId],
                'evidenceAssurance' => 'source_verified',
                'numericVerification' => $observation->integrity['numericVerification'],
                'unitVerification' => $observation->integrity['unitVerification'],
                'derivation' => 'reported',
            ];
        }

        $labels = array_column($rows, 'label');
        $continuous = $time && $this->sourceCharts->continuousPeriods($labels);
        $views = [];
        if ($safe) {
            if ($continuous) {
                $views[] = ['id' => 'line', 'type' => 'line', 'orientation' => null];
            }
            $views[] = ['id' => $time ? 'column' : 'bar', 'type' => 'bar',
                'orientation' => $time ? 'vertical' : 'horizontal'];
        }
        $views[] = ['id' => 'table', 'type' => 'table', 'orientation' => null];
        $reasons = [];
        if (! $safe) {
            $reasons[] = 'numeric_conversion_unsafe';
        }
        if ($time && ! $continuous) {
            $reasons[] = 'non_contiguous_periods';
        }
        sort($sourceIds);
        $pipeline = $document->ai_pipeline['key'] ?? null;
        $revision = $document->intelligenceRevisionForRelations();
        $scope = $time && $first->subject !== '' ? $first->subject
            : ($first->integrity['governingOwner'] ?? null);
        $identity = [$document->workspace_id, $document->id, $revision, $pipeline,
            $first->groupKey(), $time ? 'time' : 'category',
            $scope, array_column($rows, 'period'), $sourceIds];
        $id = 'dataset-'.substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 32);

        return [new AnalyticalDataset($id, $candidate['id'], $document->workspace_id,
            $document->id, $revision, $pipeline, hash('sha256', (string) $document->extracted_text),
            $first->groupKey(), $candidate['title'], $time ? 'time' : 'category', $scope,
            $first->valueBasis, $first->aggregation, $first->quantityKind,
            $candidate['unit'], count(array_unique(array_column($rows, 'unitVerification'))) === 1
                ? $rows[0]['unitVerification'] : 'unavailable',
            $candidate['currency'], (float) $candidate['scale'],
            $rows, $views, $reasons), null];
    }

    private function valueStatus(MetricObservation $observation): string
    {
        foreach ([$observation->metricType, $observation->valueBasis] as $field) {
            $status = mb_strtolower(trim($field));
            if (in_array($status, ['actual', 'forecast', 'projected', 'planned', 'target',
                'budget', 'budgeted', 'estimated', 'amended', 'revised'], true)) {
                return $status;
            }
        }

        return 'unspecified';
    }

    /** Conservative chart-coordinate conversion; the exact decimal remains the source value. */
    private function safeCoordinate(string $exact, float $scale): ?float
    {
        if (! is_finite($scale) || $scale < 1) {
            return null;
        }
        $base = BigDecimal::of($exact);
        if ($base->isGreaterThan('9007199254740991') || $base->isLessThan('-9007199254740991')) {
            return null;
        }
        $scaleText = (string) (int) $scale;
        $coordinate = $base->dividedBy($scaleText,
            $base->getScale() + strlen($scaleText) - 1, RoundingMode::Unnecessary);
        $significant = ltrim(str_replace('.', '', ltrim((string) $coordinate, '-')), '0');
        $significant = rtrim($significant, '0');
        if (strlen($significant) > 15) {
            return null;
        }
        $number = (float) (string) $coordinate;

        return is_finite($number) && ($number !== 0.0 || $coordinate->isZero()) ? $number : null;
    }
}
