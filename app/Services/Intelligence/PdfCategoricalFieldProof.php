<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Extraction\BasicTableInterpreter;
use App\Services\Extraction\StructuredExtractionStore;
use Illuminate\Support\Facades\DB;

/** Deterministic proof for one category/value occurrence in a published PDF graph. */
final class PdfCategoricalFieldProof
{
    public function __construct(private StructuredExtractionStore $extractions,
        private BasicTableInterpreter $tables, private SourceProvenanceReader $reader,
        private PeriodParser $periods) {}

    public static function fingerprint(array $data): string
    {
        return hash('sha256', json_encode(array_map(
            static fn ($key) => $data[$key] ?? null,
            ['strategy', 'label', 'value', 'unit', 'scale', 'period', 'subject',
                'source_file_hash', 'source_revision', 'source_provenance_set_id',
                'table_id', 'row_id', 'category_cell_id', 'value_cell_id',
                'header_cell_id', 'caption_cell_id', 'scale_source_cell_id', 'value_column']
        ), JSON_THROW_ON_ERROR));
    }

    public function prove(Document $document, DocumentEvidence $row, string $setId,
        ?array $provenance = null): array
    {
        $reject = static fn (string $code): array => ['rejection' => $code];
        if (! config('document_intelligence.pdf_table_structure')
            || ! config('document_intelligence.pdf_basic_table_structure')
            || $document->type !== 'PDF' || $row->kind !== 'table_category'
            || $row->workspace_id !== $document->workspace_id
            || $row->document_id !== $document->id
            || $row->pipeline_key !== ($document->ai_pipeline['key'] ?? 'legacy')) {
            return $reject('categorical_owner_mismatch');
        }
        $data = $row->data ?? [];
        $valueId = $data['value_cell_id'] ?? null;
        $identity = is_string($valueId) && is_string($document->file_hash)
            ? hash('sha256', $document->file_hash.'|'.$valueId) : null;
        $revision = hash('sha256', (string) $document->extracted_text);
        $live = DB::table('documents')->where('id', $document->id)
            ->where('workspace_id', $document->workspace_id)->whereNull('deleted_at')
            ->first(['file_hash', 'extracted_text', 'ai_pipeline']);
        $livePipeline = json_decode($live->ai_pipeline ?? '{}', true);
        $published = $document->published_intelligence_revision_id !== null
            && $document->intelligenceRevisionForRelations() === $document->published_intelligence_revision_id
            ? $document->publishedIntelligenceRevision : null;
        $publishedPipeline = $published?->workspace_id === $document->workspace_id
            && $published?->document_id === $document->id
            && $published?->state === 'published'
            && $published?->pipeline_key === $row->pipeline_key
            && $published?->file_hash === $document->file_hash
            && $published?->extracted_text_hash === $revision;
        if ($identity === null || $row->identity !== $identity
            || $row->source_id !== 'pdf-category:'.$identity
            || ($data['strategy'] ?? null) !== PdfCategoricalCandidateCollector::STRATEGY
            || ($data['source_file_hash'] ?? null) !== $document->file_hash
            || $live === null || $live->file_hash !== $document->file_hash
            || hash('sha256', (string) $live->extracted_text) !== $revision
            || (! $publishedPipeline && ($livePipeline['key'] ?? 'legacy') !== $row->pipeline_key)
            || (! $publishedPipeline && ! app(DocumentRevisionPublisher::class)->ownsPipeline($document,
                (string) $row->pipeline_key))) {
            return $reject('categorical_revision_mismatch');
        }
        $set = DB::table('source_provenance_sets')->where('id', $setId)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $row->pipeline_key)->where('source_revision', $revision)
            ->where('policy_version', SourceProvenance::POLICY)
            ->where('published', true)->where('active', true)->first(['id']);
        if ($set === null) {
            return $reject('categorical_provenance_unavailable');
        }
        $source = $this->extractions->active($document);
        if ($source === null || ! str_contains($source->parserVersion, '+basic-grid.v1')) {
            return $reject('categorical_structure_unavailable');
        }
        $tableId = $data['table_id'] ?? null;
        $view = is_string($tableId) ? $this->tables->interpret($source, $tableId) : null;
        if ($view === null || $view['analytical_status'] !== 'potential_only') {
            return $reject('categorical_table_ineligible');
        }
        $column = $data['value_column'] ?? null;
        if (! is_int($column) || ! in_array($column,
            array_column($view['potential_charts'], 'value_column'), true)) {
            return $reject('categorical_column_ambiguous');
        }
        $header = $view['columns'][$column] ?? null;
        $matchingRows = array_values(array_filter($view['rows'], static fn ($candidate) => $candidate['row_id'] === ($data['row_id'] ?? null)));
        $selected = $matchingRows[0] ?? null;
        $category = $selected['cells'][0] ?? null;
        $value = $selected['cells'][$column] ?? null;
        if (count($matchingRows) !== 1 || $selected['aggregation_role'] !== 'detail'
            || $header === null || $category === null || $value === null
            || $category['cell_id'] !== ($data['category_cell_id'] ?? null)
            || $value['cell_id'] !== $valueId
            || $header['header_cell_id'] !== ($data['header_cell_id'] ?? null)
            || $view['caption_cell_id'] !== ($data['caption_cell_id'] ?? null)
            || $header['scale_source_cell_id'] !== ($data['scale_source_cell_id'] ?? null)
            || $category['raw_text'] !== ($data['label'] ?? null)
            || $value['raw_text'] !== ($data['value'] ?? null)
            || $header['unit'] !== ($data['unit'] ?? null)
            || $header['scale'] !== ($data['scale'] ?? null)
            || ($view['period_source_text'] ?? '') !== ($data['period'] ?? '')
            || ($data['subject'] ?? '') !== '') {
            return $reject('categorical_source_claim_mismatch');
        }
        $proof = $provenance ?? ($this->reader->forEvidence($document, [$row->id], $setId)[$row->id] ?? []);
        $path = $proof['pdf_table_path'] ?? null;
        $role = $proof['roles'] ?? [];
        if (! is_array($path) || ($path['set_id'] ?? null) !== $setId
            || ! ($proof['capabilities']['structural'] ?? false)
            || count($path['headers'] ?? []) !== 1
            || count($path['captions'] ?? []) !== ($view['caption_cell_id'] === null ? 0 : 1)) {
            return $reject('categorical_relationship_unverified');
        }
        $expected = ['value' => [$valueId, 'cell', $value['raw_text']],
            'metric' => [$category['cell_id'], 'row_header', $category['raw_text']],
            'unit' => [$header['header_cell_id'], 'column_header', $header['raw_header']]];
        if (($data['period'] ?? '') !== ''
            && ($view['period'] === null || $this->periods->parse((string) $data['period'])?->label
                !== $view['period'])) {
            return $reject('categorical_period_mismatch');
        }
        if ($header['scale'] !== 1) {
            $scaleNode = $source->node($header['scale_source_cell_id']);
            if ($scaleNode === null) {
                return $reject('categorical_scale_unverified');
            }
            $expected['scale'] = [$scaleNode['id'], $scaleNode['type'] === 'cell'
                ? 'column_header' : 'section_heading', $scaleNode['text']];
        }
        foreach ($expected as $field => [$nodeId, $kind, $text]) {
            $bound = $role[$field] ?? [];
            if (count($bound) !== 1 || ($bound[0]['path'] ?? null) !== $nodeId
                || ($bound[0]['kind'] ?? null) !== $kind
                || ($bound[0]['text'] ?? null) !== $text) {
                return $reject('categorical_'.$field.'_anchor_mismatch');
            }
        }
        if (($path['row']['path'] ?? null) !== $category['cell_id']
            || ($path['headers'][0]['path'] ?? null) !== $header['header_cell_id']
            || ($path['table']['path'] ?? null) !== $tableId
            || ($view['caption_cell_id'] !== null
                && ($path['captions'][0]['path'] ?? null) !== $view['caption_cell_id'])) {
            return $reject('categorical_column_relationship_mismatch');
        }
        $nodes = array_column($source->nodes, null, 'id');
        $ids = [];
        foreach (array_unique(array_filter([$valueId, $category['cell_id'],
            $header['header_cell_id'], $view['caption_cell_id'], $header['scale_source_cell_id']])) as $nodeId) {
            $node = $nodes[$nodeId] ?? null;
            $anchor = DB::table('source_anchors')->where('set_id', $setId)
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('structural_path', $nodeId)->first();
            if ($node === null || $anchor === null || $anchor->source_text !== ($node['text'] ?? null)
                || $anchor->source_hash !== hash('sha256', (string) ($node['text'] ?? ''))
                || (int) $anchor->start_offset !== ($node['start'] ?? null)
                || (int) $anchor->end_offset !== ($node['end'] ?? null)
                || (int) $anchor->page !== ($node['page'] ?? null)
                || ! $this->geometryMatches($node, $anchor->geometry)) {
                return $reject('categorical_anchor_unavailable');
            }
            $ids[$nodeId] = $anchor->id;
        }
        $valueMatches = DB::table('source_anchors')->where('set_id', $setId)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('kind', 'cell')->where('start_offset', $value['source']['start'])
            ->where('end_offset', $value['source']['end'])->limit(2)->pluck('id')->all();
        if ($valueMatches !== [$ids[$valueId]]) {
            return $reject('categorical_navigation_ambiguous');
        }
        $tableAnchor = DB::table('source_anchors')->where('set_id', $setId)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('structural_path', $tableId)->where('kind', 'table')->first();
        if ($tableAnchor === null || $tableAnchor->source_text !== null) {
            return $reject('categorical_table_anchor_unavailable');
        }
        $edges = [[$ids[$valueId], $ids[$category['cell_id']], 'row_header'],
            [$ids[$valueId], $ids[$header['header_cell_id']], 'column_header'],
            [$ids[$valueId], $tableAnchor->id, 'table_parent']];
        if ($view['caption_cell_id'] !== null) {
            $edges[] = [$tableAnchor->id, $ids[$view['caption_cell_id']], 'caption'];
        }
        foreach ($edges as [$from, $to, $type]) {
            if (! DB::table('source_relationships')->where('set_id', $setId)
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('from_anchor_id', $from)->where('to_anchor_id', $to)
                ->where('type', $type)->exists()) {
                return $reject('categorical_provenance_edge_missing');
            }
        }
        $sources = [];
        foreach ($expected as $field => [$nodeId]) {
            $node = $nodes[$nodeId];
            $anchorId = $ids[$nodeId];
            if (! DB::table('evidence_role_bindings')->where('set_id', $setId)
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('evidence_id', $row->id)->where('anchor_id', $anchorId)
                ->where('role', $field)->exists()) {
                return $reject('categorical_role_binding_missing');
            }
            $sources[$anchorId] ??= ['span_id' => $anchorId, 'quote' => $node['text'],
                'page' => $node['page'], 'start_offset' => $node['start'],
                'end_offset' => $node['end'], 'roles' => [], 'source_graph_derived' => true];
            $sources[$anchorId]['roles'][] = $field;
        }
        if ($view['period'] !== null) {
            $captionId = $view['caption_cell_id'];
            $caption = $nodes[$captionId];
            $anchorId = $ids[$captionId];
            $sources[$anchorId] ??= ['span_id' => $anchorId, 'quote' => $caption['text'],
                'page' => $caption['page'], 'start_offset' => $caption['start'],
                'end_offset' => $caption['end'], 'roles' => [], 'source_graph_derived' => true];
            $sources[$anchorId]['roles'][] = 'period';
        }

        return ['rejection' => null, 'anchors' => ['value' => $ids[$valueId],
            'category' => $ids[$category['cell_id']], 'header' => $ids[$header['header_cell_id']],
            'caption' => $view['caption_cell_id'] === null ? null : $ids[$view['caption_cell_id']]],
            'sources' => array_values($sources), 'numeric_value' => $value['parsed_value'],
            'unit' => $header['unit'], 'scale' => $header['scale'],
            'period' => $view['period'], 'table' => $view];
    }

    private function geometryMatches(array $node, ?string $stored): bool
    {
        $box = $node['meta']['bbox'] ?? null;
        $width = $node['meta']['page_width'] ?? null;
        $height = $node['meta']['page_height'] ?? null;
        if (! is_array($box) || count($box) !== 4 || ! is_numeric($width)
            || ! is_numeric($height) || $stored === null) {
            return false;
        }
        $expected = ['system' => 'pdf_points_bottom_left',
            'box' => [$box[0], $height - $box[3], $box[2] - $box[0], $box[3] - $box[1]],
            'width' => $width, 'height' => $height, 'rotation' => 0];

        return json_decode($stored, true) == $expected;
    }
}
