<?php

namespace App\Services\Intelligence;

use App\Exceptions\AiProcessingException;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;

/** Conservative provenance from accepted stored evidence, never model-reported axes. */
class ProvenanceProjector
{
    public function __construct(private EvidenceMerger $merger, private AttributionMatcher $attribution) {}

    /** @return array<string,mixed> */
    public function project(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        $origin = $this->direct($data) ? 'document' : 'unknown';

        return ['origin' => $origin, 'assertion' => $origin === 'document' ? 'stated' : 'unspecified',
            'attribution' => $origin === 'document' ? $this->attribution->match($row)
                : ['speaker' => null, 'role' => 'unattributed', 'reported' => false, 'evidence_ref' => null]];
    }

    /** @param array<string,mixed> $provenance */
    public function legacyBasis(array $provenance): string
    {
        return match ([$provenance['origin'] ?? null, $provenance['assertion'] ?? null]) {
            ['document', 'stated'], ['docintel_deterministic', 'derived'],
            ['docintel_deterministic', 'absent'], ['docintel_ai', 'stated'] => 'explicit',
            default => 'inferred',
        };
    }

    /** @param array<string,mixed> $data */
    private function direct(array $data): bool
    {
        $quote = is_string($data['quote'] ?? null) ? $data['quote'] : '';
        $value = $this->merger->normalize((string) ($data['value'] ?? ''));
        $normalQuote = $this->merger->normalize($quote);
        if ($value === '' || $normalQuote === '' || ! str_contains($normalQuote, $value)) {
            return false;
        }
        if ($data['period'] ?? null) {
            $period = $this->merger->normalize((string) $data['period']);
            if ($period !== '' && ! str_contains($normalQuote, $period)) {
                return false;
            }
        }
        if ($data['due_date'] ?? null) {
            // Reuse the application validator's exact date-grounding check on accepted stored
            // fields. An ISO date need not appear verbatim in the cited quote.
            try {
                $validated = EvidenceSchema::validate(['records' => [$data]], $quote);

                return ($validated['records'][0]['due_date'] ?? null) === $data['due_date'];
            } catch (AiProcessingException) {
                return false;
            }
        }

        return true;
    }
}
