<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

/** One bounded, workspace-scoped read for the evidence rows in a Stage A snapshot. */
class SourceProvenanceReader
{
    /** @param list<string> $evidenceIds @return array<string,array<string,mixed>> */
    public function forEvidence(Document $document, array $evidenceIds, ?string $setId = null): array
    {
        if ($evidenceIds === []) {
            return [];
        }
        $revision = hash('sha256', (string) $document->extracted_text);
        $pipeline = (string) ($document->ai_pipeline['key'] ?? 'legacy');
        $rows = collect();
        foreach (array_chunk($evidenceIds, 500) as $ids) {
            $rows = $rows->concat(DB::table('evidence_role_bindings as b')
                ->join('source_anchors as a', 'a.id', '=', 'b.anchor_id')
                ->join('source_provenance_sets as s', 's.id', '=', 'b.set_id')
                ->where('s.workspace_id', $document->workspace_id)->where('s.document_id', $document->id)
                ->where('s.source_revision', $revision)->where('s.pipeline_key', $pipeline)
                ->where('s.policy_version', SourceProvenance::POLICY)->where('s.published', true)
                ->when($setId !== null,
                    fn ($query) => $query->where('s.id', $setId),
                    fn ($query) => $query->when($document->published_intelligence_revision_id,
                        fn ($query) => $query->where('s.id', $document->publishedIntelligenceRevision?->provenance_set_id),
                        fn ($query) => $query->where('s.active', true)))
                ->whereIn('b.evidence_id', $ids)
                ->get(['b.evidence_id', 'b.role', 's.id as set_id', 'a.id as anchor_id', 'a.kind', 'a.modality',
                    'a.page', 'a.sheet', 'a.structural_path', 'a.start_offset', 'a.end_offset',
                    'a.source_text', 'a.source_hash']));
        }
        $out = [];
        $valueIds = [];
        $pdfValues = [];
        foreach ($rows as $row) {
            // A changed source location or content cannot retain a stronger capability.
            if ($row->start_offset !== null && mb_substr((string) $document->extracted_text,
                $row->start_offset, $row->end_offset - $row->start_offset) !== $row->source_text) {
                continue;
            }
            if ($row->source_text !== null && hash('sha256', $row->source_text) !== $row->source_hash) {
                continue;
            }
            $out[$row->evidence_id]['roles'][$row->role][] = [
                'anchor_id' => $row->anchor_id, 'text' => $row->source_text,
                'kind' => $row->kind, 'modality' => $row->modality,
                'page' => $row->page, 'sheet' => $row->sheet,
                'path' => $row->structural_path,
                'start_offset' => $row->start_offset, 'end_offset' => $row->end_offset,
            ];
            if ($row->role === 'value') {
                $valueIds[] = $row->anchor_id;
                if ($row->modality === 'structured_pdf' && $row->kind === 'cell') {
                    $pdfValues[$row->anchor_id] = ['evidence_id' => $row->evidence_id,
                        'set_id' => $row->set_id, 'path' => $row->structural_path];
                }
            }
        }
        $pdfPaths = $this->pdfPaths($document, $pdfValues);
        $structuralIds = [];
        foreach (array_chunk(array_values(array_unique($valueIds)), 500) as $ids) {
            array_push($structuralIds, ...DB::table('source_relationships')
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->whereIn('from_anchor_id', $ids)
                ->whereIn('type', ['row_header', 'column_header', 'table_parent'])
                ->distinct()->pluck('from_anchor_id')->all());
        }
        $structural = array_fill_keys($structuralIds, true);
        foreach ($out as &$provenance) {
            $value = $provenance['roles']['value'][0]['anchor_id'] ?? null;
            if (is_string($value) && isset($pdfPaths[$value])) {
                $provenance['pdf_table_path'] = $pdfPaths[$value];
            }
            $provenance['level'] = isset($provenance['roles']['value']) ? 'FIELD_ROLE' : 'TEXTUAL';
            $provenance['capabilities'] = ['textual' => true,
                'structural' => count(array_filter($provenance['roles']['value'] ?? [],
                    static fn ($anchor) => $anchor['modality'] === 'structured_pdf'
                        ? isset($pdfPaths[$anchor['anchor_id']])
                        : isset($structural[$anchor['anchor_id']]))) > 0,
                'field_roles' => array_keys($provenance['roles'])];
            $provenance['policy'] = SourceProvenance::POLICY;
        }
        unset($provenance);

        return $out;
    }

