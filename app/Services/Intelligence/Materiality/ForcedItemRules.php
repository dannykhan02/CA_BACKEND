<?php

namespace App\Services\Intelligence\Materiality;

use App\Services\Intelligence\Attention\HistoricalRiskRule;

/** Approved forced predicates over the full document read model. */
class ForcedItemRules
{
    public function __construct(private HistoricalRiskRule $historical, private SignalEvaluator $signals) {}

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records @param array<string,mixed> $assignments @param array<string,mixed> $context */
    public function first(array $record, array $records, array $assignments, array $context, \DateTimeImmutable $asOf): ?string
    {
        $kind = $record['kind'] ?? null;
        $severity = $record['data']['severity'] ?? null;
        $historical = $this->historical->applies($record, $records, $asOf);
        $date = $record['typed']['dates']['due_date'] ?? null;
        $due = ($date['resolution'] ?? null) === 'calendar' ? ($date['date'] ?? null) : null;
        $days = is_string($due) ? (int) $asOf->setTime(0, 0)->diff(new \DateTimeImmutable($due))->format('%r%a') : null;
        $open = ($record['status'] ?? null) === 'open';
        $rules = [
            'critical_risk' => $kind === 'risk' && $severity === 'critical' && ! $historical,
            'imminent_dated_obligation' => in_array($kind, ['deadline', 'obligation'], true) && $open
                && $days !== null && $days >= 0 && $days <= config('intelligence_v2.tier1.imminent_days'),
            'overdue_dated_obligation' => in_array($kind, ['deadline', 'obligation'], true) && $open
                && $days !== null && $days < 0,
            'penalised_obligation' => $this->signals->consequence($record),
            'high_risk' => $kind === 'risk' && $severity === 'high' && ! $historical,
            'regulator_attributed' => in_array($record['provenance']['attribution']['role'] ?? null, ['regulator', 'auditor'], true)
                && ($record['provenance']['assertion'] ?? null) === 'stated',
            'headline_measure' => $this->headline($record, $records, $assignments, $context),
            'unresolved_material_reference' => $this->unresolvedNeighbour($record, $records, $assignments),
        ];
        $priorities = config('intelligence_v2.materiality.forced_priorities');
        foreach ($priorities as $id => $priority) {
            if ($rules[$id] ?? false) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records @param array<string,mixed> $assignments @param array<string,mixed> $context */
    private function headline(array $record, array $records, array $assignments, array $context): bool
    {
        $id = $record['identity'] ?? '';
        $value = $record['typed']['value'] ?? [];
        if (($record['kind'] ?? null) !== 'metric' || ($value['unit_kind'] ?? null) !== 'currency'
            || ! isset($context['comparable_source_ids'][$record['source_id'] ?? ''])
            || ($assignments[$id]['scored_tier'] ?? 4) > 2 || ! is_numeric($value['number'] ?? null)) {
            return false;
        }
        foreach ($records as $other) {
            $otherValue = $other['typed']['value'] ?? [];
            if (($other['kind'] ?? null) === 'metric' && ($otherValue['unit_kind'] ?? null) === 'currency'
                && ($otherValue['currency'] ?? null) === ($value['currency'] ?? null)
                && isset($context['comparable_source_ids'][$other['source_id'] ?? ''])
                && is_numeric($otherValue['number'] ?? null)
                && abs((float) $otherValue['number']) > abs((float) $value['number'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records @param array<string,mixed> $assignments */
    private function unresolvedNeighbour(array $record, array $records, array $assignments): bool
    {
        if (($record['kind'] ?? null) !== 'unresolved' || ! empty($record['data']['resolved_evidence_id'])) {
            return false;
        }
        foreach ($records as $other) {
            if (($other['identity'] ?? null) === ($record['identity'] ?? null)
                || ($assignments[$other['identity'] ?? '']['scored_tier'] ?? 4) > 2) {
                continue;
            }
            if (($record['section'] ?? null) !== null && ($other['section'] ?? null) !== null) {
                if ($record['section'] === $other['section']) {
                    return true;
                }
            } elseif (($record['section'] ?? null) === null && ($other['section'] ?? null) === null
                && ($record['page'] ?? null) !== null && $record['page'] === ($other['page'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
