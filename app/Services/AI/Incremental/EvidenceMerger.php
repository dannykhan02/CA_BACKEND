<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\AiModels;
use App\Services\Kpis\KpiIdentityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, provider-free merge of checkpointed chunk evidence.
 *
 * Bounded work per merge: existing evidence is loaded once, entity aliases and KPI
 * identities are resolved in memory (one resolution per distinct KPI observation),
 * evidence is written with bulk upserts and derived rows are created in short
 * batches that lock only the evidence rows they finalize - never the document row.
 * Evidence identity is unchanged, so retries reuse rows persisted by earlier attempts.
 */
class EvidenceMerger
{
    private const WRITE_BATCH = 100;

    private const DERIVED_BATCH = 50;

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

    /** Returns metadata-only diagnostics (counts and timings, never content). */
    public function merge(Document $document): array
    {
        $started = hrtime(true);
        $key = $document->ai_pipeline['key'];
        $stats = ['chunks' => 0, 'candidate_records' => 0, 'accepted_records' => 0, 'rejected_quote' => 0,
            'existing_evidence' => 0, 'existing_hits' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0,
            'derived_created' => 0, 'kpi_resolutions' => 0, 'kpi_alias_fast_path' => 0, 'kpi_cache_hits' => 0,
            'kpi_ms' => 0.0, 'entity_ms' => 0.0, 'persist_ms' => 0.0, 'derived_ms' => 0.0];

        $existing = DocumentEvidence::where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $key)->get()->keyBy('identity');
        $stats['existing_evidence'] = $existing->count();

        $state = [];
        $aliasIndex = [];
        foreach ($existing as $identity => $evidence) {
            if ($evidence->kind === 'entity') {
                $this->indexAliases($aliasIndex, $identity, $evidence->data);
            }
        }
        $metrics = [];
        $resolver = app(KpiIdentityResolver::class);
        $text = $document->extracted_text;

        // No provider call in the merge. KPI resolution stays deterministic (allowAi: false).
        foreach (DocumentChunk::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
            ->where('pipeline_key', $key)->where('stage', 'extraction')->where('status', 'completed')->orderBy('start_offset')->cursor() as $chunk) {
            $stats['chunks']++;
            $chunkText = mb_substr($text, $chunk->start_offset, $chunk->end_offset - $chunk->start_offset);
            foreach ($chunk->result['records'] ?? [] as $record) {
                $stats['candidate_records']++;
                $localOffset = mb_strpos($chunkText, $record['quote']);
                if ($localOffset === false) {
                    $stats['rejected_quote']++;

                    continue;
                } // Defense in depth against corrupted checkpoint evidence.
                $metric = null;
                if ($record['kind'] === 'metric') {
                    $clock = hrtime(true);
                    $input = ['label' => $record['label'], 'unit' => $record['unit'], 'period' => $record['period'],
                        'identity' => ['scope' => $record['subject'], 'period' => $record['period'],
                            'metric_type' => $record['metric_type'] ?? null, 'value_basis' => $record['value_basis'] ?? null,
                            'aggregation' => $record['aggregation'] ?? null, 'quantity_kind' => $record['quantity_kind'] ?? null]];
                    $cacheKey = json_encode($input);
                    if (array_key_exists($cacheKey, $metrics)) {
                        $stats['kpi_cache_hits']++;
                    } elseif ($fast = $resolver->existingAlias($document->workspace_id, $input)) {
                        $stats['kpi_alias_fast_path']++;
                        $metrics[$cacheKey] = $fast;
                    } else {
                        $stats['kpi_resolutions']++;
                        $metrics[$cacheKey] = $resolver->resolve($document->workspace_id, $input, allowAi: false);
                    }
                    $metric = $metrics[$cacheKey];
                    $stats['kpi_ms'] += (hrtime(true) - $clock) / 1e6;
                }
                if ($record['kind'] === 'entity') {
                    $clock = hrtime(true);
                    $canonical = $this->explicitAliasTarget($aliasIndex, $state, $existing, $record);
                    if ($canonical !== null) {
                        $record['value'] = $canonical;
                    }
                    $stats['entity_ms'] += (hrtime(true) - $clock) / 1e6;
                }
                $identity = $this->identity($record, $metric['definition_id'] ?? null);
                $source = ['chunk_id' => $chunk->id, 'start_offset' => $chunk->start_offset + $localOffset,
                    'end_offset' => $chunk->start_offset + $localOffset + mb_strlen($record['quote']),
                    'quote' => $record['quote'], 'page' => $chunk->start_page === null ? null : $chunk->start_page + substr_count(mb_substr($chunkText, 0, $localOffset), "\f")];
                $stats['accepted_records']++;

                if (! isset($state[$identity])) {
                    $known = $existing[$identity] ?? null;
                    if ($known) {
                        $stats['existing_hits']++;
                    }
                    $state[$identity] = $known
                        ? ['id' => $known->id, 'kind' => $known->kind, 'data' => $known->data, 'sources' => $known->sources ?? [], 'new' => false, 'dirty' => false]
                        : ['id' => (new DocumentEvidence)->newUniqueId(), 'kind' => $record['kind'], 'data' => $record, 'sources' => [], 'new' => true, 'dirty' => true];
                    $state[$identity]['metric'] = $metric;
                    $state[$identity]['source_id'] = $known?->source_id;
                }
                $entry = &$state[$identity];
                // jsonb reorders object keys, so compare sources by canonical form.
                $signature = $this->sourceSignature($source);
                if (! in_array($signature, array_map(fn ($s) => $this->sourceSignature($s), $entry['sources']), true)) {
                    $entry['sources'][] = $source;
                    $entry['dirty'] = true;
                }
                $aliases = array_values(array_unique([...($entry['data']['aliases'] ?? []),
                    ...array_filter($record['aliases'], fn ($alias) => str_contains($record['quote'], $alias))]));
                if ($aliases !== ($entry['data']['aliases'] ?? null)) {
                    $entry['data']['aliases'] = $aliases;
                    $entry['dirty'] = true;
                }
                if ($entry['kind'] === 'entity') {
                    $this->indexAliases($aliasIndex, $identity, $entry['data']);
                }
                unset($entry);
            }
        }

