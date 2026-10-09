<?php

namespace App\Services\Intelligence\B2;

use App\Services\AI\Incremental\EvidenceSchema;

/**
 * Structured-output schema for one B2 narrative.
 *
 * There is no coverage field. A model-authored coverage sentence is exactly where an unsupported
 * absence or completeness claim would live, and it cannot be grounded in any record, so coverage is
 * emitted by B1's deterministic coverage_note template instead and never generated.
 */
final class NarrativeSchema
{
    public static function schema(): array
    {
        return EvidenceSchema::object([
            'narrative' => [
                'type' => 'array',
                'items' => EvidenceSchema::object([
                    'claim' => ['type' => 'string'],
                    'cites' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]),
            ],
        ]);
    }
}
