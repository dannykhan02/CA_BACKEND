<?php

namespace App\Services\Intelligence\B2;

use App\Services\AI\AiModels;
use App\Services\AI\Incremental\ChunkPlanner;

/**
 * The B2 input contract. Turns one Stage A snapshot into the bounded, deterministically ordered
 * payload the synthesis request carries, and nothing else reaches the provider.
 *
 * What is deliberately NOT in the contract:
 *  - source quotes and span offsets. Stage A has already typed every value, so the narrative needs
 *    no source text; withholding it removes the largest body of untrusted free text from the
 *    prompt and makes the verifier's no_source_text_leak check unfalsifiable by construction.
 *  - unknown-origin evidence. It can never support a factual claim, so it is excluded entirely and
 *    only counted, so coverage can still be stated honestly without exposing the content.
 *  - anything from the provider wire format, the extraction prompt or the record's raw `data` blob.
 *
 * Determinism: the same canonical record population yields the same payload and the same hash,
 * whichever extraction prompt or transport produced those records.
 */
class NarrativeContextBuilder
{
    public function __construct(private ChunkPlanner $planner) {}

    /**
     * @param  array<string,mixed>  $snapshot  StageASnapshot::build()
     * @return array{context:array<string,mixed>,supplied:list<string>,records:array<string,array<string,mixed>>,
     *               omitted:int,eligible:int}
     */
    public function build(array $snapshot): array
    {
        $settings = config('intelligence_v2.b2');
        $keyFigures = array_fill_keys($snapshot['key_figure_ids'], true);

        $eligible = [];
        $unknownOrigin = 0;
        foreach ($snapshot['records'] as $record) {
            if (($record['provenance']['origin'] ?? null) !== 'document') {
                $unknownOrigin++;

                continue;
            }
            // 'unresolved' is a bookkeeping kind for a reference Stage A could not resolve; it
            // carries no claim of its own, so it is not narrative material.
            if (($record['kind'] ?? null) === 'unresolved' || ! is_string($record['source_id'] ?? null)) {
                continue;
            }
            $eligible[] = $record;
        }
        $eligible = StageASnapshot::prioritize($eligible, $snapshot['assignments']);
        $eligibleCount = count($eligible);
        $eligible = array_slice($eligible, 0, max(0, (int) $settings['max_records']));

        $items = [];
        $records = [];
        foreach ($eligible as $record) {
            $items[] = $this->item($record, $snapshot, isset($keyFigures[$record['source_id']]));
            $records[$record['source_id']] = $record;
        }

        // Token budget: drop from the lowest-priority end until the encoded payload fits. The
        // bound is on the serialized context, which is what the request actually pays for.
        $budget = max(1, (int) $settings['context_token_budget']);
        $envelope = $this->envelope($snapshot, $unknownOrigin);
        while ($items !== [] && $this->tokens($envelope + ['evidence' => $items]) > $budget) {
            $dropped = array_pop($items);
            unset($records[$dropped['id']]);
        }

        $context = $envelope + ['evidence' => $items];
        $context['omitted_evidence'] = max(0, $eligibleCount - count($items));

        return [
            'context' => $context,
            'supplied' => array_keys($records),
            'records' => $records,
            'omitted' => $context['omitted_evidence'],
            'eligible' => $eligibleCount,
        ];
    }

