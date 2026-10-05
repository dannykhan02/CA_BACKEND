<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\AiModels;

class EvidenceBudget
{
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

    public function forDocument(Document $document): array
    {
        $records = DocumentEvidence::where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])->orderBy('identity')->get();
        $unresolved = $records->filter(fn ($record) => $record->kind === 'unresolved' && ! isset($record->data['resolved_evidence_id']))->count();
        $records = $records->filter(fn ($record) => $record->kind !== 'unresolved' || isset($record->data['resolved_evidence_id']));
        $records = $records->sortBy(fn ($e) => match ($e->kind) {
            'deadline', 'obligation' => 0, 'metric' => 1,
            'risk' => in_array($e->data['severity'], ['high', 'critical']) ? 0 : 2,
            'definition', 'entity' => 3, default => 4,
        })->values();
        $data = ['entities' => [], 'risks' => [], 'deadlines' => [], 'kpis' => [], 'facts' => []];
        $budget = (int) (config('document_intelligence.synthesis_token_budget') / (2 ** ($document->ai_pipeline['synthesis_reductions'] ?? 0)));
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
        $complete = $trimmed === 0 && $unresolved === 0 && $failed === 0 && $dropped === 0;
        $data['coverage'] = ['evidence_total' => $records->count(), 'evidence_omitted' => $trimmed,
            'unresolved_references' => $unresolved, 'failed_chunks' => $failed, 'total_chunks' => $leaves->count(),
            'dropped_records' => $dropped, 'comprehensive' => $complete,
            'warning' => $complete ? null : 'This intelligence is based on incomplete document evidence; some content could not be processed or included.'];

        return $data;
    }

    /** Evidence (authoritative) plus original source text for cross-section context. */
    public function forSynthesis(Document $document): array
    {
        $data = $this->forDocument($document);
        $data['source_context'] = $this->sourceContext($document, $data);
        $data['coverage']['source_text'] = $data['source_context']['coverage'];

        return $data;
    }

    /** Token budget for source text; halves with every synthesis context reduction. */
    public function sourceBudgetTokens(Document $document): int
    {
        $model = app(AiModels::class)->forTask('document_summary');
        $context = (config('document_intelligence.model_capabilities', [])[$model] ?? ['context_window' => 0])['context_window'];
        // Room left after the bounded evidence, prompt/schema, output and safety margin.
        $room = $context - (int) config('document_intelligence.synthesis_token_budget') - 8192
            - (int) config('document_intelligence.synthesis_max_tokens')
            - (int) ceil($context * (float) config('document_intelligence.context_safety_ratio'));

        return max(0, (int) floor(min($room, (int) config('document_intelligence.synthesis_source_max_tokens'))
            / (2 ** ($document->ai_pipeline['synthesis_reductions'] ?? 0))));
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
    public function sourceContext(Document $document, array $data): array
    {
        $text = (string) $document->extracted_text;
        $budget = $this->sourceBudgetTokens($document);
        $tokens = $this->documentTokens($document);
        if ($text !== '' && $tokens <= $budget) {
            return ['coverage' => 'full', 'text' => $text];
        }
        // Bytes per token from the same conservative count, so the excerpt budget stays within tokens.
        $byteBudget = (int) floor($budget * strlen($text) / max(1, $tokens));
        $radius = (int) config('document_intelligence.synthesis_excerpt_radius_chars');
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
    public function sourceReserveBytes(Document $document): int
    {
        $text = (string) $document->extracted_text;
        $budget = $this->sourceBudgetTokens($document);
        if ($this->documentTokens($document) <= $budget) {
            return strlen(json_encode($text, JSON_UNESCAPED_UNICODE)) + 64;
        }
        $byteBudget = (int) floor($budget * strlen($text) / max(1, $this->documentTokens($document)));

        // JSON escaping at most doubles ordinary text; offsets/keys per excerpt are small.
        return 2 * $byteBudget + 4096;
    }
}
