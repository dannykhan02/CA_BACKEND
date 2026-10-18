<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use Illuminate\Support\Facades\DB;

/** Bounded read of the exact provenance occurrence used by verification. */
class SourceReferenceResolver
{
    private const MAX_TEXT = 512;

    private const MAX_ROLES = 12;

    private const MAX_RELATIONS = 24;

    public function resolve(Document $document, string $sourceId): ?array
    {
        if (strlen($sourceId) > 200 || $sourceId === '') {
            return null;
        }
        if (! in_array($document->status, ['Ready', 'Needs Review'], true)
            || ($document->published_intelligence_revision_id === null && (int) $document->intelligence_generation > 0)) {
            return ['state' => 'NOT_READY'];
        }
        $pipeline = (string) ($document->ai_pipeline['key'] ?? 'legacy');
        $owner = DB::table('document_evidence')->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('source_id', $sourceId);
        $matches = (clone $owner)->where('pipeline_key', $pipeline)
            ->limit(2)->get(['id', 'source_id', 'sources', 'data']);
        if ($matches->count() > 1) {
            return ['state' => 'SOURCE_AMBIGUOUS'];
        }
        $evidence = $matches->first();
        if ($evidence === null) {
            if ($document->published_intelligence_revision_id === null) {
                return (clone $owner)->exists() ? ['state' => 'STALE_REFERENCE']
                    : $this->legacy($document, $sourceId);
            }
            $historical = DB::table('document_evidence as e')
                ->join('document_intelligence_revisions as r', function ($join): void {
                    $join->on('r.document_id', '=', 'e.document_id')
                        ->on('r.workspace_id', '=', 'e.workspace_id')
                        ->on('r.pipeline_key', '=', 'e.pipeline_key');
                })->where('e.workspace_id', $document->workspace_id)->where('e.document_id', $document->id)
                ->where('e.source_id', $sourceId)->where('r.state', 'published')->exists();

            return $historical ? ['state' => 'STALE_REFERENCE'] : $this->legacy($document, $sourceId);
        }
        $revision = hash('sha256', (string) $document->extracted_text);
        $published = $document->published_intelligence_revision_id
            ? DocumentIntelligenceRevision::whereKey($document->published_intelligence_revision_id)
                ->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->first()
            : null;
        $setQuery = DB::table('source_provenance_sets')->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $pipeline)
            ->where('source_revision', $revision)->where('policy_version', SourceProvenance::POLICY)
            ->where('published', true);
        $set = $published
            ? ($published->provenance_set_id ? $setQuery->where('id', $published->provenance_set_id)->first(['id']) : null)
            : $setQuery->where('active', true)->first(['id']);
        // Structural context can be present in the pinned graph without a role
        // binding or a one-hop relationship in this response. A changed hash
        // anywhere in that graph invalidates its provenance as a whole.
        if ($set !== null && DB::table('source_anchors')
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('set_id', $set->id)
            ->whereRaw("source_hash IS DISTINCT FROM encode(sha256(convert_to(source_text, 'UTF8')), 'hex')")
            ->exists()) {
            return ['state' => 'SOURCE_UNAVAILABLE'];
        }
        $roles = [];
        $relationships = [];
        if ($set !== null) {
            $bound = DB::table('evidence_role_bindings as b')
                ->join('source_anchors as a', 'a.id', '=', 'b.anchor_id')
                ->where('b.workspace_id', $document->workspace_id)->where('b.document_id', $document->id)
                ->where('b.set_id', $set->id)->where('b.evidence_id', $evidence->id)
                ->orderBy('b.role')->orderBy('a.id')->limit(self::MAX_ROLES + 1)
                ->get(['b.role', 'a.id', 'a.kind', 'a.modality', 'a.page', 'a.sheet',
                    'a.structural_path', 'a.start_offset', 'a.end_offset', 'a.source_hash',
                    'a.navigation_metadata', 'a.geometry', DB::raw('left(a.source_text, '.self::MAX_TEXT.') as preview'),
                    DB::raw('char_length(a.source_text) as source_length')]);
            if ($bound->count() > self::MAX_ROLES) {
                return ['state' => 'SOURCE_TOO_COMPLEX'];
            }
            foreach ($bound as $anchor) {
                if (! $this->matchesDocument($document, $anchor)) {
                    return ['state' => 'SOURCE_UNAVAILABLE'];
                }
                $roles[$anchor->role][] = $this->anchor($anchor);
            }
            $ids = $bound->pluck('id')->all();
            if ($ids !== []) {
                $edges = DB::table('source_relationships as r')
                    ->join('source_anchors as a', 'a.id', '=', 'r.to_anchor_id')
                    ->where('r.workspace_id', $document->workspace_id)->where('r.document_id', $document->id)
                    ->where('r.set_id', $set->id)->whereIn('r.from_anchor_id', $ids)
                    ->whereIn('r.type', ['contained_by', 'row_header', 'column_header', 'table_parent',
                        'section_parent', 'caption', 'footnote', 'continuation'])
                    ->orderBy('r.from_anchor_id')->orderBy('r.path_order')->orderBy('r.to_anchor_id')
                    ->limit(self::MAX_RELATIONS + 1)
                    ->get(['r.from_anchor_id', 'r.type', 'a.id', 'a.kind', 'a.modality', 'a.page', 'a.sheet',
                        'a.structural_path', 'a.start_offset', 'a.end_offset', 'a.source_hash',
                        'a.navigation_metadata', 'a.geometry', DB::raw('left(a.source_text, '.self::MAX_TEXT.') as preview'),
                        DB::raw('char_length(a.source_text) as source_length')]);
                foreach ($edges->take(self::MAX_RELATIONS) as $edge) {
                    if (! $this->matchesDocument($document, $edge)) {
                        return ['state' => 'SOURCE_UNAVAILABLE'];
                    }
                    $relationships[] = ['fromAnchorId' => $edge->from_anchor_id, 'type' => $edge->type,
                        'anchor' => $this->anchor($edge)];
                }
            }
        }
        $sources = json_decode($evidence->sources, true) ?: [];
        $fieldSources = [];
        $valueSources = [];
        foreach ($sources as $candidate) {
            if (! is_array($candidate) || ! is_array($candidate['roles'] ?? null)) {
                continue;
            }
            $start = $candidate['start_offset'] ?? null;
            $end = $candidate['end_offset'] ?? null;
            $quote = $candidate['quote'] ?? null;
            if (! is_int($start) || ! is_int($end) || ! is_string($quote) || $end <= $start
                || mb_substr((string) $document->extracted_text, $start, $end - $start) !== $quote) {
                return ['state' => 'SOURCE_UNAVAILABLE'];
            }
            $fieldSources[] = ['roles' => $candidate['roles'], 'page' => $candidate['page'] ?? null,
                'range' => ['start' => $start, 'end' => $end], 'text' => mb_substr($quote, 0, self::MAX_TEXT)];
            if (in_array('value', $candidate['roles'], true)) {
                $valueSources[] = $candidate;
            }
        }
        // A field-grounded measurement can cite its value row plus governing headings.
        // Navigation chooses the single value occurrence; all field quotes remain visible.
        if ($fieldSources !== []) {
            if (count($fieldSources) !== count($sources) || count($valueSources) !== 1) {
                return ['state' => 'SOURCE_AMBIGUOUS'];
            }
            $sources = $valueSources;
        }
        // A source ID can carry several genuine citations. Returning the
        // first one would silently choose an occurrence the user did not ask
        // for, especially when the same phrase appears on several pages.
        $occurrences = [];
        foreach ($sources as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $occurrences[json_encode([$candidate['page'] ?? null,
                $candidate['start_offset'] ?? null, $candidate['end_offset'] ?? null,
                $candidate['quote'] ?? null])] = true;
            if (count($occurrences) > 1) {
                return ['state' => 'SOURCE_AMBIGUOUS'];
            }
        }
        $citation = $sources[0] ?? [];
        $quote = is_string($citation['quote'] ?? null) ? $citation['quote'] : null;
        $start = $citation['start_offset'] ?? null;
        $end = $citation['end_offset'] ?? null;
        $rangeValid = is_int($start) && is_int($end) && $end > $start && $quote !== null
            && mb_substr((string) $document->extracted_text, $start, $end - $start) === $quote;
        $location = null;
        if ($set !== null && $rangeValid) {
            // Exact projection ownership is safe even where a hidden or formula cell could not
            // acquire a semantic field-role binding. Ambiguous projections remain textual.
            $matches = DB::table('source_anchors')->where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)->where('set_id', $set->id)
                ->whereIn('kind', ['cell', 'worksheet_cell'])
                ->where('start_offset', $start)->where('end_offset', $end)
                ->limit(2)->get(['id', 'kind', 'modality', 'page', 'sheet', 'structural_path',
                    'start_offset', 'end_offset', 'source_hash', 'navigation_metadata', 'geometry',
                    DB::raw('left(source_text, '.self::MAX_TEXT.') as preview'),
                    DB::raw('char_length(source_text) as source_length')]);
            if ($matches->count() === 1 && hash('sha256', $quote) === $matches[0]->source_hash) {
                $location = $this->anchor($matches[0]);
            }
        }
        $structural = false;
        foreach ($roles['value'] ?? [] as $value) {
            if (in_array($value['type'], ['cell', 'worksheet_cell'], true)
                && collect($relationships)->contains(fn ($r) => $r['fromAnchorId'] === $value['id']
                    && in_array($r['type'], ['table_parent', 'column_header', 'row_header'], true))) {
                $structural = true;
                break;
            }
        }
        $matchState = $structural ? 'STRUCTURALLY_RESOLVED'
            : ($rangeValid ? 'EXACT' : 'UNAVAILABLE');
        if (! $rangeValid && $quote !== null && $quote !== '') {
            $firstOccurrence = mb_strpos((string) $document->extracted_text, $quote);
            if ($firstOccurrence !== false) {
                $matchState = mb_strpos((string) $document->extracted_text, $quote,
                    $firstOccurrence + mb_strlen($quote)) === false ? 'APPROXIMATE' : 'AMBIGUOUS';
            }
        }

        $data = json_decode($evidence->data, true) ?: [];
        $conflict = $data['source_conflict']['status'] ?? null;
        $channel = $roles['value'][0]['modality'] ?? $location['modality'] ?? null;
        $evidenceStatus = $conflict !== null || in_array($matchState, ['AMBIGUOUS', 'UNAVAILABLE'], true)
            ? 'unresolved' : ($structural ? 'supported_structural_partial' : 'supported_textual');

        return [
            'state' => 'CURRENT', 'sourceId' => $sourceId, 'documentId' => $document->id,
            'matchState' => $matchState,
            'intelligenceRevisionId' => $published?->id,
            'sourceFormat' => in_array($document->type, ['DOCX', 'XLSX', 'PDF'], true) ? $document->type : 'OTHER',
            'revision' => $revision, 'provenancePolicy' => $set === null ? null : SourceProvenance::POLICY,
            'capability' => $structural ? 'STRUCTURAL_PARTIAL' : 'TEXTUAL',
            'source' => ['text' => $quote === null ? null : mb_substr($quote, 0, self::MAX_TEXT),
                'length' => $quote === null ? null : mb_strlen($quote),
                'truncated' => $quote !== null && mb_strlen($quote) > self::MAX_TEXT,
                'page' => $citation['page'] ?? null,
                'range' => $rangeValid ? ['start' => $start, 'end' => $end] : null],
            'location' => $location,
            'fieldSources' => $fieldSources,
            'roles' => $roles, 'relationships' => $relationships,
            'quality' => ['sourceChannel' => $channel,
                'locationQuality' => $rangeValid ? 'exact_text_offset' : strtolower($matchState),
                'structuralRelationshipQuality' => $structural ? 'verified_partial' : 'unavailable',
                'ambiguity' => $matchState === 'AMBIGUOUS' ? 'repeated_occurrence' : null,
                'ocrUncertainty' => $channel === 'ocr' ? 'unverified_reading_order_and_glyphs' : null,
                'language' => null, 'locale' => null, 'conflictStatus' => $conflict,
                'evidenceStatus' => $evidenceStatus],
            'limits' => ['sourceTextChars' => self::MAX_TEXT, 'roleAnchors' => self::MAX_ROLES,
                'relationships' => self::MAX_RELATIONS, 'relationshipsTruncated' => isset($edges)
                    && $edges->count() > self::MAX_RELATIONS],
        ];
    }

    private function anchor(object $row): array
    {
        return ['id' => $row->id, 'type' => $row->kind, 'modality' => $row->modality,
            'page' => $row->page, 'sheet' => $row->sheet, 'path' => $row->structural_path,
            'range' => $row->start_offset === null ? null
                : ['start' => (int) $row->start_offset, 'end' => (int) $row->end_offset],
            'text' => $row->preview, 'textLength' => $row->source_length === null ? null : (int) $row->source_length,
            'textTruncated' => $row->source_length > self::MAX_TEXT,
            'geometry' => $row->geometry === null ? null : json_decode($row->geometry, true),
            'metadata' => $row->navigation_metadata === null ? null : json_decode($row->navigation_metadata, true)];
    }

    private function matchesDocument(Document $document, object $anchor): bool
    {
        if ($anchor->start_offset === null) {
            return true;
        }

        return is_string($anchor->source_hash)
            && hash('sha256', mb_substr((string) $document->extracted_text,
                (int) $anchor->start_offset, (int) $anchor->end_offset - (int) $anchor->start_offset))
                === $anchor->source_hash;
    }

    /** Historical typed evidence has no structural anchor or trustworthy raw occurrence. */
    private function legacy(Document $document, string $sourceId): ?array
    {
        if (! preg_match('/^(risk|deadline|entity|kpi):([1-9][0-9]{0,18})$/D', $sourceId, $match)) {
            return null;
        }
        [$table, $field] = match ($match[1]) {
            'risk' => ['document_risks', 'evidence'],
            'deadline' => ['document_deadlines', 'evidence'],
            'entity' => ['document_entities', 'context'],
            'kpi' => ['document_kpis', null],
        };
        $query = DB::table($table)->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('intelligence_revision_id', $document->intelligenceRevisionForRelations())
            ->where('id', $match[2]);
        $row = $query->first($field === null ? ['id'] : ['id', $field]);
        if ($row === null) {
            $historical = DB::table($table.' as t')
                ->join('document_intelligence_revisions as r', 'r.id', '=', 't.intelligence_revision_id')
                ->where('t.workspace_id', $document->workspace_id)->where('t.document_id', $document->id)
                ->where('t.id', $match[2])->where('r.state', 'published')->exists();

            return $historical ? ['state' => 'STALE_REFERENCE'] : null;
        }
        $text = $field === null ? null : $row->$field;
        $policy = $document->published_intelligence_revision_id
            ? DocumentIntelligenceRevision::whereKey($document->published_intelligence_revision_id)
                ->value('provenance_policy') : null;

        return ['state' => 'CURRENT', 'sourceId' => $sourceId, 'documentId' => $document->id,
            'matchState' => 'UNAVAILABLE',
            'intelligenceRevisionId' => $document->published_intelligence_revision_id,
            'sourceFormat' => in_array($document->type, ['DOCX', 'XLSX', 'PDF'], true) ? $document->type : 'OTHER',
            'revision' => null, 'provenancePolicy' => $policy, 'capability' => 'TEXTUAL',
            'source' => ['text' => $text === null ? null : mb_substr($text, 0, self::MAX_TEXT),
                'length' => $text === null ? null : mb_strlen($text),
                'truncated' => $text !== null && mb_strlen($text) > self::MAX_TEXT,
                'page' => null, 'range' => null],
            'location' => null, 'roles' => [], 'relationships' => [],
            'quality' => ['sourceChannel' => null, 'locationQuality' => 'unavailable',
                'structuralRelationshipQuality' => 'unavailable', 'ambiguity' => null,
                'ocrUncertainty' => null, 'language' => null, 'locale' => null,
                'conflictStatus' => null, 'evidenceStatus' => 'unresolved'],
            'limits' => ['sourceTextChars' => self::MAX_TEXT, 'roleAnchors' => self::MAX_ROLES,
                'relationships' => self::MAX_RELATIONS, 'relationshipsTruncated' => false]];
    }
}