    /** Follow only edges in the value's published set; never search by nearby text. */
    private function pdfPaths(Document $document, array $values): array
    {
        if ($values === []) {
            return [];
        }
        $paths = [];
        $tableIds = [];
        foreach (array_chunk(array_keys($values), 500) as $ids) {
            $edges = DB::table('source_relationships as r')
                ->join('source_anchors as a', 'a.id', '=', 'r.to_anchor_id')
                ->where('r.workspace_id', $document->workspace_id)
                ->where('r.document_id', $document->id)
                ->whereIn('r.from_anchor_id', $ids)
                ->whereIn('r.type', ['row_header', 'column_header', 'table_parent'])
                ->get(['r.from_anchor_id', 'r.set_id', 'r.type', 'r.path_order',
                    'a.id as anchor_id', 'a.kind', 'a.modality', 'a.page', 'a.structural_path',
                    'a.start_offset', 'a.end_offset', 'a.source_text', 'a.source_hash']);
            foreach ($edges as $edge) {
                if ($edge->set_id !== $values[$edge->from_anchor_id]['set_id']
                    || ! $this->currentAnchor($document, $edge)) {
                    continue;
                }
                $paths[$edge->from_anchor_id][$edge->type][] = $this->anchor($edge);
                if ($edge->type === 'table_parent' && $edge->kind === 'table') {
                    $tableIds[$edge->anchor_id] = ['set_id' => $edge->set_id,
                        'path' => $edge->structural_path];
                }
            }
        }
        $headings = [];
        foreach (array_chunk(array_keys($tableIds), 500) as $ids) {
            $edges = DB::table('source_relationships as r')
                ->join('source_anchors as a', 'a.id', '=', 'r.to_anchor_id')
                ->where('r.workspace_id', $document->workspace_id)
                ->where('r.document_id', $document->id)
                ->whereIn('r.from_anchor_id', $ids)
                ->whereIn('r.type', ['caption', 'section_parent'])
                ->get(['r.from_anchor_id', 'r.set_id', 'r.type', 'r.path_order',
                    'a.id as anchor_id', 'a.kind', 'a.modality', 'a.page', 'a.structural_path',
                    'a.start_offset', 'a.end_offset', 'a.source_text', 'a.source_hash']);
            foreach ($edges as $edge) {
                $tablePath = $tableIds[$edge->from_anchor_id]['path'];
                if ($edge->set_id === $tableIds[$edge->from_anchor_id]['set_id']
                    && is_string($tablePath)
                    && is_string($edge->structural_path)
                    && str_starts_with($edge->structural_path, $tablePath.'/heading:')
                    && $this->currentAnchor($document, $edge)) {
                    $headings[$edge->from_anchor_id][$edge->type][] = $this->anchor($edge);
                }
            }
        }
        $valid = [];
        foreach ($paths as $valueId => $path) {
            if (count($path['row_header'] ?? []) !== 1
                || count($path['table_parent'] ?? []) !== 1
                || count($path['column_header'] ?? []) < 1
                || count($path['column_header'] ?? []) > 6) {
                continue;
            }
            $tableId = $path['table_parent'][0]['anchor_id'];
            $headers = array_values(array_filter($path['column_header'],
                static fn ($anchor) => $anchor['path_order'] >= 1 && $anchor['path_order'] <= 4));
            $currencies = array_values(array_filter($path['column_header'],
                static fn ($anchor) => $anchor['path_order'] >= 10));
            usort($headers, static fn ($a, $b) => $a['path_order'] <=> $b['path_order']);
            usort($currencies, static fn ($a, $b) => $a['path_order'] <=> $b['path_order']);
            if ($headers === [] || array_column($headers, 'path_order') !== range(1, count($headers))
                || ($currencies !== [] && (array_column($currencies, 'path_order') !== [10, 11]
                    || count(array_unique(array_column($currencies, 'text'))) !== 1
                    || $currencies[0]['start_offset'] === $currencies[1]['start_offset']))) {
                continue;
            }
            $tablePath = $path['table_parent'][0]['path'];
            $valuePath = $values[$valueId]['path'];
            $valueColumn = null;
            if (is_string($valuePath)
                && preg_match('~/row\[\d+\]/cell\[(\d+)\]$~D', $valuePath, $match)) {
                $valueColumn = (int) $match[1];
            }
            if (! is_string($tablePath)
                || $valueColumn === null || $valueColumn < 1
                || $path['row_header'][0]['path']
                    !== preg_replace('~/cell\[\d+\]$~D', '/cell[0]', $valuePath)
                || count(array_filter([$path['row_header'][0], ...$headers, ...$currencies],
                    static fn ($anchor) => ! is_string($anchor['path'])
                        || ! str_starts_with($anchor['path'], $tablePath.'/')
                        || $anchor['page'] !== $path['table_parent'][0]['page'])) > 0
                || count(array_filter($headers,
                    static fn ($anchor) => ! str_starts_with($anchor['path'], $tablePath.'/row[0]/'))) > 0
                || count(array_filter($currencies,
                    static fn ($anchor) => ! str_ends_with($anchor['path'],
                        '/currency['.$valueColumn.']'))) > 0) {
                continue;
            }
            $valid[$valueId] = ['row' => $path['row_header'][0], 'headers' => $headers,
                'currencies' => $currencies,
                'table' => $path['table_parent'][0],
                'captions' => $headings[$tableId]['caption'] ?? [],
                'entities' => $headings[$tableId]['section_parent'] ?? [],
                'set_id' => $values[$valueId]['set_id']];
        }

        return $valid;
    }

    private function currentAnchor(Document $document, object $anchor): bool
    {
        if ($anchor->kind === 'table' && $anchor->modality === 'structured_pdf') {
            return $anchor->start_offset === null && $anchor->end_offset === null
                && is_string($anchor->structural_path) && $anchor->structural_path !== '';
        }

        return is_int($anchor->start_offset) && is_int($anchor->end_offset)
            && is_string($anchor->source_text)
            && mb_substr((string) $document->extracted_text, $anchor->start_offset,
                $anchor->end_offset - $anchor->start_offset) === $anchor->source_text
            && hash('sha256', $anchor->source_text) === $anchor->source_hash;
    }

    private function anchor(object $anchor): array
    {
        return ['anchor_id' => $anchor->anchor_id, 'text' => $anchor->source_text,
            'kind' => $anchor->kind, 'modality' => $anchor->modality,
            'page' => $anchor->page, 'path' => $anchor->structural_path,
            'start_offset' => $anchor->start_offset, 'end_offset' => $anchor->end_offset,
            'path_order' => (int) $anchor->path_order];
    }
}
