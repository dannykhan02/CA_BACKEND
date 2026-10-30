<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the derived, presentation-ready intelligence for one document from evidence that is
 * already stored. No provider request is made here, nothing is written, and no new column or table
 * is required, so a document processed before this layer existed produces the same analysis as one
 * processed after it.
 *
 * Every query is scoped to the document's own workspace and pipeline, and every reference emitted
 * is a `source_id` that belongs to this document, so a reference can never address evidence the
 * caller is not authorized to see.
 */
class DocumentAnalysisComposer
{
    /** Charts sent to the client. The page shows fewer and reveals the rest on request. */
    private const MAX_CHARTS = 12;

    public function __construct(
        private MetricCollector $metrics,
        private AnalyticalComparisonBuilder $comparisons,
        private ChartCandidateBuilder $charts,
        private AnalyticalDatasetReadModel $datasets,
        private DocumentVisualSummaryReadModel $visualSummaries,
        private SourceChartContract $sourceCharts,
        private TakeawayBuilder $takeaways,
        private AnalysisGrouper $groups,
        private ImportantFindingsBuilder $findings,
        private MaterialityReadModel $materialityRecords,
        private MaterialityScorer $materialityScorer,
        private CoverageStateBuilder $coverageStates,
        private HistoricalRiskRule $historicalRisks,
        private VerifiedPdfFindingPresenter $pdfFindings,
    ) {}

