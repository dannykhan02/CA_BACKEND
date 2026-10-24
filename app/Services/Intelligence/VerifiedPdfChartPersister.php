<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentEvidence;
use App\Services\Extraction\StructuredExtractionStore;
use Illuminate\Support\Facades\DB;

/** Persists only chart candidates whose PDF cell proofs survived the normal integrity gates. */
final class VerifiedPdfChartPersister
{
    public function __construct(private MetricCollector $metrics,
        private ChartCandidateBuilder $builder,
        private StructuredExtractionStore $extractions) {}

    public function persist(Document $document, ?string $setId): array
    {
        if (! config('document_intelligence.pdf_table_structure') || $document->type !== 'PDF') {
            return ['charts' => 0, 'points' => 0];
        }
        if (! is_string($setId) || $setId === '') {
            return $this->retract($document);
        }
        $source = $this->extractions->active($document);
        if ($source === null || ! str_contains($source->parserVersion, '+poppler-grid.v1')) {
            return $this->retract($document);
        }
        $eligible = array_values(array_filter($this->metrics->collect($document)['observations'],
            static fn (MetricObservation $item) => $item->integrity['analyticalEligible']));
        $candidates = array_values(array_filter($this->builder->build($eligible)['candidates'],
            static fn ($chart) => ($chart['basis'] ?? null) === 'time_series'
                && ($chart['sourceVerification'] ?? null) === 'verified'
                && ($chart['unitVerification'] ?? null) === 'verified'
                && ($chart['numericVerification'] ?? null) === 'parsed'));
        if ($candidates === []) {
            return $this->retract($document);
        }
        $sourceIds = array_values(array_unique(array_merge(...array_column($candidates, 'sourceIds'))));
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->whereIn('source_id', $sourceIds)->get()->keyBy('source_id');
        $references = [];
        foreach ($sourceIds as $sourceId) {
            $row = $rows[$sourceId] ?? null;
            $data = $row?->data ?? [];
            $valueSources = array_values(array_filter($row?->sources ?? [],
                static fn ($source) => in_array('value', $source['roles'] ?? [], true)
                    && is_string($source['span_id'] ?? null)
                    && is_int($source['start_offset'] ?? null)));
            if ($row === null || ($data['measurement_verification'] ?? null) !== 'verified'
                || ($data['source_provenance_set_id'] ?? null) !== $setId
                || ($data['source_revision'] ?? null) !== hash('sha256', (string) $document->extracted_text)
                || ! is_string($data['source_claim_fingerprint'] ?? null)
                || count($valueSources) !== 1) {
                return $this->retract($document);
            }
            if (($data['pdf_value_anchor_id'] ?? null) !== $valueSources[0]['span_id']) {
                return $this->retract($document);
            }
            $references[$sourceId] = ['evidence_id' => $row->id,
                'anchor_id' => $valueSources[0]['span_id'],
                'page' => $valueSources[0]['page'],
                'start_offset' => $valueSources[0]['start_offset'],
                'end_offset' => $valueSources[0]['end_offset']];
        }

        return DB::transaction(function () use ($document, $setId, $candidates, $references): array {
            $current = Document::where('workspace_id', $document->workspace_id)
                ->whereKey($document->id)->lockForUpdate()->first();
            if ($current === null || $current->file_hash !== $document->file_hash
                || hash('sha256', (string) $current->extracted_text)
                    !== hash('sha256', (string) $document->extracted_text)
                || ! app(DocumentRevisionPublisher::class)->ownsPipeline($current,
                    (string) ($document->ai_pipeline['key'] ?? 'legacy'))) {
                return ['charts' => 0, 'points' => 0];
            }
            DocumentChart::where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)
                ->where('source_metadata->origin', 'verified_pdf_table')->delete();
            $counts = ['charts' => 0, 'points' => 0];
            foreach (array_slice($candidates, 0, 12) as $candidate) {
                $points = $candidate['points'] ?: ($candidate['series'][0]['data'] ?? []);
                if (count($points) < 2 || count($candidate['series'] ?? []) > 1) {
                    continue;
                }
                $pointData = [];
                foreach ($points as $point) {
                    $sourceId = $point['sourceIds'][0] ?? null;
                    if (! is_string($sourceId) || ! isset($references[$sourceId])
                        || ! is_numeric($point['value'] ?? null)) {
                        $pointData = [];
                        break;
                    }
                    $pointData[] = [...$point, 'source' => $references[$sourceId]];
                }
                if (count($pointData) < 2) {
                    continue;
                }
                $chart = DocumentChart::create([
                    'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                    'intelligence_revision_id' => $document->intelligenceRevisionForRelations(),
                    'type' => $candidate['type'], 'title' => $candidate['title'],
                    'description' => $candidate['description'], 'data' => $pointData,
                    'source_metadata' => ['origin' => 'verified_pdf_table',
                        'source_revision' => hash('sha256', (string) $document->extracted_text),
                        'provenance_set_id' => $setId, 'pipeline_key' => $document->ai_pipeline['key'],
                        'candidate_id' => $candidate['id'], 'source_references' => $references],
                ]);
                foreach ($pointData as $order => $point) {
                    DocumentChartPoint::create(['document_chart_id' => $chart->id,
                        'workspace_id' => $document->workspace_id, 'label' => $point['label'],
                        'value' => $point['value'], 'sort_order' => $order]);
                    $counts['points']++;
                }
                $counts['charts']++;
            }

            return $counts;
        });
    }

    private function retract(Document $document): array
    {
        return DB::transaction(function () use ($document): array {
            $current = Document::where('workspace_id', $document->workspace_id)
                ->whereKey($document->id)->lockForUpdate()->first();
            if ($current !== null && app(DocumentRevisionPublisher::class)->ownsPipeline($current,
                (string) ($document->ai_pipeline['key'] ?? 'legacy'))) {
                DocumentChart::where('workspace_id', $document->workspace_id)
                    ->where('document_id', $document->id)
                    ->where('source_metadata->origin', 'verified_pdf_table')
                    ->where('source_metadata->pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
                    ->delete();
            }

            return ['charts' => 0, 'points' => 0];
        });
    }
}
