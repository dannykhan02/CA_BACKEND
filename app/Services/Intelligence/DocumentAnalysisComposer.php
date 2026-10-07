<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use Illuminate\Support\Collection;

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
    ) {}

    /** @return array<string,mixed> */
    public function compose(Document $document): array
    {
        $document->loadMissing(['risks', 'deadlines', 'intelligenceSummary']);
        $summary = $document->intelligenceSummary;
        $evidence = $this->evidence($document);
        $cited = $this->findings->citedSourceIds($summary);

        $collected = $this->metrics->collect($document);
        $derived = $this->charts->build($collected['observations'], $cited);
        $charts = array_slice($derived['candidates'], 0, self::MAX_CHARTS);
        $takeaways = $this->takeaways->build($document, $summary, $charts);
        $notes = $this->takeaways->notes($summary, $takeaways);
        $groups = $this->groups->build($evidence);

        $shown = [];
        foreach ($charts as $chart) {
            $shown = [...$shown, ...$chart['sourceIds']];
        }
        foreach ($takeaways as $takeaway) {
            $shown = [...$shown, ...$takeaway['sourceIds']];
        }
        $important = $this->findings->build($evidence, $summary, array_values(array_unique($shown)));

        return [
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
