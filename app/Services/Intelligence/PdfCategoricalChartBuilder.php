<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Extraction\BasicTableInterpreter;
use App\Services\Extraction\StructuredExtractionStore;

/** Produces categorical candidates only from a complete set of current proofs. */
final class PdfCategoricalChartBuilder
{
    public function __construct(private StructuredExtractionStore $extractions,
        private BasicTableInterpreter $tables, private PdfCategoricalFieldVerifier $verifier,
        private SourceProvenanceReader $reader) {}

    public function build(Document $document, string $setId): array
    {
        if (! config('document_intelligence.pdf_table_structure')
            || ! config('document_intelligence.pdf_basic_table_structure')
            || $document->type !== 'PDF') {
            return [];
        }
        $source = $this->extractions->active($document);
        if ($source === null || ! str_contains($source->parserVersion, '+basic-grid.v1')) {
            return [];
        }
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->where('kind', 'table_category')->get()->keyBy('source_id');
        $proofs = $this->reader->forEvidence($document, $rows->pluck('id')->all(), $setId);
        $charts = [];
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) !== 'table'
                || ($node['meta']['structural_type'] ?? null) !== 'basic_relational') {
                continue;
            }
            $view = $this->tables->interpret($source, $node['id']);
            if ($view === null || $view['analytical_status'] !== 'potential_only') {
                continue;
            }
            $detail = array_values(array_filter($view['rows'], static fn ($row) =>
                $row['aggregation_role'] === 'detail'));
            if (count($detail) < 2 || count($detail) > 30) {
                continue;
            }
            foreach ($view['potential_charts'] as $potential) {
                $column = $potential['value_column'];
                $header = $view['columns'][$column];
                $points = [];
                $references = [];
                foreach ($detail as $item) {
                    $value = $item['cells'][$column];
                    $sourceId = 'pdf-category:'.hash('sha256',
                        $document->file_hash.'|'.$value['cell_id']);
                    $evidence = $rows[$sourceId] ?? null;
                    $verified = $evidence === null ? null : $this->verifier->current($document,
                        $evidence, $setId, $proofs[$evidence->id] ?? []);
                    if ($verified === null) {
                        $points = [];
                        break;
                    }
                    $anchor = $verified['anchors'];
                    $points[] = ['label' => $item['cells'][0]['raw_text'],
                        'value' => $verified['numeric_value'], 'sourceValue' => $value['raw_text'],
                        'sourceUnit' => $header['unit'], 'sourceScale' => $header['scale'],
                        'sourceIds' => [$sourceId], 'page' => $value['source']['page']];
                    $references[$sourceId] = ['evidence_id' => $evidence->id,
                        'anchor_id' => $anchor['value'], 'category_anchor_id' => $anchor['category'],
                        'header_anchor_id' => $anchor['header'], 'caption_anchor_id' => $anchor['caption'],
                        'page' => $value['source']['page'],
                        'start_offset' => $value['source']['start'],
                        'end_offset' => $value['source']['end']];
                }
                if (count($points) !== count($detail)) {
                    continue;
                }
                $title = $view['caption'] ?? $header['raw_header'];
                $id = 'pdf-category-chart:'.hash('sha256', $document->file_hash.'|'
                    .$view['table_id'].'|'.$column.'|'.PdfCategoricalCandidateCollector::STRATEGY);
                $charts[] = ['id' => $id, 'type' => 'bar', 'basis' => 'categorical',
                    'title' => $title, 'description' => $header['raw_header'],
                    'unit' => $header['unit'], 'scale' => $header['scale'],
                    'unitKind' => $header['role'], 'sourceUnit' => $header['unit'],
                    'tableId' => $view['table_id'], 'valueColumn' => $column,
                    'period' => $view['period'], 'points' => $points,
                    'sourceIds' => array_keys($references), 'references' => $references,
                    'verificationStrategy' => PdfCategoricalCandidateCollector::STRATEGY];
            }
        }

        return array_slice($charts, 0, 12);
    }
}
