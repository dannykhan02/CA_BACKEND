<?php

namespace App\Services\Intelligence;

/** Builds a conservative V2 coverage view from diagnostics already stored by V1. */
class CoverageStateBuilder
{
    /** @return array<string,mixed> */
    public function build(array $pipeline, bool $summaryAvailable, bool $tier1Truncated = false): array
    {
        $coverage = is_array($pipeline['coverage'] ?? null) ? $pipeline['coverage'] : [];
        $route = $pipeline['route'] ?? null;
        $reasons = [];
        if ($route === 'normal') {
            $reasons[] = 'legacy_route';
        }
        foreach (['evidence_total', 'evidence_omitted', 'unresolved_references', 'failed_chunks',
            'total_chunks', 'dropped_records', 'saturated_chunks'] as $key) {
            if (! is_int($coverage[$key] ?? null)) {
                $reasons[] = $key.'_unknown';
            }
        }
        if (! in_array($coverage['source_text'] ?? null, ['full', 'excerpts', 'omitted'], true)) {
            $reasons[] = 'source_text_unknown';
        }
        if (! is_int($coverage['synthesis_level'] ?? null)) {
            $reasons[] = 'synthesis_level_unknown';
        }
        $telemetryUnknown = $reasons !== [];
        $failed = ($coverage['failed_chunks'] ?? 0) > 0 || ($coverage['dropped_records'] ?? 0) > 0
            || ($coverage['saturated_chunks'] ?? 0) > 0 || ($coverage['unresolved_references'] ?? 0) > 0;
        $omitted = ($coverage['evidence_omitted'] ?? 0) > 0;
        $sourceText = $coverage['source_text'] ?? 'omitted';
        if ($failed) {
            $reasons[] = 'processing_partial';
        }
        if ($omitted) {
            $reasons[] = 'evidence_trimmed';
        }
        if ($tier1Truncated) {
            $reasons[] = 'tier1_truncated';
        }
        $state = match (true) {
            ($pipeline['synthesis'] ?? null) !== 'completed' && ! $summaryAvailable => 'unavailable',
            $route === 'normal' => 'bounded',
            $failed => 'partial',
            $omitted || $sourceText !== 'full' || $telemetryUnknown || ! ($coverage['comprehensive'] ?? false) => 'bounded',
            default => 'complete',
        };
        $stages = $this->stages($pipeline, $coverage, $summaryAvailable);

        return [
            'state' => $state,
            'evidence_total' => (int) ($coverage['evidence_total'] ?? 0),
            'evidence_omitted' => (int) ($coverage['evidence_omitted'] ?? 0),
            'unresolved_references' => (int) ($coverage['unresolved_references'] ?? 0),
            'failed_chunks' => (int) ($coverage['failed_chunks'] ?? 0),
            'total_chunks' => (int) ($coverage['total_chunks'] ?? 0),
            'dropped_records' => (int) ($coverage['dropped_records'] ?? 0),
            'saturated_chunks' => (int) ($coverage['saturated_chunks'] ?? 0),
            'comprehensive' => (bool) ($coverage['comprehensive'] ?? false),
            'source_text' => $sourceText,
            'synthesis_level' => (int) ($coverage['synthesis_level'] ?? 0),
            'warning' => $coverage['warning'] ?? null,
            'reasons' => array_values(array_unique($reasons)),
            'tier1_truncated' => $tier1Truncated,
            'stages' => $stages,
        ];
    }

    /** @param array<string,mixed> $pipeline @param array<string,mixed> $coverage @return array<string,array<string,mixed>> */
    private function stages(array $pipeline, array $coverage, bool $summaryAvailable): array
    {
        // The existing builder has no ingestion counters. Keep their values unknown rather than
        // treating the absence of a counter as an observed zero.
        $ingestion = ['status' => isset($pipeline['route']) ? 'unknown' : 'unavailable',
            'unknown_facts' => ['ingested_bytes', 'failed_items'],
            'ingested_bytes' => null, 'failed_items' => null];

        $extractionFacts = ['total_chunks', 'failed_chunks', 'dropped_records',
            'saturated_chunks', 'unresolved_references'];
        $extraction = ['unknown_facts' => []];
        foreach ($extractionFacts as $fact) {
            $extraction[$fact] = is_int($coverage[$fact] ?? null) ? $coverage[$fact] : null;
            if ($extraction[$fact] === null) {
                $extraction['unknown_facts'][] = $fact;
            }
        }
        $extractionFailed = ($extraction['failed_chunks'] ?? 0) > 0
            || ($extraction['dropped_records'] ?? 0) > 0
            || ($extraction['saturated_chunks'] ?? 0) > 0
            || ($extraction['unresolved_references'] ?? 0) > 0;
        $extraction['status'] = match (true) {
            $extractionFailed => 'partial',
            ($extraction['total_chunks'] ?? 0) === 0 => 'unavailable',
            $extraction['unknown_facts'] !== [] => 'unknown',
            default => 'complete',
        };

        $synthesis = ['unknown_facts' => []];
        foreach (['evidence_total', 'evidence_omitted', 'synthesis_level'] as $fact) {
            $synthesis[$fact] = is_int($coverage[$fact] ?? null) ? $coverage[$fact] : null;
            if ($synthesis[$fact] === null) {
                $synthesis['unknown_facts'][] = $fact;
            }
        }
        if (! in_array($coverage['source_text'] ?? null, ['full', 'excerpts', 'omitted'], true)) {
            $synthesis['unknown_facts'][] = 'source_text';
        }
        $synthesis['status'] = match (true) {
            ! $summaryAvailable => 'unavailable',
            ($synthesis['evidence_omitted'] ?? 0) > 0
                || in_array($coverage['source_text'] ?? null, ['excerpts', 'omitted'], true)
                || ($synthesis['synthesis_level'] ?? 0) > 0 => 'bounded',
            $synthesis['unknown_facts'] !== [] => 'unknown',
            default => 'complete',
        };

        return ['ingestion' => $ingestion, 'extraction' => $extraction, 'synthesis' => $synthesis];
    }
}
