<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

/** One bounded, workspace-scoped read for the evidence rows in a Stage A snapshot. */
class SourceProvenanceReader
{
    /** @param list<string> $evidenceIds @return array<string,array<string,mixed>> */
    public function forEvidence(Document $document, array $evidenceIds): array
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
                ->where('s.active', true)
                ->whereIn('b.evidence_id', $ids)
                ->get(['b.evidence_id', 'b.role', 'a.id as anchor_id', 'a.kind', 'a.modality',
                    'a.page', 'a.sheet', 'a.structural_path', 'a.start_offset', 'a.end_offset',
                    'a.source_text', 'a.source_hash']));
        }
        $out = [];
        $valueIds = [];
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
            }
        }
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
            $provenance['level'] = isset($provenance['roles']['value']) ? 'FIELD_ROLE' : 'TEXTUAL';
            $provenance['capabilities'] = ['textual' => true,
                'structural' => count(array_filter($provenance['roles']['value'] ?? [],
                    static fn ($anchor) => isset($structural[$anchor['anchor_id']]))) > 0,
                'field_roles' => array_keys($provenance['roles'])];
            $provenance['policy'] = SourceProvenance::POLICY;
        }
        unset($provenance);

        return $out;
    }
}
