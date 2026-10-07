<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Services\Kpis\KpiIdentityProfile;
use App\Services\Kpis\KpiLabelNormalizer;

/**
 * Reads the metric findings a document already has and turns the chartable ones into
 * MetricObservations. Nothing new is persisted and nothing is re-extracted: a document processed
 * months ago yields the same observations as one processed today.
 *
 * Two stores hold metrics, and both are read:
 *
 *  - `document_evidence` (kind `metric`) is the richer one. It carries the measured subject, the
 *    period, the measurement metadata and the resolved source spans, so it is preferred.
 *  - `document_kpis` is written by both pipeline routes, so a document from the legacy four-job
 *    route - which has no evidence rows at all - is still chartable from its KPI observations.
 *
 * Grouping identity comes from KpiIdentityProfile, the workspace's existing KPI normalization:
 * its `concept` and `qualifiers` already fold "Total financing" and "Total financing approved"
 * together without fuzzy matching, and its scope/basis signals keep actuals apart from targets.
 * The profile's own identity_key is deliberately *not* used as the group key, because it folds the
 * written unit - and therefore the written scale - into identity, which would split
 * "USD million" from "USD billion" inside one series. Scale is handled by MeasurementParser
 * instead, which is the one place allowed to normalize it.
 */
class MetricCollector
{
    /** Subjects that name the whole rather than a part of it. */
    private const TOTAL_SUBJECTS = ['', 'total', 'overall', 'all', 'group', 'consolidated', 'aggregate', 'company', 'entity'];

    public function __construct(
        private MeasurementParser $measurements,
        private PeriodParser $periods,
        private KpiIdentityProfile $profiles,
        private KpiLabelNormalizer $normalizer,
    ) {}

    /**
     * @return array{observations: list<MetricObservation>, stats: array<string,int>}
     */
    public function collect(Document $document): array
    {
        $stats = ['metric_findings' => 0, 'chartable' => 0, 'unreadable_value' => 0, 'no_period' => 0];
        $observations = [];
        foreach ($this->records($document) as $record) {
            $stats['metric_findings']++;
            $observation = $this->observe($record);
            if ($observation === null) {
                $stats['unreadable_value']++;

                continue;
            }
            $stats['chartable']++;
            if ($observation->period === null) {
                $stats['no_period']++;
            }
            $observations[] = $observation;
        }

        return ['observations' => $observations, 'stats' => $stats];
    }

    /**
     * Metric findings in a uniform shape. Evidence rows win when present: a document with evidence
     * has a KPI row per evidence row, so reading both would double every observation.
     *
     * @return list<array<string,mixed>>
     */
    private function records(Document $document): array
    {
        $records = [];
        $key = $document->ai_pipeline['key'] ?? null;
        if ($key !== null) {
            $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)->where('pipeline_key', $key)
                ->where('kind', 'metric')->orderBy('identity')->get();
            foreach ($rows as $row) {
                $data = is_array($row->data) ? $row->data : [];
                $pages = array_values(array_unique(array_filter(array_column($row->sources ?? [], 'page'), fn ($page) => $page !== null)));
                $records[] = [
                    'source_id' => $row->source_id ?: 'evidence:'.$row->id,
                    'label' => (string) ($data['label'] ?? ''),
                    'value' => (string) ($data['value'] ?? ''),
                    'unit' => $data['unit'] ?? null,
                    'period' => $data['period'] ?? null,
                    'subject' => (string) ($data['subject'] ?? ''),
                    'confidence' => (float) ($data['confidence'] ?? 0),
                    'definition_id' => null,
                    'metadata' => [
                        'scope' => $data['subject'] ?? null,
                        'metric_type' => $data['metric_type'] ?? null,
                        'value_basis' => $data['value_basis'] ?? null,
                        'aggregation' => $data['aggregation'] ?? null,
                        'quantity_kind' => $data['quantity_kind'] ?? null,
                        'period' => $data['period'] ?? null,
                    ],
                    'page' => count($pages) === 1 ? (int) reset($pages) : null,
                    'numeric' => null,
                ];
            }
        }
        if ($records !== []) {
            return $this->withDefinitionIds($document, $records);
        }

        foreach (DocumentKpi::where('document_id', $document->id)
            ->where(fn ($query) => $query->where('workspace_id', $document->workspace_id)->orWhereNull('workspace_id'))
            ->orderBy('id')->get() as $kpi) {
            $metadata = is_array($kpi->identity_metadata) ? $kpi->identity_metadata : [];
            $records[] = [
                'source_id' => 'kpi:'.$kpi->id,
                'label' => (string) $kpi->label,
                'value' => (string) $kpi->value,
                'unit' => $kpi->unit,
                'period' => $kpi->period,
                'subject' => (string) ($metadata['scope'] ?? ''),
                // Legacy KPI rows carry no per-observation confidence.
                'confidence' => 0.8,
                'definition_id' => $kpi->kpi_definition_id,
                'metadata' => $metadata + ['period' => $kpi->period],
                'page' => null,
                'numeric' => $kpi->value_numeric === null ? null : (float) $kpi->value_numeric,
            ];
        }

