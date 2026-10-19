<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Field-level integrity for a stored observation. Parsing, source and unit proof stay separate. */
class MeasurementIntegrity
{
    private array $sourceRevisions = [];

    public function __construct(private MeasurementParser $measurements) {}

    public function forEvidence(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        $owner = $row->workspace_id.':'.$row->document_id;
        if (! array_key_exists($owner, $this->sourceRevisions)) {
            $source = Document::where('workspace_id', $row->workspace_id)
                ->whereKey($row->document_id)->value('extracted_text');
            $this->sourceRevisions[$owner] = is_string($source)
                ? hash('sha256', $source) : 'missing_source_document';
        }
        $data['current_source_revision'] = $this->sourceRevisions[$owner];

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
        $fieldProof = is_array($metadata['source_fields_verified'] ?? null)
            ? $metadata['source_fields_verified'] : null;
        $currentRevision = $metadata['current_source_revision'] ?? null;
        $revisionMatches = $currentRevision === null || (is_string($metadata['source_revision'] ?? null)
            && hash_equals($currentRevision, $metadata['source_revision']));
        $sourceVerified = $trustedSource && $quotes !== []
            && $fieldProof !== null && in_array('value', $fieldProof, true) && $revisionMatches;
        $context = mb_strtolower(implode("\n", $quotes));
        $unit = trim((string) $rawUnit);
        $type = mb_strtolower(trim((string) ($metadata['metric_type'] ?? '')));
        $identifier = in_array($type, ['identifier', 'id', 'code', 'reference'], true);
        $ambiguous = preg_match('/^(?:m|k|m\s*\/\s*[a-z]+|m[²³]|m\^[23])$/iuD', $unit) === 1
            || ($measurement === null && preg_match('/\b(?:million|billion|trillion|thousand|bn|mn)\b/iu', $rawValue.' '.$unit) === 1);
        $dimensionless = $unit === '' && in_array($type, ['ratio', 'index'], true)
            && $sourceVerified && preg_match('/\b'.preg_quote($type, '/').'\b/iu', $context) === 1;
        $unitInSource = $unit !== '' && $sourceVerified && in_array('unit', $fieldProof, true);
        $unitState = $ambiguous ? 'scale_ambiguous'
            : ($dimensionless ? 'dimensionless' : ($unitInSource ? 'verified' : 'unit_unverified'));
        $numericState = $measurement === null ? 'invalid'
            : ($measurement->numericVerified ? 'parsed' : 'stored_numeric_legacy');
        $reasons = [];
        if (! $sourceVerified) {
            $reasons[] = 'missing_source_reference';
        }
        if (! $revisionMatches) {
            $reasons[] = 'source_revision_mismatch';
        }
        if ($unitState === 'unit_unverified') {
            $reasons[] = 'unit_unverified';
        }
        if ($unitState === 'scale_ambiguous') {
            $reasons[] = 'scale_ambiguous';
        }
        if ($numericState === 'invalid') {
            $reasons[] = 'invalid_numeric_value';
        }
        if ($numericState === 'stored_numeric_legacy') {
            $reasons[] = 'raw_value_unreadable';
        }
        if ($identifier) {
            $reasons[] = 'non_measurement_numeric';
        }
        if (($metadata['field_verification_policy'] ?? null) === 'incremental.v1') {
            foreach (['metric' => 'concept_source_mismatch', 'period' => 'period_source_mismatch',
                'subject' => 'subject_source_mismatch'] as $role => $reason) {
                $claim = $role === 'metric' ? $metadata['label'] ?? null : $metadata[$role] ?? null;
                if (($role === 'metric' || trim((string) $claim) !== '')
                    && ! in_array($role, $fieldProof ?? [], true)) {
                    $reasons[] = $reason;
                }
            }
        }
        $groundingRejection = is_string($metadata['grounding_rejection'] ?? null)
            ? trim($metadata['grounding_rejection']) : '';
        if ($groundingRejection !== '') {
            $reasons[] = $groundingRejection;
        }

        return [
            'numericVerification' => $numericState,
            'sourceVerification' => $sourceVerified ? 'verified' : 'unverified',
            'unitVerification' => $unitState,
            'analyticalEligible' => $numericState === 'parsed' && $sourceVerified
                && in_array($unitState, ['verified', 'dimensionless'], true)
                && ! $identifier && $groundingRejection === ''
                && ! array_intersect($reasons, ['concept_source_mismatch', 'period_source_mismatch',
                    'subject_source_mismatch']),
            'reasons' => $reasons,
        ];
    }
}
