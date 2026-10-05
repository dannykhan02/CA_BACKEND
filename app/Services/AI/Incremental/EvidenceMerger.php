<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\AiModels;
use App\Services\Kpis\KpiIdentityResolver;
use Illuminate\Support\Facades\DB;

class EvidenceMerger
{
    public function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(preg_replace('/[.,]/u', '', $value))));
    }

    public function identity(array $record, ?string $metricId = null): string
    {
        $fields = match ($record['kind']) {
            'entity' => [$record['entity_type'], $record['value']],
            'metric' => [$metricId ?? $record['label'], $record['subject'], $record['period'], $record['unit'], $record['value'], $record['metric_type'] ?? null, $record['value_basis'] ?? null],
            'deadline', 'obligation' => [$record['label'], $record['subject'], $record['due_date'], $record['value']],
            default => [$record['label'], $record['subject'], $record['value'], $record['quote']],
        };

        return hash('sha256', json_encode([$record['kind'], ...array_map(fn ($v) => $record['kind'] === 'entity'
            ? $this->normalize((string) $v)
            : trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $v))), $fields)]));
    }

    public function merge(Document $document): void
    {
        $key = $document->ai_pipeline['key'];
        // No provider call in the merge. The existing KPI resolver defaults to deterministic matching.
        foreach (DocumentChunk::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
            ->where('pipeline_key', $key)->where('stage', 'extraction')->where('status', 'completed')->orderBy('start_offset')->cursor() as $chunk) {
            $text = mb_substr($document->extracted_text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
            foreach ($chunk->result['records'] ?? [] as $record) {
                $localOffset = mb_strpos($text, $record['quote']);
                if ($localOffset === false) {
                    continue;
                } // Defense in depth against corrupted checkpoint evidence.
                $metric = null;
                if ($record['kind'] === 'metric') {
                    $metric = app(KpiIdentityResolver::class)->resolve($document->workspace_id,
                        ['label' => $record['label'], 'unit' => $record['unit'], 'period' => $record['period'],
                            'identity' => ['scope' => $record['subject'], 'period' => $record['period'],
                                'metric_type' => $record['metric_type'] ?? null, 'value_basis' => $record['value_basis'] ?? null,
                                'aggregation' => $record['aggregation'] ?? null, 'quantity_kind' => $record['quantity_kind'] ?? null]], allowAi: false);
                }
                if ($record['kind'] === 'entity') {
                    $matches = DocumentEvidence::where('document_id', $document->id)->where('pipeline_key', $key)
                        ->where('kind', 'entity')->get()->filter(function ($known) use ($record) {
                            if ($known->data['entity_type'] !== $record['entity_type']) {
                                return false;
                            }
                            foreach ($known->data['aliases'] ?? [] as $alias) {
                                if ($this->normalize($alias) === $this->normalize($record['value'])
                                    && preg_match('/'.preg_quote($known->data['value'], '/').'\\s*\\('.preg_quote($alias, '/').'\\)/iu', $known->data['quote'])) {
                                    return true;
                                }
                            }

                            return false;
                        });
                    if ($matches->count() === 1) {
                        $record['value'] = $matches->first()->data['value'];
                    }
                }
                $identity = $this->identity($record, $metric['definition_id'] ?? null);
                $source = ['chunk_id' => $chunk->id, 'start_offset' => $chunk->start_offset + $localOffset,
                    'end_offset' => $chunk->start_offset + $localOffset + mb_strlen($record['quote']),
                    'quote' => $record['quote'], 'page' => $chunk->start_page === null ? null : $chunk->start_page + substr_count(mb_substr($text, 0, $localOffset), "\f")];
                DB::transaction(function () use ($document, $key, $record, $identity, $source, $metric) {
                    Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                    $evidence = DocumentEvidence::firstOrCreate(['document_id' => $document->id,
                        'pipeline_key' => $key, 'identity' => $identity], ['workspace_id' => $document->workspace_id,
                            'kind' => $record['kind'], 'data' => $record, 'sources' => []]);
                    $sources = $evidence->sources;
                    if (! in_array($source, $sources, true)) {
                        $sources[] = $source;
                    }
                    $data = $evidence->data;
                    $data['aliases'] = array_values(array_unique([...($data['aliases'] ?? []),
                        ...array_filter($record['aliases'], fn ($alias) => str_contains($record['quote'], $alias))]));
                    $evidence->update(['sources' => $sources, 'data' => $data]);
                    if (! $evidence->source_id) {
                        $evidence->update(['source_id' => $this->persist($document, $evidence, $metric)]);
                    }
                });
            }
        }
    }

    private function persist(Document $document, DocumentEvidence $evidence, ?array $metric): string
    {
        $r = $evidence->data;
        $common = ['workspace_id' => $document->workspace_id, 'prompt_version' => config('document_intelligence.prompt_version'),
            'provider' => 'anthropic', 'model' => app(AiModels::class)->forTask('extraction'), 'confidence' => $r['confidence']];
        if ($r['kind'] === 'entity') {
            $type = in_array($r['entity_type'], ['organization', 'person', 'department', 'location', 'regulator', 'contract', 'reference', 'date', 'other'], true) ? $r['entity_type'] : 'other';
            $row = $document->entities()->firstOrCreate(['entity_type' => $type, 'normalized_value' => $this->normalize($r['value'])],
                $common + ['value' => $r['value'], 'context' => $r['quote']]);

            return 'entity:'.$row->id;
        }
        if ($r['kind'] === 'risk') {
            $row = $document->risks()->firstOrCreate(['title' => mb_substr($r['label'], 0, 255), 'evidence' => $r['quote']],
                $common + ['description' => $r['value'], 'severity' => in_array($r['severity'], ['low', 'medium', 'high', 'critical'], true) ? $r['severity'] : 'medium', 'status' => 'open']);

            return 'risk:'.$row->id;
        }
        if (in_array($r['kind'], ['deadline', 'obligation'])) {
            $row = $document->deadlines()->firstOrCreate(['title' => mb_substr($r['label'], 0, 255), 'evidence' => $r['quote']],
                $common + ['deadline_type' => $r['kind'], 'description' => $r['value'], 'date_type' => $r['date_type'],
                    'due_date' => $r['due_date'], 'relative_text' => $r['due_date'] ? null : $r['value'], 'status' => 'open']);

            return 'deadline:'.$row->id;
        }
        if ($r['kind'] === 'metric') {
            $number = rtrim(str_replace(',', '', $r['value']), '%');
            $row = $document->kpis()->create(['workspace_id' => $document->workspace_id, 'label' => mb_substr($r['label'], 0, 255),
                'kpi_definition_id' => $metric['definition_id'] ?? null, 'identity_metadata' => ['scope' => $r['subject'], 'metric_type' => $r['metric_type'] ?? null,
                    'value_basis' => $r['value_basis'] ?? null, 'aggregation' => $r['aggregation'] ?? null,
                    'quantity_kind' => $r['quantity_kind'] ?? null],
                'period' => $metric['profile']['period'] ?? $r['period'], 'value' => mb_substr($r['value'], 0, 255),
                'unit' => $r['unit'], 'value_numeric' => is_numeric($number) ? (float) $number : null]);

            return 'kpi:'.$row->id;
        }

        return 'fact:'.$evidence->id;
    }
}
