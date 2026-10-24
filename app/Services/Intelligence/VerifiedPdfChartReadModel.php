<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentEvidence;
use App\Services\Extraction\StructuredExtractionStore;
use Illuminate\Support\Facades\DB;

/** Rechecks persisted PDF charts against current, independently verified source facts. */
final class VerifiedPdfChartReadModel
{
    private array $candidates = [];

    public function __construct(private MetricCollector $metrics,
        private ChartCandidateBuilder $builder,
        private StructuredExtractionStore $extractions) {}

    public function candidate(Document $document, DocumentChart $chart): ?array
    {
        $metadata = $chart->source_metadata;
        if (! config('document_intelligence.pdf_table_structure') || $document->type !== 'PDF'
            || ! is_array($metadata) || ($metadata['origin'] ?? null) !== 'verified_pdf_table'
            || $chart->workspace_id !== $document->workspace_id || $chart->document_id !== $document->id
            || $chart->intelligence_revision_id !== $document->intelligenceRevisionForRelations()
            || $document->published_intelligence_revision_id !== null
                && $chart->intelligence_revision_id !== $document->published_intelligence_revision_id) {
            return null;
        }
        $revision = hash('sha256', (string) $document->extracted_text);
        $pipeline = $document->ai_pipeline['key'] ?? null;
        $setId = $metadata['provenance_set_id'] ?? null;
        $candidateId = $metadata['candidate_id'] ?? null;
        $current = DB::table('documents')->where('id', $document->id)
            ->where('workspace_id', $document->workspace_id)->whereNull('deleted_at')
            ->first(['file_hash', 'extracted_text']);
        if (! is_string($pipeline) || ! is_string($setId) || ! is_string($candidateId)
            || ($metadata['source_revision'] ?? null) !== $revision
            || ($metadata['pipeline_key'] ?? null) !== $pipeline
            || $current === null || $current->file_hash !== $document->file_hash
            || hash('sha256', (string) $current->extracted_text) !== $revision
            || ! DB::table('source_provenance_sets')->where('id', $setId)
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('pipeline_key', $pipeline)->where('source_revision', $revision)
                ->where('published', true)->where('active', true)->exists()
            || $document->published_intelligence_revision_id !== null
                && ! DB::table('document_intelligence_revisions')
                    ->where('id', $document->published_intelligence_revision_id)
                    ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                    ->where('state', 'published')->where('provenance_set_id', $setId)->exists()) {
            return null;
        }
        $source = $this->extractions->active($document);
        if ($source === null || ! str_contains($source->parserVersion, '+poppler-grid.v1')) {
            return null;
        }
        $key = $document->workspace_id.'|'.$document->id.'|'.$pipeline.'|'.$revision;
        if (! isset($this->candidates[$key])) {
            $eligible = array_values(array_filter($this->metrics->collect($document)['observations'],
                static fn (MetricObservation $item) => $item->integrity['analyticalEligible']));
            $this->candidates[$key] = collect($this->builder->build($eligible)['candidates'])
                ->keyBy('id')->all();
        }
        $candidate = $this->candidates[$key][$candidateId] ?? null;
        if ($candidate === null || ($candidate['basis'] ?? null) !== 'time_series'
            || ($candidate['sourceVerification'] ?? null) !== 'verified'
            || ($candidate['unitVerification'] ?? null) !== 'verified'
            || ($candidate['numericVerification'] ?? null) !== 'parsed') {
            return null;
        }
        $expected = $candidate['points'] ?: ($candidate['series'][0]['data'] ?? []);
        $points = $chart->data ?? [];
        if (count($expected) < 2 || count($expected) !== count($points)) {
            return null;
        }
        foreach ($points as $index => $point) {
            $reference = $point['source'] ?? null;
            unset($point['source']);
            $sourceId = $point['sourceIds'][0] ?? null;
            if ($point != $expected[$index] || ! is_array($reference) || ! is_string($sourceId)
                || ! is_string($reference['evidence_id'] ?? null)
                || ! is_string($reference['anchor_id'] ?? null)) {
                return null;
            }
            $evidence = DocumentEvidence::whereKey($reference['evidence_id'])
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('pipeline_key', $pipeline)->where('source_id', $sourceId)->first();
            $data = $evidence?->data ?? [];
            if ($evidence === null || ($data['measurement_verification'] ?? null) !== 'verified'
                || ($data['source_provenance_set_id'] ?? null) !== $setId
                || ($data['source_revision'] ?? null) !== $revision
                || ($data['source_file_hash'] ?? null) !== $document->file_hash
                || ($data['source_claim_fingerprint'] ?? null) !== StructuredPdfFieldProof::claimFingerprint($data)
                || ($data['pdf_value_anchor_id'] ?? null) !== $reference['anchor_id']
                || ($data['value'] ?? null) !== ($point['sourceValue'] ?? null)) {
                return null;
            }
            $anchor = DB::table('source_anchors')->where('id', $reference['anchor_id'])
                ->where('set_id', $setId)->where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)->where('kind', 'cell')->first();
            if ($anchor === null || $anchor->source_text !== $point['sourceValue']
                || $anchor->source_hash !== hash('sha256', $anchor->source_text)
                || (int) $anchor->page !== ($reference['page'] ?? null)
                || (int) $anchor->start_offset !== ($reference['start_offset'] ?? null)
                || (int) $anchor->end_offset !== ($reference['end_offset'] ?? null)
                || mb_substr((string) $document->extracted_text, (int) $anchor->start_offset,
                    (int) $anchor->end_offset - (int) $anchor->start_offset) !== $anchor->source_text
                || ! DB::table('evidence_role_bindings')->where('set_id', $setId)
                    ->where('workspace_id', $document->workspace_id)
                    ->where('document_id', $document->id)->where('evidence_id', $evidence->id)
                    ->where('anchor_id', $anchor->id)->where('role', 'value')->exists()) {
                return null;
            }
        }

        return $candidate;
    }
}
