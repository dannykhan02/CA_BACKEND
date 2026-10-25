<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Extraction\BasicTableInterpreter;
use App\Services\Extraction\StructuredExtractionStore;

/** Source-created observations; no model claim enters this namespace. */
final class PdfCategoricalCandidateCollector
{
    public const STRATEGY = 'categorical.v1';

    public function __construct(private StructuredExtractionStore $extractions,
        private BasicTableInterpreter $tables) {}

    public function collect(Document $document): array
    {
        if (! config('document_intelligence.pdf_table_structure')
            || ! config('document_intelligence.pdf_basic_table_structure')
            || $document->type !== 'PDF') {
            return ['tables' => 0, 'candidates' => 0];
        }
        $source = $this->extractions->active($document);
        $current = [];
        $tables = 0;
        if ($source !== null && str_contains($source->parserVersion, '+basic-grid.v1')) {
            foreach ($source->nodes as $node) {
                if (($node['type'] ?? null) !== 'table'
                    || ($node['meta']['structural_type'] ?? null) !== 'basic_relational') {
                    continue;
                }
                $view = $this->tables->interpret($source, $node['id']);
                if ($view === null || $view['analytical_status'] !== 'potential_only') {
                    continue;
                }
                $tables++;
                foreach ($view['potential_charts'] as $chart) {
                    $column = $chart['value_column'];
                    $header = $view['columns'][$column];
                    foreach ($view['rows'] as $row) {
                        if ($row['aggregation_role'] !== 'detail') {
                            continue;
                        }
                        $category = $row['cells'][0];
                        $value = $row['cells'][$column];
                        if (! is_string($category['cell_id']) || ! is_string($value['cell_id'])
                            || ! is_array($value['source']) || $value['parsed_value'] === null) {
                            continue;
                        }
                        $identity = hash('sha256', $document->file_hash.'|'.$value['cell_id']);
                        $sourceId = 'pdf-category:'.$identity;
                        $current[] = $sourceId;
                        DocumentEvidence::updateOrCreate([
                            'workspace_id' => $document->workspace_id,
                            'document_id' => $document->id,
                            'pipeline_key' => $document->ai_pipeline['key'] ?? 'legacy',
                            'source_id' => $sourceId,
                        ], ['identity' => $identity, 'kind' => 'table_category',
                            'data' => ['strategy' => self::STRATEGY, 'source_candidate' => true,
                                'label' => $category['raw_text'], 'value' => $value['raw_text'],
                                'unit' => $header['unit'], 'scale' => $header['scale'],
                                'period' => $view['period_source_text'] ?? '',
                                'subject' => '', 'source_file_hash' => $document->file_hash,
                                'table_id' => $view['table_id'], 'row_id' => $row['row_id'],
                                'category_cell_id' => $category['cell_id'],
                                'value_cell_id' => $value['cell_id'],
                                'header_cell_id' => $header['header_cell_id'],
                                'caption_cell_id' => $view['caption_cell_id'],
                                'scale_source_cell_id' => $header['scale_source_cell_id'],
                                'value_column' => $column],
                            'sources' => [['quote' => $value['raw_text'],
                                'start_offset' => $value['source']['start'],
                                'end_offset' => $value['source']['end'],
                                'page' => $value['source']['page'],
                                'span_id' => 'pdf-category-cell:'.hash('sha256', $value['cell_id'])]],
                        ]);
                    }
                }
            }
        }
        $stale = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->where('source_id', 'like', 'pdf-category:%');
        if ($current !== []) {
            $stale->whereNotIn('source_id', $current);
        }
        $stale->delete();

        return ['tables' => $tables, 'candidates' => count($current)];
    }
}
