<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceDateRecognizer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/** Bounded, read-only visual specifications from already collected measurements and deadlines. */
class DocumentVisualSummaryReadModel
{
    private const MAX_KPIS = 4;

    private const MAX_COMPARISONS = 6;

    private const MAX_EVENTS = 24;

    /** @param list<MetricObservation> $observations @param list<array<string,mixed>> $comparisons
     * @param  list<string>  $rankedSourceIds  @param list<array<string,mixed>> $featuredCharts
     */
    public function build(Document $document, array $observations, array $comparisons,
        array $rankedSourceIds, array $featuredCharts, Collection $evidence): array
    {
        $revision = $document->intelligenceRevisionForRelations();
        if (($document->published_intelligence_revision_id !== null
                && $revision !== $document->published_intelligence_revision_id)
            || ($document->published_intelligence_revision_id === null
                && (int) $document->intelligence_generation > 0)) {
            return $this->response($document, [], [], [], ['unpublished_revision' => 1]);
        }

        $pdfCandidates = [];
        $evidenceBySource = [];
        foreach ($evidence as $row) {
            if ($row instanceof DocumentEvidence) {
                $evidenceBySource[$row->source_id] = $row;
                if (($row->data['source_candidate'] ?? false) === true) {
                    $pdfCandidates[$row->source_id] = true;
                }
            }
        }
        $featuredSources = [];
        foreach (array_slice($featuredCharts, 0, 6) as $chart) {
            foreach ($chart['sourceIds'] ?? [] as $sourceId) {
                $featuredSources[$sourceId] = true;
            }
        }
        $priority = array_flip($rankedSourceIds);
        $revisedSlots = [];
        foreach ($observations as $observation) {
            if (in_array($observation->valueStatus(), ['amended', 'revised'], true)) {
                $revisedSlots[$this->revisionSlot($observation)] = true;
            }
        }
        $groups = [];
        $unsupported = [];
        foreach ($observations as $observation) {
            if (isset($revisedSlots[$this->revisionSlot($observation)])) {
                $unsupported['revision_relationship_unproven'] = ($unsupported['revision_relationship_unproven'] ?? 0) + 1;

                continue;
            }
            if (! ($observation->integrity['analyticalEligible'] ?? false)
                || $observation->measurement->exactMagnitude === null
                || isset($pdfCandidates[$observation->sourceId])) {
                $unsupported['measurement_ineligible'] = ($unsupported['measurement_ineligible'] ?? 0) + 1;

                continue;
            }
            if ((! $observation->isTotal && ! isset($priority[$observation->sourceId]))
                || ($observation->period === null && trim($observation->subject) === '')) {
                $unsupported['kpi_context_or_relevance_missing'] = ($unsupported['kpi_context_or_relevance_missing'] ?? 0) + 1;

                continue;
            }
            $key = json_encode([$observation->groupKey(), mb_strtolower(trim($observation->subject)),
                $observation->metricType, $observation->valueBasis,
                $observation->measurement->currency, $observation->period?->basis], JSON_THROW_ON_ERROR);
            $groups[$key][$observation->period?->label ?? 'no_period'][] = $observation;
        }
        $kpis = [];
        foreach ($groups as $periods) {
            $valid = [];
            foreach ($periods as $samePeriod) {
                $first = $samePeriod[0];
                if (count(array_unique(array_map(static fn (MetricObservation $item) => (string) BigDecimal::of($item->measurement->exactMagnitude), $samePeriod))) > 1) {
                    $unsupported['conflicting_observations'] = ($unsupported['conflicting_observations'] ?? 0) + 1;

                    continue;
                }
                $valid[] = $first;
            }
            if ($valid === []) {
                continue;
            }
            usort($valid, static fn (MetricObservation $a, MetricObservation $b) => ($b->period?->sortKey ?? 0) <=> ($a->period?->sortKey ?? 0));
            $item = $valid[0];
            if (isset($featuredSources[$item->sourceId])) {
                $unsupported['already_featured_as_chart'] = ($unsupported['already_featured_as_chart'] ?? 0) + 1;

                continue;
            }
            $kpis[] = ['id' => $this->id($document, 'kpi', [$item->sourceId]),
                'title' => $item->label, 'reportedValue' => $item->rawValue,
                'exactValue' => $item->measurement->exactMagnitude,
                'reportedUnit' => $item->measurement->unitText,
                'canonicalUnit' => $item->measurement->canonicalUnit,
                'scale' => $item->measurement->scale,
                'period' => $item->period?->label, 'periodBasis' => $item->period?->basis,
                'scope' => $item->subject !== '' ? $item->subject : ($item->integrity['governingOwner'] ?? null),
                'valueStatus' => $item->valueStatus(),
                'derivation' => 'reported', 'sourceIds' => [$item->sourceId],
                'evidenceAssurance' => 'source_verified',
                '_priority' => $priority[$item->sourceId] ?? PHP_INT_MAX,
                '_period' => $item->period?->sortKey ?? 0];
        }
        usort($kpis, static fn (array $a, array $b) => [$a['_priority'], -$a['_period'], $a['id']]
            <=> [$b['_priority'], -$b['_period'], $b['id']]);
        $kpis = array_map(static function (array $item): array {
            unset($item['_priority'], $item['_period']);

            return $item;
        }, $kpis);

        $cards = [];
        foreach ($comparisons as $comparison) {
            if (! in_array($comparison['kind'] ?? null, ['actual_vs_target', 'actual_vs_budget', 'period_change'], true)
                || ($comparison['verification'] ?? null) !== 'source_verified'
                || ! is_string($comparison['exactGap'] ?? null)
                || ! is_string($comparison['exactGapUnit'] ?? null)
                || array_filter($comparison['sourceIds'] ?? [],
                    static fn (string $id): bool => isset($pdfCandidates[$id]))) {
                $unsupported['comparison_not_supported'] = ($unsupported['comparison_not_supported'] ?? 0) + 1;

                continue;
            }
            $cards[] = ['id' => $this->id($document, 'comparison', $comparison['sourceIds']),
                'kind' => $comparison['kind'], 'title' => $comparison['title'],
                'period' => $comparison['period'], 'left' => $comparison['left'],
                'right' => $comparison['right'], 'exactDifference' => $comparison['exactGap'],
                'differenceUnit' => $comparison['exactGapUnit'], 'direction' => $comparison['direction'],
                'sourceIds' => $comparison['sourceIds'], 'derivation' => 'left_minus_right',
                'evidenceAssurance' => 'source_verified'];
        }

        $events = [];
        $seen = [];
        foreach ($document->deadlines as $deadline) {
            if (! $deadline instanceof DocumentDeadline || $deadline->workspace_id !== $document->workspace_id
                || $deadline->document_id !== $document->id
                || $deadline->intelligence_revision_id !== $revision) {
                $unsupported['timeline_owner_mismatch'] = ($unsupported['timeline_owner_mismatch'] ?? 0) + 1;

                continue;
            }
            if ($deadline->date_type !== 'explicit' || $deadline->due_date === null) {
                $unsupported['timeline_date_not_explicit'] = ($unsupported['timeline_date_not_explicit'] ?? 0) + 1;

                continue;
            }
            $date = $deadline->due_date->toDateString();
            $evidenceText = trim((string) $deadline->evidence);
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date
                || $evidenceText === '' || ! $this->deadlineSourceCurrent($document, $deadline,
                    $evidenceBySource['deadline:'.$deadline->id] ?? null)
                || ! EvidenceDateRecognizer::statesDate($evidenceText, $parsedDate)) {
                $unsupported['timeline_source_date_unproven'] = ($unsupported['timeline_source_date_unproven'] ?? 0) + 1;

                continue;
            }
            $dedup = hash('sha256', implode('|', [$deadline->title, $date, $evidenceText,
                (string) $deadline->deadline_type]));
            if (isset($seen[$dedup])) {
                $unsupported['duplicate_timeline_event'] = ($unsupported['duplicate_timeline_event'] ?? 0) + 1;

                continue;
            }
            $seen[$dedup] = true;
            if ($deadline->status !== 'open') {
                // The existing writers set only open; other stored states lack source field proof.
                $unsupported['timeline_status_unproven'] = ($unsupported['timeline_status_unproven'] ?? 0) + 1;
            }
            $events[] = ['id' => $this->id($document, 'event', [(string) $deadline->id]),
                'title' => $deadline->title, 'date' => $date,
                'eventType' => $deadline->deadline_type ?: 'deadline',
                'status' => $deadline->status === 'open' ? 'open' : 'undocumented',
                'sourceIds' => ['deadline:'.$deadline->id],
                'evidenceAssurance' => 'source_reference'];
        }
        usort($events, static fn (array $a, array $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        ksort($unsupported);

        return $this->response($document, $kpis, $cards, $events, $unsupported);
    }

    private function deadlineSourceCurrent(Document $document, DocumentDeadline $deadline,
        ?DocumentEvidence $evidence): bool
    {
        $text = (string) $document->extracted_text;
        if ($evidence === null || ($evidence->sources ?? []) === []) {
            return str_contains($text, (string) $deadline->evidence);
        }
        $revision = $evidence->data['source_revision'] ?? null;
        if (! is_string($revision) || ! hash_equals(hash('sha256', $text), $revision)) {
            return false;
        }
        $quotes = [];
        foreach ($evidence->sources as $source) {
            if (! is_array($source) || ! is_int($source['start_offset'] ?? null)
                || ! is_int($source['end_offset'] ?? null)
                || $source['end_offset'] <= $source['start_offset']
                || ! is_string($source['quote'] ?? null)
                || mb_substr($text, $source['start_offset'],
                    $source['end_offset'] - $source['start_offset']) !== $source['quote']) {
                return false;
            }
            $quotes[] = $source['quote'];
        }

        return trim((string) $deadline->evidence) === implode("\n\n", $quotes);
    }

    private function id(Document $document, string $kind, array $sources): string
    {
        return $kind.'-'.substr(hash('sha256', json_encode([$document->workspace_id,
            $document->id, $document->intelligenceRevisionForRelations(),
            $document->ai_pipeline['key'] ?? null, $sources], JSON_THROW_ON_ERROR)), 0, 24);
    }

    private function revisionSlot(MetricObservation $item): string
    {
        $concept = mb_strtolower($item->semanticConcept ?: trim($item->label));
        $concept = trim((string) preg_replace('/\b(?:actual|amended|revised)\b/u', ' ', $concept));

        return json_encode([$concept,
            mb_strtolower(trim($item->subject)), $item->period?->label,
            $item->period?->basis, $item->measurement->family,
            $item->measurement->currency, $item->integrity['governingOwner'] ?? null],
            JSON_THROW_ON_ERROR);
    }

    private function response(Document $document, array $kpis, array $comparisons,
        array $events, array $unsupported): array
    {
        return ['owner' => ['workspaceId' => $document->workspace_id,
            'documentId' => $document->id,
            'intelligenceRevisionId' => $document->intelligenceRevisionForRelations(),
            'pipelineKey' => $document->ai_pipeline['key'] ?? null,
            'sourceRevisionHash' => hash('sha256', (string) $document->extracted_text)],
            'kpis' => array_slice($kpis, 0, self::MAX_KPIS),
            'comparisons' => array_slice($comparisons, 0, self::MAX_COMPARISONS),
            'timeline' => array_slice($events, 0, self::MAX_EVENTS),
            'diagnostics' => ['eligibleKpis' => count($kpis),
                'eligibleComparisons' => count($comparisons), 'eligibleEvents' => count($events),
                'omittedKpis' => max(0, count($kpis) - self::MAX_KPIS),
                'omittedComparisons' => max(0, count($comparisons) - self::MAX_COMPARISONS),
                'omittedEvents' => max(0, count($events) - self::MAX_EVENTS),
                'unsupported' => $unsupported]];
    }
}
