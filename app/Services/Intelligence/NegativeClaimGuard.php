<?php

namespace App\Services\Intelligence;

/** The single admission gate for deterministic V2 absence claims. */
class NegativeClaimGuard
{
    /** English-only, case-insensitive, Unicode word-boundary safety screen. */
    public function matchesProse(string $text): bool
    {
        foreach (config('intelligence_v2.negative_claim.patterns', []) as $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/(?<![\p{L}\p{N}_])(?:'.$pattern.')(?![\p{L}\p{N}_])/iu', $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Screens one V2 AI block. An absence template requires a separately supplied, trusted
     * request for a named predicate; the AI's own wording never chooses a predicate or template.
     *
     * @param  array<string,mixed>  $block
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,string>|null  $absenceRequest
     * @return array{block:array<string,mixed>|null,rejected:bool,reason:string|null,omitted:bool}
     */
    public function screenAiBlock(array $block, array $coverage, array $records,
        ?array $absenceRequest = null, ?callable $nonAbsenceTemplate = null): array
    {
        if (($block['origin'] ?? null) !== 'docintel_ai'
            || ! $this->matchesProse((string) ($block['text'] ?? '').' '.(string) ($block['detail'] ?? ''))) {
            return ['block' => $block, 'rejected' => false, 'reason' => null, 'omitted' => false];
        }

        $replacement = $this->deterministicAbsence($coverage, $records, $absenceRequest);
        if ($replacement === null && $nonAbsenceTemplate !== null) {
            $candidate = $nonAbsenceTemplate($block);
            $originalCites = (array) ($block['cites'] ?? $block['sourceIds'] ?? []);
            $candidateCites = is_array($candidate) ? (array) ($candidate['cites'] ?? $candidate['sourceIds'] ?? []) : [];
            sort($originalCites);
            sort($candidateCites);
            if (is_array($candidate) && ($candidate['origin'] ?? null) === 'docintel_deterministic'
                && ($candidate['assertion'] ?? null) === 'derived'
                && is_string($candidate['template_id'] ?? null)
                && $originalCites !== [] && $candidateCites === $originalCites) {
                $replacement = $candidate;
            }
        }

        return ['block' => $replacement, 'rejected' => true, 'reason' => 'negative_claim',
            'omitted' => $replacement === null];
    }

    /** @param list<array<string,mixed>> $records @param array<string,string>|null $request @return array<string,mixed>|null */
    private function deterministicAbsence(array $coverage, array $records, ?array $request): ?array
    {
        // The only declared Stage A template uses the contract's named example predicate. New
        // predicates need their own explicit deterministic template and a separately reviewed scan.
        if (($request['template_id'] ?? null) !== 'absence.high_critical_risks'
            || ($request['predicate'] ?? null) !== 'risk_severity_in(high,critical)') {
            return null;
        }
        $check = $this->absenceCheck($coverage, $request['predicate'], (string) ($request['scope'] ?? ''),
            $records, static fn (array $record) => ($record['kind'] ?? null) === 'risk'
                && in_array(SeverityNormalizer::normalize($record['data']['severity'] ?? $record['severity'] ?? null),
                    ['high', 'critical'], true));
        if ($check === null) {
            return null;
        }

        return ['type' => 'finding', 'text' => 'No high or critical risks were identified.',
            'origin' => 'docintel_deterministic', 'assertion' => 'absent', 'ai_generated' => false,
            'template_id' => $request['template_id'], 'absence_check' => $check['absence_check']];
    }

    /**
     * @param  list<array<string,mixed>>  $records  all records under the current pipeline key
     * @return array<string,mixed>|null null means no absence statement may be emitted
     */
    public function absenceCheck(array $coverage, string $predicateId, string $scope,
        array $records, callable $matches): ?array
    {
        if (($coverage['state'] ?? null) !== 'complete' || $predicateId === '' || $scope === '') {
            return null;
        }
        foreach ($records as $record) {
            if (($record['provenance']['origin'] ?? null) === 'unknown') {
                return null;
            }
            if ($matches($record)) {
                return null;
            }
        }

        return ['origin' => 'docintel_deterministic', 'assertion' => 'absent',
            'absence_check' => ['predicate' => $predicateId, 'scope' => $scope, 'matched' => 0]];
    }
}
