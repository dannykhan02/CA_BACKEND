<?php

namespace App\Services\Comparisons;

/** Obligation is a classified deadline occurrence. Existing timing gates remain authoritative. */
class ObligationDecision
{
    public function decide(array $a, array $b): array
    {
        $decision = app(DeadlineDecision::class)->decide($a, $b);
        if ($decision[0] === 'supported_change') {
            return $decision;
        }
        if ($decision[0] === 'equivalent') {
            return ($a['value']['description'] ?? null) === ($b['value']['description'] ?? null)
                ? ['equivalent', 'aligned_equal_obligation_observation']
                : ['potential_relationship', 'obligation_action_wording_requires_review'];
        }
        if ($decision === ['insufficient_evidence', 'unverified_provision_context']
            && isset($a['context']['relationship_candidate'], $b['context']['relationship_candidate'])) {
            return ['potential_relationship', 'provision_correspondence_requires_review'];
        }

        return $decision;
    }
}
