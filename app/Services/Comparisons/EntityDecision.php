<?php

namespace App\Services\Comparisons;

class EntityDecision
{
    public function decide(array $a, array $b): array
    {
        if (($a['context']['entity_type'] ?? $a['label']) !== ($b['context']['entity_type'] ?? $b['label'])) {
            return ['not_comparable', 'entity_type_mismatch'];
        }
        if (empty($a['context']['entity_identity']) || $a['context']['entity_identity'] !== ($b['context']['entity_identity'] ?? null)) {
            return ['potential_relationship', 'entity_correspondence_requires_review'];
        }
        if (isset($a['context']['role'], $b['context']['role']) && $a['context']['role'] !== $b['context']['role']) {
            return ['supported_change', 'same_entity_different_source_roles'];
        }

        return ['equivalent', 'supported_entity_correspondence'];
    }
}
