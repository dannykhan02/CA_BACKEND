<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use Illuminate\Support\Collection;

/** Hides only independently verified model/source repeats from presentation. */
final class VerifiedPdfFindingPresenter
{
    /** @param list<MetricObservation> $observations */
    public function reconcile(Document $document, Collection $evidence, array $observations): array
    {
        if (! config('document_intelligence.pdf_table_structure') || $document->type !== 'PDF') {
            return ['rows' => $evidence, 'equivalents' => [], 'hidden' => []];
        }
        $byId = [];
        foreach ($observations as $observation) {
            $byId[$observation->sourceId] = $observation;
        }
        $revision = hash('sha256', (string) $document->extracted_text);
        $groups = [];
        foreach ($evidence as $row) {
            if ($row->kind !== 'metric' || ! is_string($row->source_id)) {
                continue;
            }
            $data = $row->data ?? [];
            $observation = $byId[$row->source_id] ?? null;
            if ($observation === null || ! $observation->integrity['analyticalEligible']
                || ($data['measurement_verification'] ?? null) !== 'verified'
                || ($data['source_revision'] ?? null) !== $revision
                || ! is_string($data['pdf_value_anchor_id'] ?? null)
                || ($data['source_claim_fingerprint'] ?? null) !== StructuredPdfFieldProof::claimFingerprint($data)
                || ! is_string($observation->measurement->exactMagnitude)
                || $observation->period === null) {
                continue;
            }
            $key = json_encode([
                mb_strtolower(trim(preg_replace('/\s+/u', ' ', $observation->label))),
                $observation->measurement->exactMagnitude,
                $observation->period->label, $observation->measurement->kind,
                $observation->measurement->family, $observation->measurement->currency,
                mb_strtolower(trim((string) ($data['accounting_basis'] ?? ''))),
                mb_strtolower(trim((string) ($data['value_basis'] ?? ''))),
                mb_strtolower(rtrim(trim($observation->subject), '.')),
                mb_strtolower((string) ($observation->integrity['governingOwner'] ?? '')),
                $revision, $data['source_provenance_set_id'] ?? null,
                $data['pdf_value_anchor_id'],
            ], JSON_THROW_ON_ERROR);
            $groups[$key][str_starts_with($row->source_id, 'pdf-cell:') ? 'source' : 'model'][] = $row->source_id;
        }
        $hidden = [];
        $equivalents = [];
        foreach ($groups as $group) {
            if (empty($group['source']) || empty($group['model'])) {
                continue;
            }
            sort($group['source']);
            sort($group['model']);
            $preferred = $group['source'][0];
            $equivalents[$preferred] = $group['model'];
            foreach ($group['model'] as $id) {
                $hidden[$id] = true;
            }
        }

        return ['rows' => $evidence->filter(static fn (DocumentEvidence $row) =>
            ! isset($hidden[$row->source_id ?? '']))->values(),
            'equivalents' => $equivalents, 'hidden' => array_keys($hidden)];
    }
}
