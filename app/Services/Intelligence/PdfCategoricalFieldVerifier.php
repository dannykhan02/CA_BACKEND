<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Publishes a verdict only after the source graph proves each field and edge. */
final class PdfCategoricalFieldVerifier
{
    public function __construct(private PdfCategoricalFieldProof $proof,
        private SourceProvenanceReader $reader) {}

    public function verify(Document $document, ?string $setId): array
    {
        if (! config('document_intelligence.pdf_table_structure')
            || ! config('document_intelligence.pdf_basic_table_structure')
            || $document->type !== 'PDF') {
            return ['verified' => 0, 'rejected' => 0];
        }
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->where('kind', 'table_category')->get();
        $proofs = $setId === null ? [] : $this->reader->forEvidence($document,
            $rows->pluck('id')->all(), $setId);
        $counts = ['verified' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $result = $setId === null ? ['rejection' => 'categorical_provenance_unavailable']
                : $this->proof->prove($document, $row, $setId, $proofs[$row->id] ?? []);
            $data = $row->data ?? [];
            $data['measurement_verification'] = $result['rejection'] === null ? 'verified' : 'limited';
            $data['source_revision'] = hash('sha256', (string) $document->extracted_text);
            $data['source_provenance_set_id'] = $setId;
            $data['field_verification_policy'] = PdfCategoricalCandidateCollector::STRATEGY;
            if ($result['rejection'] === null) {
                $data['category_anchor_id'] = $result['anchors']['category'];
                $data['value_anchor_id'] = $result['anchors']['value'];
                $data['header_anchor_id'] = $result['anchors']['header'];
                $data['caption_anchor_id'] = $result['anchors']['caption'];
                $data['source_claim_fingerprint'] = PdfCategoricalFieldProof::fingerprint($data);
                unset($data['grounding_rejection']);
                $row->sources = $result['sources'];
                $counts['verified']++;
            } else {
                unset($data['category_anchor_id'], $data['value_anchor_id'],
                    $data['header_anchor_id'], $data['caption_anchor_id'],
                    $data['source_claim_fingerprint']);
                $data['grounding_rejection'] = $result['rejection'];
                $counts['rejected']++;
            }
            if ($data !== $row->data || $row->isDirty('sources')) {
                $row->forceFill(['data' => $data])->save();
            }
        }

        return $counts;
    }

    /** Reuse the full deterministic proof at read time; never trust a stored verdict alone. */
    public function current(Document $document, DocumentEvidence $row, string $setId,
        ?array $provenance = null): ?array
    {
        $data = $row->data ?? [];
        if (($data['measurement_verification'] ?? null) !== 'verified'
            || ($data['source_provenance_set_id'] ?? null) !== $setId
            || ($data['source_revision'] ?? null) !== hash('sha256', (string) $document->extracted_text)
            || ($data['source_claim_fingerprint'] ?? null) !== PdfCategoricalFieldProof::fingerprint($data)) {
            return null;
        }
        $result = $this->proof->prove($document, $row, $setId, $provenance);
        if ($result['rejection'] !== null
            || ($data['category_anchor_id'] ?? null) !== $result['anchors']['category']
            || ($data['value_anchor_id'] ?? null) !== $result['anchors']['value']
            || ($data['header_anchor_id'] ?? null) !== $result['anchors']['header']
            || ($data['caption_anchor_id'] ?? null) !== $result['anchors']['caption']
            || ($row->sources ?? []) != $result['sources']) {
            return null;
        }

        return $result;
    }
}
