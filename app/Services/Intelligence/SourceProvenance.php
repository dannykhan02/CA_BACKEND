<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Source-owned structural evidence. Only parser-established edges may enter this store. */
class SourceProvenance
{
    public const POLICY = 'provenance.v1';

    private const KINDS = ['text_span', 'paragraph', 'table', 'table_row', 'table_column', 'cell',
        'row_header', 'column_header',
        'section_heading', 'caption', 'footnote', 'worksheet_cell', 'ocr_region'];

    private const RELATIONS = ['contained_by', 'row_header', 'column_header', 'table_parent',
        'section_parent', 'caption', 'footnote', 'continuation'];

    private const ROLES = ['entity', 'subject', 'metric', 'value', 'currency', 'unit', 'scale',
        'period', 'qualifier', 'direction', 'numerator', 'denominator'];

    /**
     * Publish a whole graph in one transaction. Keys describe occurrences, never text identity.
     * Inputs must come from a parser or a deterministic raw-source locator, never model output.
     *
     * @param  list<array<string,mixed>>  $anchors
     * @param  list<array{from:string,to:string,type:string,path_order?:int}>  $relations
     * @param  list<array{evidence_id:string,anchor:string,role:string}>  $bindings
     */
    public function publish(Document $document, string $parserVersion, array $anchors, array $relations,
        array $bindings): string
    {
        app(StructuralProvenanceValidator::class)->validate($anchors, $relations, $bindings);
        $revision = hash('sha256', (string) $document->extracted_text);
        $pipeline = (string) ($document->ai_pipeline['key'] ?? 'legacy');
        $graphHash = $this->graphHash($anchors, $relations, $bindings);
        if ($document->workspace_id === null || $parserVersion === '' || strlen($parserVersion) > 64) {
            throw new \InvalidArgumentException('Incomplete source provenance owner.');
        }

        return DB::transaction(function () use ($document, $parserVersion, $anchors, $relations, $bindings,
            $revision, $pipeline, $graphHash): string {
            // Serializes two workers publishing the same revision; a failed build rolls back in full.
            $current = DB::table('documents')->where('id', $document->id)
                ->where('workspace_id', $document->workspace_id)->lockForUpdate()
                ->first(['id', 'extracted_text', 'ai_pipeline', 'status', 'deleted_at',
                    'active_intelligence_revision_id', 'intelligence_generation']);
            $currentPipeline = $current === null ? null : json_decode($current->ai_pipeline ?? '{}', true);
            if ($current === null || $current->deleted_at !== null
                || ! in_array($current->status, ['Processing', 'Ready'], true)
                || hash('sha256', (string) $current->extracted_text) !== $revision
                || (string) ($currentPipeline['key'] ?? 'legacy') !== $pipeline) {
                throw new \LogicException('Stale provenance publisher cannot activate a source revision.');
            }
            if (! app(DocumentRevisionPublisher::class)->ownsPipeline(Document::whereKey($document->id)->firstOrFail(), $pipeline)) {
                throw new \LogicException('Stale generation cannot publish provenance.');
            }
            $existing = DB::table('source_provenance_sets')->where('document_id', $document->id)
                ->where('workspace_id', $document->workspace_id)->where('source_revision', $revision)
                ->where('parser_version', $parserVersion)->where('policy_version', self::POLICY)
                ->where('pipeline_key', $pipeline)->first(['id', 'published', 'active', 'graph_hash']);
            if ($existing !== null) {
                if (! $existing->published) {
                    throw new \LogicException('An unpublished provenance set already exists.');
                }
                if ($existing->graph_hash !== $graphHash) {
                    throw new \LogicException('The same source revision has a different provenance graph.');
                }
                if (! $existing->active) {
                    DB::table('source_provenance_sets')->where('document_id', $document->id)
                        ->where('pipeline_key', $pipeline)->where('active', true)->update(['active' => false]);
                    DB::table('source_provenance_sets')->where('id', $existing->id)->update(['active' => true]);
                }

                return $existing->id;
            }
            if (hash('sha256', (string) DB::table('documents')->where('id', $document->id)
                ->value('extracted_text')) !== $revision) {
                throw new \LogicException('Extracted source changed during provenance publication.');
            }

            $setId = (string) Str::uuid();
            $owner = ['set_id' => $setId, 'workspace_id' => $document->workspace_id,
                'document_id' => $document->id];
            DB::table('source_provenance_sets')->insert(['id' => $setId, 'workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'source_revision' => $revision,
                'parser_version' => $parserVersion, 'policy_version' => self::POLICY,
                'graph_hash' => $graphHash,
                'pipeline_key' => $pipeline, 'published' => false, 'active' => false,
                'created_at' => now(), 'updated_at' => now()]);
            $ids = [];
            $anchorByKey = [];
            $anchorRows = [];
            foreach ($anchors as $anchor) {
                $key = $anchor['key'] ?? null;
                if (! is_string($key) || $key === '' || strlen($key) > 200 || isset($ids[$key])
                    || ! in_array($anchor['kind'] ?? null, self::KINDS, true)
                    || ! in_array($anchor['modality'] ?? null, ['embedded_text', 'structured_pdf', 'docx',
                        'xlsx', 'ocr', 'visual_table'], true)) {
                    throw new \InvalidArgumentException('Invalid or duplicate source occurrence.');
                }
                $start = $anchor['start_offset'] ?? null;
                $end = $anchor['end_offset'] ?? null;
                $source = $anchor['text'] ?? null;
                $path = $anchor['path'] ?? null;
                if (($start === null) !== ($end === null)
                    || ($start === null && (! is_string($path) || trim($path) === '')
                        && ! isset($anchor['geometry']))
                    || (in_array($anchor['kind'], ['table', 'cell', 'row_header', 'column_header', 'worksheet_cell'], true)
                        && (! is_string($path) || trim($path) === ''))
                    || ($start !== null && (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start
                        || ! is_string($source)
                        || mb_substr((string) $document->extracted_text, $start, $end - $start) !== $source))
                    || ($source !== null && ! is_string($source))
                    || (isset($anchor['geometry']) && ! isset($anchor['page']))
                    || (isset($anchor['geometry']) && ! $this->validGeometry($anchor['geometry']))) {
                    throw new \InvalidArgumentException('Source anchor does not match the raw source.');
                }
                $ids[$key] = (string) Str::uuid();
                $anchorByKey[$key] = $anchor;
                $anchorRows[] = $owner + ['id' => $ids[$key], 'source_key' => $key,
                    'kind' => $anchor['kind'], 'modality' => $anchor['modality'],
                    'page' => $anchor['page'] ?? null, 'sheet' => $anchor['sheet'] ?? null,
                    'structural_path' => $anchor['path'] ?? null,
                    'start_offset' => $start, 'end_offset' => $end, 'source_text' => $source,
                    'source_hash' => $source === null ? null : hash('sha256', $source),
                    'geometry' => isset($anchor['geometry']) ? json_encode($anchor['geometry'], JSON_THROW_ON_ERROR) : null,
                    'navigation_metadata' => isset($anchor['navigation_metadata'])
                        ? json_encode($anchor['navigation_metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($anchorRows, 500) as $batch) {
                DB::table('source_anchors')->insert($batch);
            }
            $edges = [];
            $relationRows = [];
            foreach ($relations as $relation) {
                $from = $ids[$relation['from'] ?? ''] ?? null;
                $to = $ids[$relation['to'] ?? ''] ?? null;
                $type = $relation['type'] ?? null;
                if ($from === null || $to === null || $from === $to || ! in_array($type, self::RELATIONS, true)) {
                    throw new \InvalidArgumentException('Invalid structural relationship.');
                }
                $signature = implode('|', [$from, $to, $type]);
                if (isset($edges[$signature])) {
                    throw new \InvalidArgumentException('Duplicate structural relationship.');
                }
                $edges[$signature] = true;
                $relationRows[] = $owner + ['from_anchor_id' => $from,
                    'to_anchor_id' => $to, 'type' => $type, 'path_order' => $relation['path_order'] ?? 0];
            }
            foreach (array_chunk($relationRows, 500) as $batch) {
                DB::table('source_relationships')->insert($batch);
            }
            $evidenceIds = array_values(array_unique(array_column($bindings, 'evidence_id')));
            $owned = collect();
            foreach (array_chunk($evidenceIds, 500) as $batch) {
                $owned = $owned->concat(DB::table('document_evidence')->where('document_id', $document->id)
                    ->where('workspace_id', $document->workspace_id)->where('pipeline_key', $pipeline)
                    ->whereIn('id', $batch)->get(['id', 'data']));
            }
            $owned = $owned->keyBy('id');
            if (count($owned) !== count($evidenceIds)) {
                throw new \InvalidArgumentException('A role binding belongs to another source owner.');
            }
            $seen = [];
            $bindingRows = [];
            foreach ($bindings as $binding) {
                $anchorId = $ids[$binding['anchor'] ?? ''] ?? null;
                if ($anchorId === null || ! in_array($binding['role'] ?? null, self::ROLES, true)) {
                    throw new \InvalidArgumentException('Invalid field-role binding.');
                }
                $data = json_decode($owned[$binding['evidence_id']]->data, true, flags: JSON_THROW_ON_ERROR);
                $field = match ($binding['role']) {
                    'metric' => $data['label'] ?? null,
                    'period' => $data['period'] ?? null,
                    'value' => $data['value'] ?? null,
                    'subject' => $data['subject'] ?? null,
                    'unit' => $data['unit'] ?? null,
                    default => null,
                };
                $anchor = $anchorByKey[$binding['anchor']];
                if ($binding['role'] === 'value'
                    && in_array($anchor['kind'], ['cell', 'worksheet_cell'], true)
                    && preg_match_all('/(?<!\d)\d+(?:[,.]\d+)*/u', (string) ($anchor['text'] ?? '')) > 1) {
                    throw new \InvalidArgumentException('Multi-value cell requires a value subspan.');
                }
                if (is_string($field) && trim($field) !== ''
                    && preg_match('/(?<![\p{L}\p{N}])'
                        .preg_quote(app(MatchingTextNormalizer::class)->fold($field), '/')
                        .'(?![\p{L}\p{N}])/u',
                        app(MatchingTextNormalizer::class)->fold((string) ($anchor['text'] ?? ''))) !== 1) {
                    throw new \InvalidArgumentException('Field role contradicts the canonical record.');
                }
                $signature = implode('|', [$binding['evidence_id'], $anchorId, $binding['role']]);
                if (isset($seen[$signature])) {
                    throw new \InvalidArgumentException('Duplicate field-role binding.');
                }
                $seen[$signature] = true;
                $bindingRows[] = $owner + ['evidence_id' => $binding['evidence_id'],
                    'anchor_id' => $anchorId, 'role' => $binding['role']];
            }
            foreach (array_chunk($bindingRows, 500) as $batch) {
                DB::table('evidence_role_bindings')->insert($batch);
            }
            DB::table('source_provenance_sets')->where('document_id', $document->id)
                ->where('pipeline_key', $pipeline)->where('active', true)->update(['active' => false]);
            DB::table('source_provenance_sets')->where('id', $setId)->update(['published' => true, 'active' => true,
                'updated_at' => now()]);

            return $setId;
        });
    }

    private function graphHash(array $anchors, array $relations, array $bindings): string
    {
        usort($anchors, static fn ($a, $b) => strcmp($a['key'] ?? '', $b['key'] ?? ''));
        usort($relations, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        usort($bindings, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

        return hash('sha256', json_encode([$anchors, $relations, $bindings], JSON_THROW_ON_ERROR));
    }

    /** Geometry is auditable only with an explicit page and coordinate system. */
    private function validGeometry(mixed $geometry): bool
    {
        return is_array($geometry) && isset($geometry['system'], $geometry['box'])
            && in_array($geometry['system'], ['pdf_points_bottom_left', 'image_pixels_top_left'], true)
            && is_array($geometry['box']) && count($geometry['box']) === 4
            && count(array_filter($geometry['box'], 'is_numeric')) === 4
            && isset($geometry['width'], $geometry['height'], $geometry['rotation']);
    }
}
