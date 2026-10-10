<?php

namespace App\Services\Intelligence\B2;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Brief\KeyFigureSelector;
use App\Services\Intelligence\CoverageStateBuilder;
use App\Services\Intelligence\ImportantFindingsBuilder;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Illuminate\Support\Collection;

/**
 * The one Stage A projection B2 is allowed to see: already-stored evidence, typed and scored by
 * the existing Stage A services, with nothing added and nothing reinterpreted.
 *
 * Deliberately a new class rather than an extension of DocumentAnalysisComposer, so Stage A and B1
 * stay byte-for-byte unchanged when B2 is off. It calls the same services in the same order the
 * composer does and reads the same rows, so the two cannot disagree about the data — only about
 * orchestration, which IntelligenceB2SnapshotTest pins against the composer's own tier-1 output.
 *
 * Read-only: no provider request, no write, no migration. Every id emitted is a `source_id` that
 * belongs to this document and workspace.
 */
class StageASnapshot
{
    public function __construct(
        private MaterialityReadModel $readModel,
        private MaterialityScorer $scorer,
        private CoverageStateBuilder $coverageStates,
        private HistoricalRiskRule $historical,
        private KeyFigureSelector $figures,
        private ImportantFindingsBuilder $findings,
    ) {}

    /**
     * @return array{records:list<array<string,mixed>>,assignments:array<string,array<string,mixed>>,
     *               attention:array<string,array<string,mixed>>,coverage:array<string,mixed>,
     *               key_figure_ids:list<string>,forced_overflow:int,as_of:string,
     *               document_name:string,document_type:string,pipeline_key:string|null,
     *               extraction_version:string|null}
     */
    /**
     * Within one request or one job the stored rows do not move, and both the API read path and the
     * synthesizer ask for the same snapshot. Memoized per instance (bound `scoped`, so a queue
     * worker starts each job with a fresh one) and keyed by the document's own timestamp, so an
     * updated document is never answered from an older projection.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $memo = [];

    public function build(Document $document, \DateTimeImmutable $asOf): array
    {
        $key = implode('|', [$document->id, $asOf->format(\DateTimeInterface::ATOM),
            (string) $document->updated_at?->getTimestampMs()]);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->project($document, $asOf);
    }

    /** @return array<string,mixed> */
    protected function project(Document $document, \DateTimeImmutable $asOf): array
    {
        $document->loadMissing(['risks', 'deadlines', 'intelligenceSummary']);
        $evidence = $this->evidence($document);
        $cited = $this->findings->citedSourceIds($document->intelligenceSummary);
        // Chart candidates only feed the scorer's comparability signal. B2 never renders a chart,
        // and building them here would pull a second large derived layer into the synthesis path,
        // so comparability is left unsupplied rather than guessed at.
        $read = $this->readModel->build($document, $evidence, [], $cited);
        $records = $read['records'];
        $assignments = $this->scorer->assign($records, $read['context'], $asOf);
        $overflow = count(array_filter($assignments, fn ($item) => $item['overflow_from_forced']));
        $truncated = $overflow > 0 || count(array_filter($assignments,
            fn ($item) => $item['band_qualified'] && $item['tier'] !== 1)) > 0;
        $coverage = $this->coverageStates->build($document->ai_pipeline ?? [],
            $document->intelligenceSummary !== null, $truncated);

        $attentionBuilder = new AttentionStateBuilder($this->historical, config('intelligence_v2.attention'));
        $attention = [];
        foreach ($records as $record) {
            $attention[$record['identity']] = $attentionBuilder->build($record,
                $assignments[$record['identity']] ?? ['tier' => 3], $records, $asOf);
        }

        $keyFigures = [];
        foreach ($this->figures->select($records, $asOf, $assignments) as $record) {
            $keyFigures[] = $record['source_id'];
        }

        return [
            'records' => $records,
            'assignments' => $assignments,
            'attention' => $attention,
            'coverage' => $coverage,
            'key_figure_ids' => $keyFigures,
            'forced_overflow' => $overflow,
            'as_of' => $asOf->format(\DateTimeInterface::ATOM),
            'document_name' => (string) $document->name,
            'document_type' => (string) $document->type,
            'pipeline_key' => $document->ai_pipeline['key'] ?? null,
            'extraction_version' => $document->ai_pipeline['extraction_version'] ?? null,
        ];
    }

    /**
     * The clock every B2 caller shares, quantized to a UTC day.
     *
     * Stage A output is a function of `asOf`: materiality's date-proximity signal and every
     * attention state move with it. At timestamp granularity the attempt's input hash would change
     * on every request, so a stored narrative could never be reused by the read path and each run
     * would pay again. Stage A's own date thresholds are all whole days (imminent_days,
     * horizon_days), so a day boundary loses nothing and makes a B2 result reproducible.
     */
    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
    }

    /**
     * Deterministic priority order shared by the context builder and the read path: materiality
     * tier, then the forced rule's declared priority, then score, then Stage A's own tiebreak.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,array<string,mixed>>  $assignments
     * @return list<array<string,mixed>>
     */
    public static function prioritize(array $records, array $assignments): array
    {
        usort($records, static function (array $a, array $b) use ($assignments): int {
            $left = $assignments[$a['identity']] ?? [];
            $right = $assignments[$b['identity']] ?? [];

            return (($left['tier'] ?? 4) <=> ($right['tier'] ?? 4))
                ?: (($left['forced_priority'] ?? PHP_INT_MAX) <=> ($right['forced_priority'] ?? PHP_INT_MAX))
                ?: (($right['score'] ?? 0.0) <=> ($left['score'] ?? 0.0))
                ?: MaterialityScorer::compareTiebreak($a, $b, $left, $right);
        });

        return array_values($records);
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