        $clock = hrtime(true);
        $this->writeEvidence($document, $key, $state, $stats);
        $stats['persist_ms'] = (hrtime(true) - $clock) / 1e6;

        $clock = hrtime(true);
        $this->persistDerived($document, $key, $state, $stats);
        $stats['derived_ms'] = (hrtime(true) - $clock) / 1e6;

        foreach (['kpi_ms', 'entity_ms', 'persist_ms', 'derived_ms'] as $timing) {
            $stats[$timing] = round($stats[$timing], 1);
        }
        $stats['total_ms'] = round((hrtime(true) - $started) / 1e6, 1);

        return $stats;
    }

    private function sourceSignature(array $source): string
    {
        ksort($source);

        return json_encode($source);
    }

    private function indexAliases(array &$index, string $identity, array $data): void
    {
        foreach ($data['aliases'] ?? [] as $alias) {
            $index[$data['entity_type']][$this->normalize($alias)][$identity] = true;
        }
    }

    /** Explicit "Name (Alias)" evidence only; similarity never merges entities. */
    private function explicitAliasTarget(array $index, array $state, $existing, array $record): ?string
    {
        $normalized = $this->normalize($record['value']);
        $matches = [];
        foreach (array_keys($index[$record['entity_type']][$normalized] ?? []) as $identity) {
            $data = $state[$identity]['data'] ?? $existing[$identity]->data;
            foreach ($data['aliases'] ?? [] as $alias) {
                if ($this->normalize($alias) === $normalized
                    && preg_match('/'.preg_quote($data['value'], '/').'\\s*\\('.preg_quote($alias, '/').'\\)/iu', $data['quote'])) {
                    $matches[] = $data['value'];
                    break;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** Bulk upsert on the existing (document_id, pipeline_key, identity) unique key. */
    private function writeEvidence(Document $document, string $key, array &$state, array &$stats): void
    {
        $now = now();
        $rows = [];
        foreach ($state as $identity => $entry) {
            if (! $entry['dirty']) {
                $stats['unchanged']++;

                continue;
            }
            $stats[$entry['new'] ? 'inserted' : 'updated']++;
            $rows[] = ['id' => $entry['id'], 'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'pipeline_key' => $key, 'identity' => $identity, 'kind' => $entry['kind'],
                'data' => json_encode($entry['data'], JSON_THROW_ON_ERROR), 'sources' => json_encode($entry['sources'], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, self::WRITE_BATCH) as $batch) {
            DB::table('document_evidence')->upsert($batch, ['document_id', 'pipeline_key', 'identity'], ['data', 'sources', 'updated_at']);
        }
    }

    /**
     * Derived KPI/entity/risk/deadline rows are created exactly once per evidence row:
     * the creation and the source_id link commit together, under a lock on that
     * evidence row, and rows already linked are skipped.
     */
    private function persistDerived(Document $document, string $key, array $state, array &$stats): void
    {
        $pending = DocumentEvidence::where('document_id', $document->id)->where('pipeline_key', $key)
            ->whereNull('source_id')->whereIn('identity', array_keys($state))->pluck('id', 'identity');
        foreach (array_chunk($pending->all(), self::DERIVED_BATCH, true) as $batch) {
            DB::transaction(function () use ($document, $state, $batch, &$stats) {
                $rows = DocumentEvidence::whereIn('id', array_values($batch))->whereNull('source_id')
                    ->orderBy('id')->lockForUpdate()->get();
                if ($rows->isEmpty()) {
                    return;
                }
                $known = $this->knownDerived($document, $rows);
                $links = [];
                foreach ($rows as $evidence) {
                    $links[$evidence->id] = $this->persist($document, $evidence, $state[$evidence->identity]['metric'] ?? null, $known);
                    $stats['derived_created']++;
                }
                $cases = implode(' ', array_fill(0, count($links), 'WHEN ?::uuid THEN ?'));
                $bindings = [];
                foreach ($links as $id => $sourceId) {
                    array_push($bindings, $id, $sourceId);
                }
                DB::update('UPDATE document_evidence SET source_id = CASE id '.$cases.' END, updated_at = ? WHERE id IN ('
                    .implode(',', array_fill(0, count($links), '?::uuid')).')', [...$bindings, now(), ...array_keys($links)]);
            });
        }
    }

    /** One lookup per derived kind per batch instead of one firstOrCreate probe per record. */
    private function knownDerived(Document $document, $rows): array
    {
        $known = ['entity' => [], 'risk' => [], 'deadline' => []];
        $kinds = $rows->pluck('kind')->unique();
        if ($kinds->contains('entity')) {
            foreach ($document->entities()->get(['id', 'entity_type', 'normalized_value']) as $row) {
                $known['entity'][$row->entity_type."\0".$row->normalized_value] ??= $row->id;
            }
        }
        if ($kinds->contains('risk')) {
            foreach ($document->risks()->get(['id', 'title', 'evidence']) as $row) {
                $known['risk'][$row->title."\0".$row->evidence] ??= $row->id;
            }
        }
        if ($kinds->intersect(['deadline', 'obligation'])->isNotEmpty()) {
            foreach ($document->deadlines()->get(['id', 'title', 'evidence']) as $row) {
                $known['deadline'][$row->title."\0".$row->evidence] ??= $row->id;
            }
        }

        return $known;
    }

    private function persist(Document $document, DocumentEvidence $evidence, ?array $metric, array &$known): string
    {
        $r = $evidence->data;
        $common = ['workspace_id' => $document->workspace_id, 'prompt_version' => config('document_intelligence.prompt_version'),
            'provider' => 'anthropic', 'model' => app(AiModels::class)->forTask('extraction'), 'confidence' => $r['confidence']];
        if ($r['kind'] === 'entity') {
            $type = in_array($r['entity_type'], ['organization', 'person', 'department', 'location', 'regulator', 'contract', 'reference', 'date', 'other'], true) ? $r['entity_type'] : 'other';
            $lookup = $type."\0".$this->normalize($r['value']);
            $known['entity'][$lookup] ??= $document->entities()->create(['entity_type' => $type, 'normalized_value' => $this->normalize($r['value'])]
                + $common + ['value' => $r['value'], 'context' => $r['quote']])->id;

            return 'entity:'.$known['entity'][$lookup];
        }
        if ($r['kind'] === 'risk') {
            $title = mb_substr($r['label'], 0, 255);
            $known['risk'][$title."\0".$r['quote']] ??= $document->risks()->create(['title' => $title, 'evidence' => $r['quote']]
                + $common + ['description' => $r['value'], 'severity' => in_array($r['severity'], ['low', 'medium', 'high', 'critical'], true) ? $r['severity'] : 'medium', 'status' => 'open'])->id;

            return 'risk:'.$known['risk'][$title."\0".$r['quote']];
        }
        if (in_array($r['kind'], ['deadline', 'obligation'])) {
            $title = mb_substr($r['label'], 0, 255);
            $known['deadline'][$title."\0".$r['quote']] ??= $document->deadlines()->create(['title' => $title, 'evidence' => $r['quote']]
                + $common + ['deadline_type' => $r['kind'], 'description' => $r['value'], 'date_type' => $r['date_type'],
                    'due_date' => $r['due_date'], 'relative_text' => $r['due_date'] ? null : $r['value'], 'status' => 'open'])->id;

            return 'deadline:'.$known['deadline'][$title."\0".$r['quote']];
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