        return $records;
    }

    /**
     * Evidence rows link to their KPI observation through `source_id`, which is where the
     * workspace's canonical KPI identity lives. One query, so an identity that already exists is
     * reused rather than recomputed.
     *
     * @param  list<array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function withDefinitionIds(Document $document, array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            if (str_starts_with($record['source_id'], 'kpi:')) {
                $ids[] = (int) substr($record['source_id'], 4);
            }
        }
        if ($ids === []) {
            return $records;
        }
        $definitions = DocumentKpi::where('document_id', $document->id)->whereIn('id', $ids)
            ->pluck('kpi_definition_id', 'id');
        foreach ($records as $index => $record) {
            if (str_starts_with($record['source_id'], 'kpi:')) {
                $records[$index]['definition_id'] = $definitions[(int) substr($record['source_id'], 4)] ?? null;
            }
        }

        return $records;
    }

    /** @param array<string,mixed> $record */
    private function observe(array $record): ?MetricObservation
    {
        $label = trim((string) $record['label']);
        if ($label === '') {
            return null;
        }
        $measurement = $this->measurements->parse($record['value'], $record['unit'], $label);
        if ($measurement === null) {
            // A KPI row whose numeric form was already parsed stays chartable when the raw text is
            // no longer readable on its own, which is how older documents keep their charts.
            if ($record['numeric'] === null) {
                return null;
            }
            $measurement = new Measurement((float) $record['numeric'], 'unknown', null,
                $this->normalizer->normalize((string) ($record['unit'] ?? '')), 1.0, (string) ($record['unit'] ?? ''));
        }

        $period = $this->periods->parse($record['period']);
        $profile = $this->profiles->make([
            'label' => $this->withoutPeriod($label, $record['period']),
            'unit' => $record['unit'],
            'value' => $record['value'],
            'identity' => is_array($record['metadata']) ? $record['metadata'] : [],
        ]);
        $subject = trim((string) $record['subject']);

        return new MetricObservation(
            sourceId: (string) $record['source_id'],
            label: $label,
            rawValue: (string) $record['value'],
            measurement: $measurement,
            period: $period,
            subject: $subject,
            confidence: max(0.0, min(1.0, (float) $record['confidence'])),
            definitionId: $record['definition_id'] === null ? null : (string) $record['definition_id'],
            conceptKey: $this->conceptKey($record, $profile),
            measureKey: $this->measureKey($measurement, $profile, $record['definition_id'] !== null),
            page: $record['page'] === null ? null : (int) $record['page'],
            isTotal: in_array($this->normalizer->normalize($subject), self::TOTAL_SUBJECTS, true),
        );
    }

    /**
     * Which metric this is. A workspace KPI identity is authoritative when one exists; otherwise
     * the profile's concept and its unexplained qualifiers identify the metric, which is what
     * makes a non-KPI metric chartable at all.
     *
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $profile
     */
    private function conceptKey(array $record, array $profile): string
    {
        if ($record['definition_id'] !== null) {
            return 'definition:'.$record['definition_id'];
        }
        $concept = (string) ($profile['concept'] ?? '');
        if ($concept === '') {
            $concept = $this->normalizer->normalize((string) $record['label']);
        }
        $qualifiers = $profile['qualifiers'] ?? [];
        sort($qualifiers);

        return 'concept:'.hash('sha256', json_encode([$concept, $qualifiers], JSON_THROW_ON_ERROR));
    }

    /**
     * What was measured and on what basis. Two observations may only share a chart when this is
     * identical, which is what keeps currencies, percentages, counts, actuals and targets apart.
     *
     * The physical measurement is always part of it. The label-derived basis signals are not when
     * the workspace already resolved both observations to one canonical KPI: that resolution
     * considered the same signals and more, so re-applying them here would split a KPI whose
     * aliases simply word it differently ("Total financing", "Financing approvals").
     *
     * @param  array<string,mixed>  $profile
     */
    private function measureKey(Measurement $measurement, array $profile, bool $canonical): string
    {
        return hash('sha256', json_encode([
            $measurement->kind, $measurement->currency, $measurement->family,
            $canonical ? null : ($profile['value_basis'] ?? null),
            $canonical ? null : ($profile['quantity_kind'] ?? null),
            $canonical ? null : ($profile['aggregation'] ?? null),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Removes the period from the label before identity is computed, so "Total financing 2023" and
     * "Total financing 2024" are one metric observed twice rather than two metrics. Only the
     * document's own stated period is removed, and only where it appears as a whole word.
     */
    private function withoutPeriod(string $label, ?string $period): string
    {
        $period = trim((string) $period);
        if ($period === '' || mb_strlen($period) < 4) {
            return $label;
        }
        $stripped = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($period, '/').'(?![\p{L}\p{N}])/iu', ' ', $label);
        $stripped = trim((string) preg_replace('/\s+/u', ' ', (string) $stripped));
        $stripped = trim($stripped, ' -–—:,()[]');

        return $stripped === '' ? $label : $stripped;
    }
}
