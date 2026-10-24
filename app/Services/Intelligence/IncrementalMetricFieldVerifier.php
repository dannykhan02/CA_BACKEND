<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Persists the source graph's field-role verdict, never a model's confidence. */
class IncrementalMetricFieldVerifier
{
    public function __construct(private SourceProvenanceReader $provenance,
        private ValueBasisEvidence $valueBasis,
        private StructuredPdfFieldProof $pdfFields) {}

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
            $pdf = null;
            if (($proofs[$row->id]['roles']['value'][0]['modality'] ?? null) === 'structured_pdf') {
                $pdf = $this->pdfFields->prove($document,
                    [...$data, 'source_provenance_set_id' => $setId], $proofs[$row->id]);
                $roles = array_values(array_unique($pdf['roles'] ?? []));
            }
            $required = ['value' => 'value_source_mismatch', 'metric' => 'concept_source_mismatch'];
            foreach (['unit' => 'unit_source_mismatch', 'period' => 'period_source_mismatch',
                'subject' => 'subject_source_mismatch'] as $field => $reason) {
                if (trim((string) ($data[$field] ?? '')) !== '') {
                    $required[$field] = $reason;
                }
            }
            $rejection = $pdf['rejection'] ?? null;
            foreach ($required as $role => $reason) {
                if ($rejection === null && ! in_array($role, $roles, true)) {
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
            if ($pdf !== null) {
                unset($data['governing_owner'], $data['source_extraction_version']);
                if (isset($pdf['governing_owner'])) {
                    $data['governing_owner'] = $pdf['governing_owner'];
                    $data['source_extraction_version'] = $pdf['source_extraction_version'];
                    if (trim((string) ($data['subject'] ?? '')) === '') {
                        $data['raw_subject'] = $data['subject'] ?? null;
                        $data['subject'] = $pdf['verified_subject'];
                    }
                }
                if (isset($pdf['raw_period'])) {
                    $data['raw_period'] = $pdf['raw_period'];
                }
                if (isset($pdf['source_unit'])) {
                    $data['raw_unit_source'] = $pdf['source_unit'];
                }
                if (isset($pdf['accounting_basis'])) {
                    $data['accounting_basis'] = $pdf['accounting_basis'];
                    if (in_array(mb_strtolower((string) ($data['value_basis'] ?? '')),
                        ['gaap', 'non-gaap'], true)) {
                        $data['raw_value_basis'] = $data['value_basis'];
                        unset($data['value_basis']);
                    }
                }
                $sources = $row->sources ?? [];
                $known = array_fill_keys(array_filter(array_column($sources, 'span_id')), true);
                foreach ($pdf['sources'] ?? [] as $source) {
                    if (! isset($known[$source['span_id']])) {
                        $sources[] = $source;
                        $known[$source['span_id']] = true;
                    }
                }
                if ($sources !== ($row->sources ?? [])) {
                    $row->sources = $sources;
                }
            }
            $data['measurement_verification'] = $rejection === null ? 'verified' : 'limited';
            if ($pdf !== null && $rejection === null) {
                $data['source_claim_fingerprint'] = StructuredPdfFieldProof::claimFingerprint($data);
                $data['pdf_value_anchor_id'] = $proofs[$row->id]['roles']['value'][0]['anchor_id'];
            } else {
                unset($data['source_claim_fingerprint'], $data['pdf_value_anchor_id']);
            }
            if ($rejection === null) {
                unset($data['grounding_rejection']);
                $counts['verified']++;
            } else {
                $data['grounding_rejection'] = $rejection;
                $counts['rejected']++;
            }
            if ($data !== $row->data || $row->isDirty('sources')) {
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
