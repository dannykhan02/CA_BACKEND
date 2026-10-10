<?php

namespace App\Services\Intelligence\B2;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Intelligence\Brief\BriefAssembler;

/**
 * The read path for the Brief. B1's deterministic blocks are always the answer; a stored B2
 * narrative is layered on only when it was verified against exactly the evidence set the reader is
 * being shown now.
 *
 * No provider request and no write: this runs inside an API response. If B2 has not run, has
 * failed, was rejected, or the evidence has moved on since it ran, the narrative is absent and
 * `fallbackReason` says which of those it was. The Brief itself is never unavailable because of B2.
 */
class BriefReadService
{
    public function __construct(
        private StageASnapshot $snapshots,
        private NarrativeContextBuilder $contexts,
        private BriefAssembler $assembler,
    ) {}

    /** @return array<string,mixed>|null null means the B2 feature is off and the API adds nothing */
    public function forDocument(Document $document, ?\DateTimeImmutable $asOf = null): ?array
    {
        if (! config('intelligence_v2.enabled') || ! config('intelligence_v2.b2.enabled')) {
            return null;
        }
        $asOf ??= StageASnapshot::today();
        $snapshot = $this->snapshots->build($document, $asOf);
        $brief = $this->assembler->assemble($snapshot['document_name'], $snapshot['document_type'],
            $snapshot['records'], $snapshot['assignments'], $snapshot['coverage'], $asOf,
            $snapshot['forced_overflow']);

        $narrative = null;
        $status = 'not_generated';
        $reason = 'not_generated';
        $audit = null;

        $built = $this->contexts->build($snapshot);
        if ($built['supplied'] === []) {
            [$status, $reason] = ['empty_evidence', 'empty_evidence'];
        } elseif (count($built['supplied']) < (int) config('intelligence_v2.b2.min_records')) {
            [$status, $reason] = ['insufficient_evidence', 'insufficient_evidence'];
        } else {
            $hash = $this->contexts->inputHash($built['context'], $this->contexts->model());
            $unit = $this->unit($document, $hash);
            if ($unit === null) {
                [$status, $reason] = ['not_generated', 'not_generated'];
            } elseif ($unit->status === 'completed' && ($unit->result['status'] ?? null) === 'verified') {
                $claims = is_array($unit->result['claims'] ?? null) ? $unit->result['claims'] : [];
                $narrative = ['claims' => array_values(array_map(static fn (array $claim) => [
                    'text' => (string) ($claim['text'] ?? ''),
                    'cites' => array_values(array_filter((array) ($claim['cites'] ?? []), 'is_string')),
                ], $claims))];
                [$status, $reason] = ['verified', null];
                $audit = $unit->result['audit'] ?? null;
            } else {
                $status = $unit->status === 'completed' ? 'rejected' : $unit->status;
                $reason = $unit->result['fallback_reason'] ?? $unit->failure_class ?? 'unavailable';
                $audit = $unit->result['audit'] ?? null;
            }
        }

        // Coverage is deterministic and is never generated: B1's own coverage_note block, when it
        // emitted one, is what qualifies the narrative.
        $coverageNote = null;
        foreach ($brief['blocks'] as $block) {
            if ($block['type'] === 'coverage_note') {
                $coverageNote = $block['text'];
            }
        }
        if ($narrative !== null) {
            $narrative['coverageNote'] = $coverageNote;
        }

        return [
            'blocks' => $brief['blocks'],
            'templateVersion' => $brief['template_version'],
            'narrative' => $narrative,
            'status' => $status,
            'fallbackReason' => $reason,
            'audit' => $audit === null ? null : [
                'contractVersion' => $audit['contract_version'] ?? null,
                'promptVersion' => $audit['prompt_version'] ?? null,
                'verifierVersion' => $audit['verifier_version'] ?? null,
                'model' => $audit['model'] ?? null,
                'claimCount' => $audit['claim_count'] ?? null,
                'suppliedEvidence' => $audit['supplied_evidence'] ?? null,
                'omittedEvidence' => $audit['omitted_evidence'] ?? null,
            ],
        ];
    }

    private function unit(Document $document, string $hash): ?DocumentChunk
    {
        $key = $document->ai_pipeline['key'] ?? null;
        if (! is_string($key)) {
            return null;
        }

        return DocumentChunk::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $key)
            ->where('stage', 'brief_synthesis')->where('input_hash', $hash)->first();
    }
}
