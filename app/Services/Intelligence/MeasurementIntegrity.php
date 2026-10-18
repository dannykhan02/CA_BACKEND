<?php

namespace App\Services\Intelligence;

use App\Models\DocumentEvidence;

/** Field-level integrity for a stored observation. Parsing, source and unit proof stay separate. */
class MeasurementIntegrity
{
    public function __construct(private MeasurementParser $measurements) {}

    public function forEvidence(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        return $this->assess($this->measurements->parse($data['value'] ?? null, $data['unit'] ?? null,
            $data['label'] ?? null), (string) ($data['value'] ?? ''), $data['unit'] ?? null,
            $data, $row->sources ?? [], $row->kind === 'metric');
    }

    /** @param array<string,mixed> $metadata @param list<array<string,mixed>> $sources */
    public function assess(?Measurement $measurement, string $rawValue, ?string $rawUnit, array $metadata,
        array $sources, bool $trustedSource): array
    {
        $quotes = array_values(array_filter(array_map(
            fn ($source) => is_array($source) ? (string) ($source['quote'] ?? $source['text'] ?? '') : '', $sources
        )));
        $sourceVerified = $trustedSource && $quotes !== [];
        $context = mb_strtolower(implode("\n", $quotes));
        $unit = trim((string) $rawUnit);
        $type = mb_strtolower(trim((string) ($metadata['metric_type'] ?? '')));
        $ambiguous = preg_match('/^(?:m|k|m\s*\/\s*[a-z]+|m[²³]|m\^[23])$/iuD', $unit) === 1
            || ($measurement === null && preg_match('/\b(?:million|billion|trillion|thousand|bn|mn)\b/iu', $rawValue.' '.$unit) === 1);
        $dimensionless = $unit === '' && in_array($type, ['ratio', 'index'], true)
            && $sourceVerified && preg_match('/\b'.preg_quote($type, '/').'\b/iu', $context) === 1;
        $unitInSource = $unit !== '' && $sourceVerified
            && (str_contains($context, mb_strtolower($unit))
                || ($unit === '%' && str_contains($context, '%')));
        $unitState = $ambiguous ? 'scale_ambiguous'
            : ($dimensionless ? 'dimensionless' : ($unitInSource ? 'verified' : 'unit_unverified'));
        $numericState = $measurement === null ? 'invalid'
            : ($measurement->numericVerified ? 'parsed' : 'stored_numeric_legacy');
        $reasons = [];
        if (! $sourceVerified) $reasons[] = 'missing_source_reference';
        if ($unitState === 'unit_unverified') $reasons[] = 'unit_unverified';
        if ($unitState === 'scale_ambiguous') $reasons[] = 'scale_ambiguous';
        if ($numericState === 'invalid') $reasons[] = 'invalid_numeric_value';
        if ($numericState === 'stored_numeric_legacy') $reasons[] = 'raw_value_unreadable';

        return [
            'numericVerification' => $numericState,
            'sourceVerification' => $sourceVerified ? 'verified' : 'unverified',
            'unitVerification' => $unitState,
            'analyticalEligible' => $numericState === 'parsed' && $sourceVerified
                && in_array($unitState, ['verified', 'dimensionless'], true),
            'reasons' => $reasons,
        ];
    }
}