    /**
     * The identity of one B2 attempt. Unchanged inputs must never pay twice, so every axis that
     * could change the output is in here: the evidence payload, the contract, the prompt and its
     * text, the verifier and the model.
     *
     * @param  array<string,mixed>  $context
     */
    public function inputHash(array $context, string $model): string
    {
        $settings = config('intelligence_v2.b2');

        return hash('sha256', json_encode([
            'context' => $context,
            'contract_version' => $settings['contract_version'],
            'prompt_version' => $settings['prompt_version'],
            'prompt_hash' => NarrativePrompt::hash(),
            'verifier_version' => $settings['verifier_version'],
            'model' => $model,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * The synthesis model, which is part of the attempt identity. It lives here rather than on the
     * synthesizer so the read path can compute an input hash without pulling AnthropicClient into
     * its dependency graph: an API response must not be able to reach the provider at all.
     */
    public function model(): string
    {
        return (string) (config('intelligence_v2.b2.model')
            ?: app(AiModels::class)->forTask('brief_synthesis'));
    }

    /** @param array<string,mixed> $context */
    public function tokens(array $context): int
    {
        return $this->planner->estimate(json_encode($context,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function envelope(array $snapshot, int $unknownOrigin): array
    {
        return [
            'contract_version' => (string) config('intelligence_v2.b2.contract_version'),
            'as_of' => substr((string) $snapshot['as_of'], 0, 10),
            'document' => ['name' => $snapshot['document_name'], 'type' => $snapshot['document_type']],
            'coverage' => [
                'state' => $snapshot['coverage']['state'] ?? 'unavailable',
                'reasons' => array_values($snapshot['coverage']['reasons'] ?? []),
                'tier1_truncated' => (bool) ($snapshot['coverage']['tier1_truncated'] ?? false),
            ],
            // Counted, never shown: these records exist but may not support any claim.
            'excluded_untrusted_evidence' => $unknownOrigin,
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function item(array $record, array $snapshot, bool $keyFigure): array
    {
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];
        $typed = is_array($record['typed'] ?? null) ? $record['typed'] : [];
        $value = is_array($typed['value'] ?? null) ? $typed['value'] : null;
        $attribution = $record['provenance']['attribution'] ?? [];

        $dates = [];
        foreach (is_array($typed['dates'] ?? null) ? $typed['dates'] : [] as $role => $date) {
            if (! is_array($date)) {
                continue;
            }
            $dates[$role] = array_filter([
                'raw' => is_string($date['raw'] ?? null) ? $date['raw'] : null,
                'date' => is_string($date['date'] ?? null) ? $date['date'] : null,
                'resolution' => is_string($date['resolution'] ?? null) ? $date['resolution'] : null,
                'period' => is_string($date['period']['text'] ?? null) ? $date['period']['text'] : null,
                'duration' => is_string($date['duration']['text'] ?? null) ? $date['duration']['text'] : null,
            ], static fn ($field) => $field !== null);
        }

        return array_filter([
            'id' => $record['source_id'],
            'kind' => $record['kind'] ?? null,
            'label' => $this->text($data['label'] ?? null),
            'statement' => $this->text($data['value'] ?? null),
            'subject' => $this->text($data['subject'] ?? null),
            'severity' => $this->text($data['severity'] ?? null),
            'status' => is_string($record['status'] ?? null) ? $record['status'] : null,
            'value' => $value === null ? null : array_filter([
                'raw' => is_string($value['raw'] ?? null) ? $value['raw'] : null,
                'number' => is_numeric($value['number'] ?? null) ? (float) $value['number'] : null,
                'unit' => is_string($value['unit'] ?? null) ? $value['unit'] : null,
                'currency' => is_string($value['currency'] ?? null) ? $value['currency'] : null,
                'unit_kind' => is_string($value['unit_kind'] ?? null) ? $value['unit_kind'] : null,
                'precision' => is_string($value['precision'] ?? null) ? $value['precision'] : null,
            ], static fn ($field) => $field !== null),
            'dates' => $dates === [] ? null : $dates,
            'materiality_tier' => $snapshot['assignments'][$record['identity']]['tier'] ?? null,
            'attention' => $snapshot['attention'][$record['identity']]['state'] ?? null,
            'key_figure' => $keyFigure ?: null,
            'reported_by' => ($attribution['reported'] ?? false)
                ? ($attribution['speaker'] ?? $attribution['role'] ?? null) : null,
            'page' => is_int($record['page'] ?? null) ? $record['page'] : null,
        ], static fn ($field) => $field !== null && $field !== '' && $field !== []);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 400);
    }
}
