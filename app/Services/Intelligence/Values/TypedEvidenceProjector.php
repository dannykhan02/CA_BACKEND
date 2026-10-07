<?php

namespace App\Services\Intelligence\Values;

use App\Models\DocumentEvidence;

/** Adds V2 typing to a read model; stored evidence and identity remain untouched. */
class TypedEvidenceProjector
{
    public function __construct(private ValueParser $parser) {}

    /** @return array<string,mixed> */
    public function project(DocumentEvidence $row, ?string $confirmedEntityId = null): array
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
        $data['typed'] = $this->parser->parse($data, $quotes, $confirmedEntityId);

        return $data;
    }
}
