<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;

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
}
