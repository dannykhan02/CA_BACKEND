<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\AiModels;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\ImportantFindingsBuilder;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\MetricCollector;

class EvidenceBudget
{
    public function __construct(private MaterialityReadModel $materialityRecords,
        private MaterialityScorer $materialityScorer, private MetricCollector $metrics,
        private ChartCandidateBuilder $charts, private ImportantFindingsBuilder $findings) {}

    /** Normal documents use the same whole-record budget without checkpoints or extra calls. */
    public function trimNormal(array $data): array
    {
        $budget = (int) config('document_intelligence.synthesis_token_budget');
        $used = 0;
        $total = 0;
        $omitted = 0;
        foreach (['deadlines', 'risks', 'kpis', 'entities', 'insights'] as $group) {
            $kept = [];
            foreach ($data[$group] ?? [] as $item) {
                $total++;
                $size = strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($used + $size > $budget) {
                    $omitted++;

                    continue;
                }
                $used += $size;
                $kept[] = $item;
            }
            $data[$group] = $kept;
        }
        $data['coverage'] = ['evidence_total' => $total, 'evidence_omitted' => $omitted, 'comprehensive' => $omitted === 0];

        return $data;
    }

    public function forDocument(Document $document, ?\DateTimeImmutable $asOf = null): array
    {
        $records = DocumentEvidence::where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])->orderBy('identity')->get();
        $unresolved = $records->filter(fn ($record) => $record->kind === 'unresolved' && ! isset($record->data['resolved_evidence_id']))->count();
        $records = $records->filter(fn ($record) => $record->kind !== 'unresolved' || isset($record->data['resolved_evidence_id']));
        if (config('intelligence_v2.enabled')) {
            $document->loadMissing(['risks', 'deadlines', 'intelligenceSummary']);
            $cited = $this->findings->citedSourceIds($document->intelligenceSummary);
            $chartCandidates = $this->charts->build($this->metrics->collect($document)['observations'], $cited)['candidates'];
            $read = $this->materialityRecords->build($document, $records, $chartCandidates, $cited);
            $assignments = $this->materialityScorer->assign($read['records'], $read['context'], $asOf ?? new \DateTimeImmutable);
            $byId = collect($read['records'])->keyBy('identity');
            $records = $records->sort(function ($left, $right) use ($assignments, $byId) {
                $a = $assignments[$left->identity];
                $b = $assignments[$right->identity];

                return [$a['tier'], -$a['score']] <=> [$b['tier'], -$b['score']]
                    ?: MaterialityScorer::compareTiebreak($byId[$left->identity], $byId[$right->identity], $a, $b);
            })->values();
        } else {
            $records = $records->sortBy(fn ($e) => match ($e->kind) {
                'deadline', 'obligation' => 0, 'metric' => 1,
                'risk' => in_array($e->data['severity'], ['high', 'critical']) ? 0 : 2,
                'definition', 'entity' => 3, default => 4,
            })->values();
        }
        $data = ['entities' => [], 'risks' => [], 'deadlines' => [], 'kpis' => [], 'facts' => []];
        // Identical at every synthesis fallback level: only source context is degraded.
        $budget = (int) config('document_intelligence.synthesis_token_budget');
        $used = 0;
        $trimmed = 0;
        foreach ($records as $record) {
            $item = ['id' => $record->source_id, ...$record->data, 'sources' => $record->sources];
            // Bytes are a conservative upper bound: do not cut a JSON object or source link.
            $tokens = strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($used + $tokens > $budget) {
                $trimmed++;

                continue;
            }
            $group = match ($record->kind) {
                'entity' => 'entities', 'risk' => 'risks', 'deadline', 'obligation' => 'deadlines', 'metric' => 'kpis', default => 'facts'
            };
            $data[$group][] = $item;
            $used += $tokens;
        }
        $leaves = DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $document->ai_pipeline['key'])
            ->where('stage', 'extraction')->where('status', '!=', 'split')->get();
        $failed = $leaves->where('status', '!=', 'completed')->count();
        $dropped = $leaves->sum(fn ($chunk) => array_sum($chunk->result['_dropped_records'] ?? []));
        // A response that reached its record limit may have left lower-priority evidence out.
        $saturated = $leaves->filter(fn ($chunk) => $chunk->status === 'completed' && ($chunk->result['_saturated'] ?? false))->count();
        $complete = $trimmed === 0 && $unresolved === 0 && $failed === 0 && $dropped === 0 && $saturated === 0;
        $data['coverage'] = ['evidence_total' => $records->count(), 'evidence_omitted' => $trimmed,
            'unresolved_references' => $unresolved, 'failed_chunks' => $failed, 'total_chunks' => $leaves->count(),
            'dropped_records' => $dropped, 'saturated_chunks' => $saturated, 'comprehensive' => $complete,
            'warning' => $complete ? null : 'This intelligence is based on incomplete document evidence; some content could not be processed or included.'];

        return $data;
    }

    /** Evidence (authoritative) plus original source text for cross-section context. */
    public function forSynthesis(Document $document): array
    {
        $data = $this->forDocument($document);
        $data['source_context'] = $this->sourceContext($document, $data);
        $data['coverage']['source_text'] = $data['source_context']['coverage'];
        $data['coverage']['synthesis_level'] = self::level($document);

        return $data;
    }

    /** Current synthesis fallback level (0 = full source context ... 3 = evidence only). */
    public static function level(Document $document): int
    {
        return min(count(config('document_intelligence.synthesis_levels')) - 1, max(0, (int) ($document->ai_pipeline['synthesis_reductions'] ?? 0)));
    }

    public static function levelConfig(int $level): array
    {
        $levels = config('document_intelligence.synthesis_levels');

        return $levels[min(count($levels) - 1, max(0, $level))];
    }

    /**
     * Token budget for source text at a fallback level. Level 0 is bounded by the synthesis model's
     * room; every later level is a fraction of what level 0 could actually send (never more than the
     * document), so each step materially reduces the previous source context.
     */
    public function sourceBudgetTokens(Document $document, ?int $level = null): int
    {
        $level ??= self::level($document);
        $model = app(AiModels::class)->forTask('document_summary');
        $context = (config('document_intelligence.model_capabilities', [])[$model] ?? ['context_window' => 0])['context_window'];
        // Room left after the bounded evidence, prompt/schema, output and safety margin.
        $room = $context - (int) config('document_intelligence.synthesis_token_budget') - 8192
            - (int) config('document_intelligence.synthesis_max_tokens')
            - (int) ceil($context * (float) config('document_intelligence.context_safety_ratio'));
        $full = max(0, min($room, (int) config('document_intelligence.synthesis_source_max_tokens')));
        if ($level === 0) {
            return $full;
        }

        return max(0, (int) floor(min($full, $this->documentTokens($document)) * (float) self::levelConfig($level)['source_fraction']));
    }

    /** Conservative token count; never smaller than the counted/estimated document size. */
    private function documentTokens(Document $document): int
    {
        return max((int) ($document->ai_pipeline['tokens'] ?? 0), (int) ceil(strlen((string) $document->extracted_text) / 3));
    }

    /**
     * Deterministic: the full document when it fits, else source windows around the
     * evidence already selected for synthesis (in that priority order), else nothing.
     */
    public function sourceContext(Document $document, array $data, ?int $level = null): array
    {
        $level ??= self::level($document);
        $mode = self::levelConfig($level)['source'];
        $text = (string) $document->extracted_text;
        $budget = $this->sourceBudgetTokens($document, $level);
        $tokens = $this->documentTokens($document);
        if ($mode === 'none' || $budget === 0) {
            return ['coverage' => 'omitted', 'excerpts' => []];
        }
        if ($text !== '' && $tokens <= $budget && $mode !== 'excerpts') {
            return ['coverage' => 'full', 'text' => $text];
        }
        // Bytes per token from the same conservative count, so the excerpt budget stays within tokens.
        $byteBudget = (int) floor($budget * strlen($text) / max(1, $tokens));
        $radius = (int) floor((int) config('document_intelligence.synthesis_excerpt_radius_chars') * (float) self::levelConfig($level)['radius_fraction']);
        $length = mb_strlen($text);
        $windows = [];
        $used = 0;
        foreach (['deadlines', 'kpis', 'risks', 'facts', 'entities'] as $group) {
            foreach ($data[$group] ?? [] as $item) {
                foreach ($item['sources'] ?? [] as $source) {
                    $start = max(0, $source['start_offset'] - $radius);
                    $end = min($length, $source['end_offset'] + $radius);
                    $covered = false;
                    foreach ($windows as $window) {
                        if ($start >= $window[0] && $end <= $window[1]) {
                            $covered = true;
                            break;
                        }
                    }
                    if ($covered) {
                        continue;
                    }
                    $size = strlen(mb_substr($text, $start, $end - $start));
                    if ($used + $size > $byteBudget) {
                        break 3;
                    }
                    $windows[] = [$start, $end];
                    $used += $size;
                }
            }
        }
        if (! $windows) {
            return ['coverage' => 'omitted', 'excerpts' => []];
        }
        sort($windows);
        $merged = [];
        foreach ($windows as [$start, $end]) {
            if ($merged && $start <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return ['coverage' => 'excerpts', 'excerpts' => array_map(fn ($w) => ['start_offset' => $w[0], 'end_offset' => $w[1],
            'text' => mb_substr($text, $w[0], $w[1] - $w[0])], $merged)];
    }

    /** Upper bound (bytes) of the source context future synthesis may include. */
    public function sourceReserveBytes(Document $document, ?int $level = null): int
    {
        $level ??= self::level($document);
        $text = (string) $document->extracted_text;
        $budget = $this->sourceBudgetTokens($document, $level);
        if ($budget === 0 || self::levelConfig($level)['source'] === 'none') {
            return 0;
        }
        if ($this->documentTokens($document) <= $budget && self::levelConfig($level)['source'] !== 'excerpts') {
            return strlen(json_encode($text, JSON_UNESCAPED_UNICODE)) + 64;
        }
        $byteBudget = (int) floor($budget * strlen($text) / max(1, $this->documentTokens($document)));
        // Excerpts are substrings, so they cannot hold more escapable characters than the whole
        // document: the document's own escaping overhead bounds theirs (and never above 2x).
        $escaping = max(0, strlen(json_encode($text, JSON_UNESCAPED_UNICODE)) - strlen($text) - 2);

        return min(2 * $byteBudget, $byteBudget + $escaping) + 4096;
    }
}
