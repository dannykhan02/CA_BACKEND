<?php

namespace App\Services\Intelligence;

/** The single admission gate for deterministic V2 absence claims. */
class NegativeClaimGuard
{
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
