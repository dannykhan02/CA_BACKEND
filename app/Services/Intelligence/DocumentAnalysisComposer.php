<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Illuminate\Support\Collection;
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
        private ChartCandidateBuilder $charts,
        private TakeawayBuilder $takeaways,
        private AnalysisGrouper $groups,
        private ImportantFindingsBuilder $findings,
        private MaterialityReadModel $materialityRecords,
        private MaterialityScorer $materialityScorer,
        private CoverageStateBuilder $coverageStates,
        private HistoricalRiskRule $historicalRisks,
    ) {}

    /** @return array<string,mixed> */
    public function compose(Document $document, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable;
        $document->loadMissing(['risks', 'deadlines', 'intelligenceSummary']);
        $summary = $document->intelligenceSummary;
        $evidence = $this->evidence($document);
        $cited = $this->findings->citedSourceIds($summary);

        $collected = $this->metrics->collect($document);
        $derived = $this->charts->build($collected['observations'], $cited);
        $charts = array_slice($derived['candidates'], 0, self::MAX_CHARTS);
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
                Log::info('docintel.v2.brief_ai_rejected', [
                    'block_type' => $blockType, 'reason' => $reason, 'count' => $count,
                ]);
            }
        }
        $groups = $this->groups->build($evidence);

        $shown = [];
        foreach ($charts as $chart) {
            $shown = [...$shown, ...$chart['sourceIds']];
        }
        foreach ($takeaways as $takeaway) {
            $shown = [...$shown, ...$takeaway['sourceIds']];
        }
        $important = $this->findings->build($evidence, $summary, array_values(array_unique($shown)), $materiality);

        $analysis = [
            'overview' => ['takeaways' => $takeaways, 'summaryNotes' => $notes],
            'visualAnalysis' => [
                'charts' => $charts,
                'omitted' => max(0, count($derived['candidates']) - count($charts)),
                'rejected' => array_filter($derived['rejected']),
            ],
            'analysisGroups' => $groups,
            'importantFindings' => $important,
            'stats' => [
                'acceptedFindings' => $evidence->count(),
                'metricFindings' => $collected['stats']['metric_findings'],
                'chartableFindings' => $collected['stats']['chartable'],
                'chartCandidates' => count($derived['candidates']),
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
        foreach ($analysis['overview']['takeaways'] as $takeaway) {
            foreach ($takeaway['sourceIds'] as $id) {
                $ids[$id] = true;
            }
        }
        foreach ($analysis['analysisGroups'] as $group) {
            foreach ($group['items'] as $item) {
                $ids[$item['sourceId']] = true;
            }
        }
        foreach ($analysis['importantFindings'] as $finding) {
            $ids[$finding['sourceId']] = true;
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
            ->orderBy('identity')->get();
    }
}