    /** @return array<string,mixed> */
    public function compose(Document $document, ?\DateTimeImmutable $asOf = null,
        ?string $visualPageFamily = null, int $visualPageOffset = 0, ?array $publishedMetrics = null, ?Collection $publishedEvidence = null): array
    {
        $asOf ??= new \DateTimeImmutable;
        $document->loadMissing(['risks', 'deadlines', 'intelligenceSummary']);
        $summary = $document->intelligenceSummary;
        $evidence = $publishedEvidence?->where('kind', '!=', 'normal_source') ?? $this->evidence($document);
        $cited = $this->findings->citedSourceIds($summary);

        $collected = $publishedMetrics ?? $this->metrics->collect($document);
        $pdfTables = config('document_intelligence.pdf_table_structure') && $document->type === 'PDF';
        $sourceCurrent = true;
        if ($pdfTables) {
            $live = DB::table('documents')->where('id', $document->id)
                ->where('workspace_id', $document->workspace_id)->whereNull('deleted_at')
                ->first(['file_hash', 'extracted_text']);
            $sourceCurrent = $live !== null && $live->file_hash === $document->file_hash
                && hash('sha256', (string) $live->extracted_text)
                    === hash('sha256', (string) $document->extracted_text);
            $sourceRevision = hash('sha256', (string) $document->extracted_text);
            foreach ($evidence as $row) {
                $data = $row->data ?? [];
                if (($data['source_candidate'] ?? false) === true
                    && (($data['source_file_hash'] ?? null) !== $document->file_hash
                        || ($data['source_revision'] ?? null) !== $sourceRevision)) {
                    $sourceCurrent = false;
                    break;
                }
            }
        }
        $presentation = $sourceCurrent
            ? $this->pdfFindings->reconcile($document, $evidence, $collected['observations'])
            : ['rows' => $evidence, 'equivalents' => [], 'hidden' => []];
        $eligible = array_values(array_filter($collected['observations'],
            fn (MetricObservation $observation) => $sourceCurrent && $observation->integrity['analyticalEligible']));
        $comparisons = $this->comparisons->build($eligible);
        $derived = $this->charts->build($eligible, $cited);
        // Preserve the builder's relevance order within each set, but do not let a
        // table-only candidate consume a slot ahead of a source-verified visual.
        $renderable = array_values(array_filter($derived['candidates'], $this->renderEligible(...)));
        $notRenderable = array_values(array_filter($derived['candidates'],
            fn (array $chart): bool => ! $this->renderEligible($chart)));
        $ranked = [...$renderable, ...$notRenderable];
        $datasets = $this->datasets->build($document, $eligible, $ranked, $evidence,
            $visualPageFamily === 'datasets' ? $visualPageOffset : 0);
        $limitedMeasurements = $collected['rejected'];
        foreach ($collected['observations'] as $observation) {
            if ($observation->integrity['analyticalEligible']) {
                continue;
            }
            $limitedMeasurements[] = ['sourceId' => $observation->sourceId,
                'codes' => array_values(array_unique($observation->integrity['reasons']))];
        }
        $chartPresenter = function (array $chart) use ($document): array {
            $renderEligible = $this->renderEligible($chart);
            $chart['origin'] = 'generated';
            $chart['logicalChartId'] = $chart['id'];
            $chart['verification'] = $chart['numericVerification'];
            $chart['recommendedType'] = $chart['type'];
            $chart['renderEligible'] = $renderEligible;
            $chart['eligibilityReason'] = ! $renderEligible ? null : match ($chart['basis']) {
                'time_series' => 'Comparable observations with ordered reporting periods',
                'composition' => 'Verified parts of one whole',
                default => 'Comparable categories measured in one period',
            };
            $chart['unavailabilityReason'] = $renderEligible ? null
                : ($chart['verification'] === 'stored_numeric_legacy'
                    ? 'Source text could not be parsed; values came from an older stored numeric field.'
                    : 'Reporting periods do not form one consecutive comparable sequence.');
            $chart['sourceRevision'] = $document->intelligenceRevisionForRelations();
            $chart['exportSupport'] = ['csv' => true, 'png' => $renderEligible, 'word' => true];

            return $chart;
        };
        $charts = array_map($chartPresenter, array_slice($ranked, 0, self::MAX_CHARTS));
        $pageCharts = $visualPageFamily === 'charts'
            ? array_map($chartPresenter, array_slice($ranked, $visualPageOffset, self::MAX_CHARTS))
            : $charts;
        $v2 = (bool) config('intelligence_v2.enabled');
        $materiality = null;
        $records = [];
        $coverage = null;
        $overflow = 0;
        if ($v2) {
            $read = $this->materialityRecords->build($document, $evidence, $derived['candidates'], $cited);
            $records = $read['records'];
            $materiality = $this->materialityScorer->assign($records, $read['context'], $asOf);
            $overflow = count(array_filter($materiality, fn ($item) => $item['overflow_from_forced']));
            $truncated = $overflow > 0 || count(array_filter($materiality,
                fn ($item) => $item['band_qualified'] && $item['tier'] !== 1)) > 0;
            $coverage = $this->coverageStates->build($document->ai_pipeline ?? [], $summary !== null, $truncated);
        }
        $materialityBySource = null;
        if ($v2) {
            $materialityBySource = [];
            foreach ($records as $record) {
                $materialityBySource[$record['source_id']] = $materiality[$record['identity']];
            }
        }
        $takeaways = $this->takeaways->build($document, $summary, $charts, $materialityBySource,
            $v2 ? ['coverage' => $coverage, 'records' => $records,
                'scope' => 'pipeline:'.($document->ai_pipeline['key'] ?? '')] : null);
        $notes = $this->takeaways->notes($summary, $takeaways);
        $negativeClaimRejections = $v2 ? $this->takeaways->negativeClaimRejections() : [];
        foreach ($v2 ? $this->takeaways->rejectionReasons() : [] as $blockType => $reasons) {
            foreach ($reasons as $reason => $count) {
                $event = $reason === 'negative_claim'
                    ? 'docintel.v2.negative_claim_rejected'
                    : 'docintel.v2.brief_ai_rejected';
                Log::info($event, [
                    'block_type' => $blockType, 'reason' => $reason, 'count' => $count,
                ]);
            }
        }
        $groups = $this->groups->build($presentation['rows']);
        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $item['equivalentSourceIds'] = $presentation['equivalents'][$item['sourceId']] ?? [];
            }
            unset($item);
        }
        unset($group);

        $shown = [];
        foreach ($charts as $chart) {
            $shown = [...$shown, ...$chart['sourceIds']];
        }
        foreach ($takeaways as $takeaway) {
            $shown = [...$shown, ...$takeaway['sourceIds']];
        }
        foreach ($presentation['equivalents'] as $preferred => $alternates) {
            if (in_array($preferred, $shown, true) || array_intersect($alternates, $shown)) {
                $shown = [...$shown, $preferred, ...$alternates];
            }
        }
        $important = $this->findings->build($presentation['rows'], $summary,
            array_values(array_unique($shown)), $materiality);
        foreach ($important as &$finding) {
            $finding['equivalentSourceIds'] = $presentation['equivalents'][$finding['sourceId']] ?? [];
        }
        unset($finding);
        $visualSummaries = $this->visualSummaries->build($document,
            $sourceCurrent ? $collected['observations'] : [], $comparisons,
            array_column($important, 'sourceId'), $charts, $evidence,
            $visualPageFamily, $visualPageOffset);

        $analysis = [
            'overview' => ['takeaways' => $takeaways, 'summaryNotes' => $notes],
            'visualAnalysis' => [
                'charts' => $pageCharts,
                'omitted' => max(0, count($ranked) - ($visualPageFamily === 'charts' ? $visualPageOffset : 0) - count($pageCharts)),
                'rejected' => array_filter($derived['rejected']),
                'diagnostics' => $this->visualDiagnostics($evidence, $collected, $eligible, $derived,
                    $renderable, $notRenderable, $charts, $sourceCurrent),
            ],
            'analysisGroups' => $groups,
            'analyticalComparisons' => $comparisons,
            'analyticalDatasets' => $datasets,
            'visualSummaries' => $visualSummaries,
            'limitations' => [
                'pdfFinancialTables' => $pdfTables
                    ? ['scope' => 'clean_native_text_duration_tables',
                        'completeDocumentAnalysis' => false,
                        'sourceRevisionCurrent' => $sourceCurrent,
                        'message' => $sourceCurrent
                            ? 'Financial comparisons cover only tables with independently verified dates, units, reporting scope and exact source cells. Other tables may remain unsupported.'
                            : 'Financial table comparisons are unavailable because the source document has changed. Process the current revision to restore source verification.']
                    : null,
                'measurementCount' => count($limitedMeasurements),
                'measurements' => array_slice($limitedMeasurements, 0, 100),
                'visualGroupCount' => count($derived['rejectedGroups']),
                'visualGroups' => array_slice($derived['rejectedGroups'], 0, 100),
                'coverage' => ['state' => $coverage['state'] ?? 'unknown',
                    'codes' => $coverage['reasons'] ?? ['coverage_not_measured']],
            ],
            'importantFindings' => $important,
            'stats' => [
                'acceptedFindings' => $evidence->count(),
                'presentedFindings' => $presentation['rows']->count(),
                'reconciledEquivalentFindings' => count($presentation['hidden']),
                'metricFindings' => $collected['stats']['metric_findings'],
                'chartableFindings' => $sourceCurrent ? $collected['stats']['chartable'] : 0,
                'chartCandidates' => count($derived['candidates']),
                'analyticalComparisons' => count($comparisons),
                'takeaways' => count($takeaways),
                'summaryNotes' => count($notes),
                'analysisGroups' => count($groups),
                'importantFindings' => count($important),
                'groundedSources' => $evidence->sum(fn (DocumentEvidence $row) => count($row->sources ?? [])),
            ],
        ];
        if ($v2) {
            $analysis['stats']['briefAiBlocksRejected'] = array_sum($negativeClaimRejections);
            $byId = collect($records)->keyBy('identity');
            $tier1Items = [];
            $attentionBuilder = new AttentionStateBuilder($this->historicalRisks, config('intelligence_v2.attention'));
            $attentionStates = [];
            foreach ($materiality as $identity => $assignment) {
                $record = $byId->get($identity);
                $attention = $attentionBuilder->build($record, $assignment, $records, $asOf);
                $attentionStates[] = $attention;
                if ($assignment['tier'] === 1) {
                    $tier1Items[] = ['record' => $record, 'assignment' => $assignment, 'attention' => $attention];
                }
            }
            usort($tier1Items, static function ($a, $b) {
                $forced = (int) $b['assignment']['forced'] <=> (int) $a['assignment']['forced'];
                if ($forced !== 0) {
                    return $forced;
                }
                $priority = $a['assignment']['forced_priority'] <=> $b['assignment']['forced_priority'];
                if ($priority !== 0) {
                    return $priority;
                }

                return $b['assignment']['score'] <=> $a['assignment']['score']
                    ?: MaterialityScorer::compareTiebreak($a['record'], $b['record'], $a['assignment'], $b['assignment']);
            });
            $tier1 = array_map(static fn ($item) => [
                'sourceId' => $item['record']['source_id'], 'kind' => $item['record']['kind'],
                'forced' => $item['assignment']['forced'], 'forcedRule' => $item['assignment']['forced_rule'],
                'tierReasons' => $item['assignment']['reasons'], 'attention' => $item['attention'],
            ], $tier1Items);
            $analysis['tier1'] = $tier1;
            $analysis['attention'] = $attentionBuilder->summary($attentionStates, $coverage, $overflow, $asOf);
        }

        return $analysis;
    }

    /**
     * References this response points at, so the composite intelligence response can send source
     * excerpts for exactly those and nothing else.
     *
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    public function referencedSourceIds(array $analysis): array
    {
        $ids = [];
        foreach ($analysis['visualAnalysis']['charts'] as $chart) {
            foreach ($chart['sourceIds'] as $id) {
                $ids[$id] = true;
            }
        }
        foreach ($analysis['analyticalComparisons'] ?? [] as $comparison) {
            foreach ($comparison['sourceIds'] as $id) {
                $ids[$id] = true;
            }
        }
        foreach (['kpis', 'comparisons', 'timeline'] as $family) {
            foreach ($analysis['visualSummaries'][$family] ?? [] as $visual) {
                foreach ($visual['sourceIds'] as $id) {
                    $ids[$id] = true;
                }
            }
        }
        foreach ($analysis['overview']['takeaways'] as $takeaway) {
            foreach ($takeaway['sourceIds'] as $id) {
                $ids[$id] = true;
            }
        }
        foreach ($analysis['analysisGroups'] as $group) {
            foreach ($group['items'] as $item) {
                $ids[$item['sourceId']] = true;
                foreach ($item['equivalentSourceIds'] ?? [] as $alternate) {
                    $ids[$alternate] = true;
                }
            }
        }
        foreach ($analysis['importantFindings'] as $finding) {
            $ids[$finding['sourceId']] = true;
            foreach ($finding['equivalentSourceIds'] ?? [] as $alternate) {
                $ids[$alternate] = true;
            }
        }
        foreach ($analysis['tier1'] ?? [] as $item) {
            $ids[$item['sourceId']] = true;
        }

        return array_keys($ids);
    }

    /** @return Collection<int,DocumentEvidence> */
    private function evidence(Document $document): Collection
    {
        $key = $document->ai_pipeline['key'] ?? null;
        if ($key === null) {
            return collect();
        }

        return DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $key)
            ->where('kind', '!=', 'normal_source')
            ->orderBy('identity')->get();
    }

    private function renderEligible(array $chart): bool
    {
        $labels = array_column($chart['series'][0]['data'] ?? $chart['points'], 'label');

        return $chart['numericVerification'] === 'parsed'
            && $chart['sourceVerification'] === 'verified'
            && in_array($chart['unitVerification'], ['verified', 'dimensionless'], true)
            && ($chart['basis'] !== 'time_series' || $this->sourceCharts->continuousPeriods($labels));
    }

    private function visualDiagnostics(Collection $evidence, array $collected, array $eligible, array $derived,
        array $renderable, array $notRenderable, array $charts, bool $sourceCurrent): array
    {
        $reasonCounts = [];
        foreach ($collected['rejected'] as $item) {
            foreach ($item['codes'] as $code) {
                $reasonCounts[$code] = ($reasonCounts[$code] ?? 0) + 1;
            }
        }
        foreach ($collected['observations'] as $observation) {
            if ($observation->integrity['analyticalEligible']) {
                continue;
            }
            foreach (array_unique($observation->integrity['reasons']) as $code) {
                $reasonCounts[$code] = ($reasonCounts[$code] ?? 0) + 1;
            }
        }
        if (! $sourceCurrent && $collected['stats']['chartable'] > 0) {
            $reasonCounts['source_revision_stale'] = $collected['stats']['chartable'];
        }
        ksort($reasonCounts);
        $sourceSets = [];
        $duplicates = 0;
        foreach ($derived['candidates'] as $candidate) {
            $ids = array_values(array_unique($candidate['sourceIds']));
            sort($ids);
            $key = $candidate['basis'].'|'.$candidate['type'].'|'.implode('|', $ids);
            if (isset($sourceSets[$key])) {
                $duplicates++;
            }
            $sourceSets[$key] = true;
        }

        return [
            'structuredEventFindings' => $evidence->filter(fn (DocumentEvidence $row) => in_array($row->kind, ['deadline', 'obligation'], true))->count(),
            'storedMetricFindings' => $collected['stats']['metric_findings'],
            'eligibleMeasurements' => count($eligible),
            'ineligibleMeasurements' => $collected['stats']['metric_findings'] - count($eligible),
            'ineligibleReasons' => $reasonCounts,
            'sourceRevisionCurrent' => $sourceCurrent,
            'metricGroups' => count(array_unique(array_map(fn (MetricObservation $item) => $item->groupKey(), $eligible))),
            'candidateVisualizations' => count($derived['candidates']),
            'duplicateSourceSetCandidates' => $duplicates,
            'unsupportedGroups' => count($derived['rejectedGroups']),
            'renderableCandidates' => count($renderable),
            'tableOnlyCandidates' => count($notRenderable),
            'returnedGeneratedVisualizations' => count($charts),
            'omittedByLimit' => max(0, count($derived['candidates']) - count($charts)),
            'renderableOmittedByLimit' => max(0, count($renderable) - self::MAX_CHARTS),
        ];
    }
}
