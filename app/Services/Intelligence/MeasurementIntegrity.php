<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\Kpis\KpiIdentityProfile;

/** Field-level integrity for a stored observation. Parsing, source and unit proof stay separate. */
class MeasurementIntegrity
{
    private array $sourceRevisions = [];

    private array $extractionVersions = [];

    private array $fileHashes = [];

    public function __construct(private MeasurementParser $measurements) {}

    public function forEvidence(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        $owner = $row->workspace_id.':'.$row->document_id;
        if (! array_key_exists($owner, $this->sourceRevisions)) {
            $document = Document::where('workspace_id', $row->workspace_id)
                ->whereKey($row->document_id)->first();
            $this->sourceRevisions[$owner] = $document !== null
                ? hash('sha256', (string) $document->extracted_text) : 'missing_source_document';
            $this->extractionVersions[$owner] = $document === null ? null
                : app(EvidenceGrounding::class)->version($document);
            $this->fileHashes[$owner] = $document?->file_hash;
        }
        $data['current_source_revision'] = $this->sourceRevisions[$owner];
        $data['current_extraction_version'] = $this->extractionVersions[$owner];
        $data['current_file_hash'] = $this->fileHashes[$owner];
        // Source ownership is a property of the claim, not of the route that
        // happened to extract it. Incremental evidence must pass the same gate.
        $data['ownership_enforced'] = true;

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
        $sourceRevision = $metadata['source_revision'] ?? null;
        $revisionProofMissing = $currentRevision !== null && (! is_string($sourceRevision) || $sourceRevision === '');
        $revisionMatches = $currentRevision === null || (! $revisionProofMissing
            && is_string($sourceRevision) && hash_equals($currentRevision, $sourceRevision));
        $fileMatches = ! is_string($metadata['source_file_hash'] ?? null)
            || ($metadata['current_file_hash'] ?? null) === $metadata['source_file_hash'];
        $fingerprint = $metadata['source_claim_fingerprint'] ?? null;
        $claimMatches = ! is_string($fingerprint)
            || hash_equals($fingerprint,
                is_string($metadata['source_claim_current_fingerprint'] ?? null)
                    ? $metadata['source_claim_current_fingerprint']
                    : StructuredPdfFieldProof::claimFingerprint([
                        ...$metadata, 'value' => $rawValue, 'unit' => $rawUnit,
                    ]));
        $sourceVerified = $trustedSource && $quotes !== []
            && $fieldProof !== null && in_array('value', $fieldProof, true)
            && ! $revisionProofMissing && $revisionMatches && $fileMatches && $claimMatches;
        $ownershipEnforced = ($metadata['ownership_enforced'] ?? false) === true;
        $ownershipRequirement = ($metadata['ownership_requirement'] ?? null) === 'company'
            || app(GoverningOwnerProof::class)->companyClaim($metadata, $rawUnit)
            ? 'company' : 'population';
        $ownerProof = is_array($metadata['governing_owner'] ?? null)
            ? $metadata['governing_owner'] : null;
        $ownerVerified = $sourceVerified && $ownershipRequirement === 'company'
            && in_array('governing_owner', $fieldProof ?? [], true)
            && $this->validOwnerProof($ownerProof, $sources, $sourceRevision,
                $metadata['source_extraction_version'] ?? null,
                $metadata['current_extraction_version'] ?? null,
                $metadata['source_provenance_set_id'] ?? null,
                $metadata['subject'] ?? $metadata['scope'] ?? null);
        $populationVerified = $sourceVerified && $ownershipRequirement === 'population'
            && trim((string) ($metadata['subject'] ?? $metadata['scope'] ?? '')) !== ''
            && in_array('subject', $fieldProof ?? [], true);
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
        if (! $claimMatches) {
            $reasons[] = 'source_claim_changed';
        }
        if (! $fileMatches) {
            $reasons[] = 'source_file_hash_mismatch';
        }
        if ($revisionProofMissing) {
            $reasons[] = 'revision_proof_missing';
        } elseif (! $revisionMatches) {
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
        if ($ownershipEnforced && $ownershipRequirement === 'company' && ! $ownerVerified) {
            $reasons[] = 'governing_owner_unverified';
        } elseif ($ownershipEnforced && $ownershipRequirement === 'population' && ! $populationVerified) {
            $reasons[] = 'population_unverified';
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
            'ownerVerification' => $ownerVerified ? 'verified' : 'unverified',
            'governingOwner' => $ownerVerified ? $ownerProof['entity'] : null,
            'ownershipRequirement' => $ownershipRequirement,
            'analyticalEligible' => $numericState === 'parsed' && $sourceVerified
                && in_array($unitState, ['verified', 'dimensionless'], true)
                && (! $ownershipEnforced || $ownerVerified || $populationVerified)
                && ! $identifier && $groundingRejection === ''
                && ! array_intersect($reasons, ['concept_source_mismatch', 'period_source_mismatch',
                    'subject_source_mismatch']),
            'reasons' => $reasons,
        ];
    }

    private function validOwnerProof(?array $proof, array $sources, mixed $revision,
        mixed $extractionVersion, mixed $currentExtractionVersion,
        mixed $provenanceSetId, mixed $claimedSubject): bool
    {
        if ($proof === null || ! is_string($revision) || $revision === ''
            || ($proof['source_revision'] ?? null) !== $revision
            || ! is_string($extractionVersion) || $extractionVersion === ''
            || ($proof['extraction_version'] ?? null) !== $extractionVersion
            || ($currentExtractionVersion !== null && $extractionVersion !== $currentExtractionVersion)
            || ($proof['kind'] ?? null) !== 'organization'
            || ! is_string($proof['entity'] ?? null) || trim($proof['entity']) === ''
            || ! in_array($proof['relationship'] ?? null,
                ['table_caption', 'value_row', 'cited_section_to_table',
                    'reporting_entity_section_table', 'structured_table_heading',
                    'structured_report_owner'], true)
            || ! is_array($proof['source_spans'] ?? null) || $proof['source_spans'] === []) {
            return false;
        }
        if (($proof['relationship'] ?? null) === 'structured_table_heading'
            && (! is_string($provenanceSetId) || $provenanceSetId === ''
                || ($proof['source_provenance_set_id'] ?? null) !== $provenanceSetId
                || ! is_array($proof['source_anchor_ids'] ?? null)
                || count($proof['source_anchor_ids'] ?? []) !== 4
                || ! is_string($claimedSubject)
                || mb_strtolower(rtrim(trim($claimedSubject), '.'))
                    !== mb_strtolower(rtrim(trim($proof['entity']), '.'))
                || array_column($proof['source_spans'], 'span_id') !== [
                    $proof['source_anchor_ids'][0], $proof['source_anchor_ids'][2],
                    $proof['source_anchor_ids'][3],
                ])) {
            return false;
        }
        if (($proof['relationship'] ?? null) === 'structured_report_owner'
            && (! is_string($provenanceSetId) || $provenanceSetId === ''
                || ($proof['source_provenance_set_id'] ?? null) !== $provenanceSetId
                || ! is_array($proof['source_anchor_ids'] ?? null)
                || count($proof['source_anchor_ids'] ?? []) !== 5
                || ! is_string($claimedSubject)
                || mb_strtolower(rtrim(trim($claimedSubject), '.'))
                    !== mb_strtolower(rtrim(trim($proof['entity']), '.'))
                || array_column($proof['source_spans'], 'span_id') !== [
                    $proof['source_anchor_ids'][0], $proof['source_anchor_ids'][1],
                    $proof['source_anchor_ids'][3], $proof['source_anchor_ids'][4],
                ])) {
            return false;
        }
        $byId = [];
        foreach ($sources as $source) {
            if (is_array($source) && is_string($source['span_id'] ?? null)) {
                $byId[$source['span_id']] = $source;
            }
        }
        $entitySeen = false;
        foreach ($proof['source_spans'] as $reference) {
            if (! is_array($reference) || ! is_string($reference['span_id'] ?? null)) {
                return false;
            }
            $source = $byId[$reference['span_id']] ?? null;
            if ($source === null || ! in_array('governing_owner', $source['roles'] ?? [], true)
                || ($source['page'] ?? null) !== ($reference['page'] ?? null)
                || ($source['start_offset'] ?? null) !== ($reference['start_offset'] ?? null)
                || ($source['end_offset'] ?? null) !== ($reference['end_offset'] ?? null)) {
                return false;
            }
            $entitySeen = $entitySeen || preg_match('/(?<![\p{L}\p{N}])'
                .preg_quote($proof['entity'], '/').'(?![\p{L}\p{N}])/iu',
                (string) ($source['quote'] ?? '')) === 1;
        }

        return $entitySeen;
    }

    /** Apply the same KPI identity and period gates to API cards and collected observations. */
    public function forKpiIdentity(array $integrity, ?ReportingPeriod $period, array $identity,
        string $label, ?string $unit, string $value): array
    {
        if ($period === null) {
            $integrity['analyticalEligible'] = false;
            $integrity['reasons'][] = 'period_unavailable';
        }
        if (array_diff_key($identity, ['period' => true]) !== []
            && ! app(KpiIdentityProfile::class)->make([
                'label' => $label, 'unit' => $unit, 'value' => $value, 'identity' => $identity,
            ])['valid']) {
            $integrity['analyticalEligible'] = false;
            $integrity['reasons'][] = 'semantic_identity_conflict';
        }
        $integrity['reasons'] = array_values(array_unique($integrity['reasons']));

        return $integrity;
    }
}
