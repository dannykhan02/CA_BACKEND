<?php

namespace App\Services\Intelligence;

/** V1 finding classes shared by legacy selection and V2 materiality. */
class FindingClassifier
{
    /** The direct classes already recognized by the V1 usefulness table. */
    private const DIRECT_CLASSES = ['critical_risk', 'high_risk', 'upcoming_obligation',
        'dated_obligation', 'undated_obligation', 'metric', 'fact', 'definition', 'entity', 'other'];

    /** @param array<string,mixed> $data */
    public function classify(string $kind, array $data, \DateTimeInterface $asOf): string
    {
        if ($kind === 'risk') {
            return match (SeverityNormalizer::normalize($data['severity'] ?? null)) {
                'critical' => 'critical_risk',
                'high' => 'high_risk',
                default => 'risk',
            };
        }
        if ($kind === 'deadline' || $kind === 'obligation') {
            $due = $this->explicitDueDate($data);
            if ($due === null) {
                // Relative or inferred timing is still an obligation; it has no calendar date.
                return 'undated_obligation';
            }

            return $due >= $asOf->format('Y-m-d') ? 'upcoming_obligation' : 'dated_obligation';
        }

        return in_array($kind, self::DIRECT_CLASSES, true) ? $kind : 'other';
    }

    /** An explicit calendar date only, exactly as the extraction schema guarantees it. */
    public function explicitDueDate(array $data): ?string
    {
        $due = is_string($data['due_date'] ?? null) ? trim($data['due_date']) : '';

        return ($data['date_type'] ?? null) === 'explicit' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) ? $due : null;
    }
}
