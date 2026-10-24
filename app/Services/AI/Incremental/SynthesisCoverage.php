<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Deterministic, whole-record synthesis inputs. Bytes bound tokens conservatively. */
class SynthesisCoverage
{
    public function plan(Document $document): array
    {
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $document->ai_pipeline['key'])
            ->orderBy('identity')->get()->filter(fn ($row) => ($row->data['source_candidate'] ?? false) !== true
                && ($row->kind !== 'unresolved' || isset($row->data['resolved_evidence_id'])));
        $groups = [];
        foreach ($rows as $row) {
            $source = $row->sources[0] ?? [];
            $section = isset($source['page']) ? 'page:'.intdiv(max(0, (int) $source['page'] - 1), 40)
                : 'offset:'.intdiv(max(0, (int) ($source['start_offset'] ?? 0)), 100000);
            $group = $section.':'.$row->kind;
            $groups[$group][] = $this->compact($row);
        }
        // Material risks and obligations get admission before dense metrics. Within a type,
        // sections rotate so a table on an early page cannot consume the whole allowance.
        $typeRank = ['risk' => 0, 'obligation' => 1, 'deadline' => 2, 'fact' => 3,
            'metric' => 4, 'definition' => 5, 'entity' => 6];
        uksort($groups, static function ($left, $right) use ($typeRank) {
            $a = substr($left, strrpos($left, ':') + 1);
            $b = substr($right, strrpos($right, ':') + 1);

            return [($typeRank[$a] ?? 7), $left] <=> [($typeRank[$b] ?? 7), $right];
        });
        foreach ($groups as &$items) {
            usort($items, static fn ($a, $b) => [empty($a['source_conflict']) ? 1 : 0,
                (int) ($a['citation'][0]['start_offset'] ?? 0)]
                <=> [empty($b['source_conflict']) ? 1 : 0, (int) ($b['citation'][0]['start_offset'] ?? 0)]);
        }
        unset($items);
        $batches = [];
        $uncovered = [];
        $limit = max(1, (int) config('document_intelligence.synthesis_token_budget'));
        $single = ['group' => 'whole_document', 'items' => array_merge(...array_values($groups ?: [[]]))];
        if (strlen(json_encode($single, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) <= $limit) {
            return ['batches' => $single['items'] ? [$single] : [], 'uncovered' => [], 'eligible' => $rows->count()];
        }
        $max = max(1, (int) config('document_intelligence.synthesis_max_batches', 24));
        while (array_filter($groups)) {
            foreach ($groups as $name => &$items) {
                if (! $items) {
                    continue;
                }
                $batch = ['group' => $name, 'items' => []];
                $bytes = strlen(json_encode(['group' => $name, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                while ($items) {
                    $item = $items[0];
                    $size = strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) + 1;
                    if ($size + $bytes > $limit) {
                        if (! $batch['items']) {
                            $uncovered[] = ['group' => $name, 'source_id' => $item['id'], 'reason' => 'record_exceeds_request_bound'];
                            array_shift($items);

                            continue;
                        }
                        break;
                    }
                    $batch['items'][] = array_shift($items);
                    $bytes += $size;
                }
                if ($batch['items']) {
                    if (count($batches) < $max) {
                        $batches[] = $batch;
                    } else {
                        foreach ($batch['items'] as $item) {
                            $uncovered[] = ['group' => $name, 'source_id' => $item['id'], 'reason' => 'batch_limit'];
                        }
                    }
                }
            }
            unset($items);
        }

        return ['batches' => $batches, 'uncovered' => $uncovered, 'eligible' => $rows->count()];
    }

    private function compact(DocumentEvidence $row): array
    {
        $data = $row->data ?? [];
        $item = ['id' => $row->source_id ?: 'fact:'.$row->id,
            'record_id' => $row->id, 'type' => $row->kind];
        foreach (['label', 'value', 'unit', 'period', 'comparison_period', 'comparison_value', 'date', 'due_date',
            'date_type', 'severity', 'obligation', 'party', 'attribution', 'qualifier', 'context', 'relative_text',
            'currency', 'basis', 'trend', 'description', 'title', 'subject', 'metric_type', 'value_basis',
            'aggregation', 'quantity_kind', 'source_conflict', 'reference'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $item[$field] = $data[$field];
            }
        }
        // The validated typed claim is compact; explicit qualifiers above remain intact. The
        // full verbatim quote stays in storage and is never shortened or modified there.
        $item['claim'] = trim(($data['label'] ?? '').': '.($data['value'] ?? ''), ': ');
        if ($item['claim'] === '') {
            $item['claim'] = $data['quote'] ?? '';
        }
        $item['citation'] = array_map(static fn ($source) => array_filter([
            'page' => $source['page'] ?? null, 'span_id' => $source['span_id'] ?? null,
            'start_offset' => $source['start_offset'] ?? null, 'end_offset' => $source['end_offset'] ?? null,
        ], static fn ($v) => $v !== null), array_slice($row->sources ?? [], 0, 3));

        return $item;
    }
}
