<?php

namespace App\Services\Intelligence\Values;

use App\Models\DocumentEntity;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Intelligence\ProvenanceProjector;

/** Adds V2 typing to a read model; stored evidence and identity remain untouched. */
class TypedEvidenceProjector
{
    public function __construct(private ValueParser $parser, private ProvenanceProjector $provenance,
        private EvidenceMerger $normalizer) {}

    /** @return array<string,mixed> */
    public function project(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        $quotes = [];
        foreach ($row->sources ?? [] as $source) {
            if (is_string($source['quote'] ?? null)) {
                $quotes[] = $source['quote'];
            }
        }
        if ($quotes === [] && is_string($data['quote'] ?? null)) {
            $quotes[] = $data['quote'];
        }
        $confirmedEntityId = null;
        $subject = is_string($data['subject'] ?? null) ? trim($data['subject']) : '';
        if ($subject !== '' && $row->document_id !== null && $row->workspace_id !== null) {
            $entityId = DocumentEntity::where('workspace_id', $row->workspace_id)
                ->where('document_id', $row->document_id)
                ->where('normalized_value', $this->normalizer->normalize($subject))->value('id');
            if ($entityId !== null) {
                $confirmedEntityId = 'entity:'.$entityId;
            }
        }
        $data['typed'] = $this->parser->parse($data, $quotes, $confirmedEntityId);
        $data['provenance'] = $this->provenance->project($row);

        return $data;
    }
}
