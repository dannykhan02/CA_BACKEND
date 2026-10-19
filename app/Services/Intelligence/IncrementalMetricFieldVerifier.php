<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Persists the source graph's field-role verdict, never a model's confidence. */
class IncrementalMetricFieldVerifier
{
    public function __construct(private SourceProvenanceReader $provenance,
        private ValueBasisEvidence $valueBasis) {}

    /** @return array<string,int> */
    public function verify(Document $document, ?string $setId): array
    {
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->where('kind', 'metric')->get();
        $proofs = $setId === null ? [] : $this->provenance->forEvidence($document, $rows->pluck('id')->all(), $setId);
        $counts = ['verified' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $data = is_array($row->data) ? $row->data : [];
            $roles = array_keys($proofs[$row->id]['roles'] ?? []);
            $required = ['value' => 'value_source_mismatch', 'metric' => 'concept_source_mismatch'];
            foreach (['unit' => 'unit_source_mismatch', 'period' => 'period_source_mismatch',
                'subject' => 'subject_source_mismatch'] as $field => $reason) {
                if (trim((string) ($data[$field] ?? '')) !== '') {
                    $required[$field] = $reason;
                }
            }
            $rejection = null;
            foreach ($required as $role => $reason) {
                if (! in_array($role, $roles, true)) {
                    $rejection = $reason;
                    break;
                }
            }
            if ($rejection === null && ! ($proofs[$row->id]['capabilities']['structural'] ?? false)) {
                $rejection = $this->proseRelationshipRejection($document, $proofs[$row->id]['roles'], $required);
            }
            if ($rejection === null) {
                $rejection = $this->valueBasis->rejection($data['value_basis'] ?? null,
                    $this->basisSource($row->sources ?? [], $proofs[$row->id]));
            }
            $data['source_fields_verified'] = $roles;
            $data['field_verification_policy'] = 'incremental.v1';
            $data['source_revision'] = hash('sha256', (string) $document->extracted_text);
            $data['source_provenance_set_id'] = $setId;
            $data['measurement_verification'] = $rejection === null ? 'verified' : 'limited';
            if ($rejection === null) {
                unset($data['grounding_rejection']);
                $counts['verified']++;
            } else {
                $data['grounding_rejection'] = $rejection;
                $counts['rejected']++;
            }
            if ($data !== $row->data) {
                $row->forceFill(['data' => $data])->save();
            }
        }

        return $counts;
    }

    private function basisSource(array $sources, array $proof): string
    {
        if ($proof['capabilities']['structural'] ?? false) {
            return implode("\n", array_column($sources, 'quote'));
        }
        $value = $proof['roles']['value'][0] ?? null;
        if (! is_int($value['start_offset'] ?? null) || ! is_int($value['end_offset'] ?? null)) {
            return '';
        }
        foreach ($sources as $source) {
            if (! is_int($source['start_offset'] ?? null) || ! is_int($source['end_offset'] ?? null)
                || $value['start_offset'] < $source['start_offset']
                || $value['end_offset'] > $source['end_offset']) {
                continue;
            }
            foreach (preg_split('/[!?\n]|(?<!\d)\.|\.(?!\d)/u', (string) ($source['quote'] ?? '')) ?: [] as $sentence) {
                if (str_contains($sentence, (string) ($value['text'] ?? ''))) {
                    return $sentence;
                }
            }
        }

        return '';
    }

    /** A quote enclosing two sentences does not make their metric fields related. */
    private function proseRelationshipRejection(Document $document, array $roles, array $required): ?string
    {
        $value = $roles['value'][0] ?? null;
        $start = $value['start_offset'] ?? null;
        $end = $value['end_offset'] ?? null;
        if (! is_int($start) || ! is_int($end)) {
            return 'value_relationship_unverified';
        }
        $text = (string) $document->extracted_text;
        foreach ($required as $role => $reason) {
            if ($role === 'value') {
                continue;
            }
            $related = false;
            foreach ($roles[$role] ?? [] as $anchor) {
                $from = $anchor['start_offset'] ?? null;
                $to = $anchor['end_offset'] ?? null;
                if (! is_int($from) || ! is_int($to)) {
                    continue;
                }
                $between = mb_substr($text, min($start, $from), max($end, $to) - min($start, $from));
                if (mb_strlen($between) <= 240
                    && preg_match('/[!?\n]|(?<!\d)\.|\.(?!\d)/u', $between) !== 1) {
                    $related = true;
                    break;
                }
            }
            if (! $related) {
                return str_replace('_source_mismatch', '_relationship_unverified', $reason);
            }
        }

        return null;
    }
}
