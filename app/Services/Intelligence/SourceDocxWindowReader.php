<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Services\Extraction\StructuredDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Reads the immutable DOCX extraction pinned to the published intelligence revision. */
class SourceDocxWindowReader
{
    private const MAX_STORED_NODES_BYTES = 8 * 1024 * 1024;

    private const MAX_RESPONSE_BYTES = 65536;

    public function read(Document $document, Document $view, DocumentIntelligenceRevision $revision, array $source): array
    {
        $textHash = hash('sha256', (string) $view->extracted_text);
        if (($source['matchState'] ?? null) === 'AMBIGUOUS') {
            throw new DomainException('SOURCE_AMBIGUOUS');
        }
        if ($source['limits']['relationshipsTruncated'] ?? true) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        if (($source['state'] ?? null) !== 'CURRENT'
            || ($source['documentId'] ?? null) !== $document->id
            || ($source['sourceFormat'] ?? null) !== 'DOCX'
            || ($source['intelligenceRevisionId'] ?? null) !== $revision->id
            || $revision->document_id !== $document->id || $revision->workspace_id !== $document->workspace_id
            || ! in_array($source['matchState'] ?? null, ['EXACT', 'STRUCTURALLY_RESOLVED'], true)
            || $source['revision'] !== $textHash
            || $revision->extracted_text_hash !== $textHash
            || $revision->file_hash !== $document->file_hash
            || $revision->provenance_set_id === null
            || $revision->provenance_policy !== SourceProvenance::POLICY
            || $source['provenancePolicy'] !== SourceProvenance::POLICY
            || $revision->structured_extraction_id === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $range = $source['source']['range'] ?? null;
        $preview = $source['source']['text'] ?? null;
        if (! is_array($range) || ! is_int($range['start'] ?? null) || ! is_int($range['end'] ?? null)
            || ! is_string($preview) || $range['start'] < 0 || $range['end'] <= $range['start']
            || $range['end'] - $range['start'] !== ($source['source']['length'] ?? null)) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        if ($range['end'] - $range['start'] > 4096) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        $quote = mb_substr((string) $view->extracted_text, $range['start'], $range['end'] - $range['start']);
        if (mb_substr($quote, 0, mb_strlen($preview)) !== $preview) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        $source['source']['text'] = $quote;

        $row = DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('source_hash', $revision->file_hash)->where('text_hash', $textHash)
            ->where('parser_version', $revision->parser_version)
            ->where('policy_version', $revision->structure_policy)
            ->where('policy_version', StructuredDocument::POLICY)->where('format', 'DOCX')
            ->selectRaw('CASE WHEN octet_length(capabilities::text) <= 4096 THEN capabilities::text ELSE NULL END as capabilities_json')
            ->selectRaw('octet_length(nodes::text) as nodes_bytes')
            ->selectRaw('CASE WHEN octet_length(nodes::text) <= ? THEN nodes::text ELSE NULL END as nodes_json',
                [self::MAX_STORED_NODES_BYTES])->first();
        if ($row === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        if ($row->nodes_json === null || $row->nodes_bytes > self::MAX_STORED_NODES_BYTES || $row->capabilities_json === null) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        try {
            $nodes = json_decode($row->nodes_json, true, flags: JSON_THROW_ON_ERROR);
            $capabilities = json_decode($row->capabilities_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        if (! is_array($nodes) || count($nodes) > 100000) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        if (! is_array($capabilities) || ($capabilities['body_order'] ?? null) !== 'complete'
            || ($capabilities['nodes_truncated'] ?? false) !== false) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }

        $window = app(DocxWindowBuilder::class)->build($nodes, $source, (string) $view->extracted_text);
        $target = $window['context']['target'];
        $keys = ['raw:'.$range['start'].':'.$range['end'],
            'node:'.hash('sha256', $target['id'])];
        $anchors = DB::table('source_anchors')->where('set_id', $revision->provenance_set_id)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->whereIn('source_key', $keys)->limit(3)
            ->get(['id', 'source_key', 'kind', 'structural_path', 'start_offset', 'end_offset', 'source_text', 'source_hash']);
        $verified = false;
        $proof = null;
        foreach ($anchors as $anchor) {
            if ($target['type'] === 'table_row' && $anchor->source_key === $keys[1]
                && $anchor->kind === 'table_row' && $anchor->structural_path === $target['id']
                && $anchor->source_text === null && $anchor->source_hash === null) {
                continue; // Containers have no text hash; the raw citation must prove this row.
            }
            if (! is_string($anchor->source_hash) || ! is_string($anchor->source_text)
                || hash('sha256', $anchor->source_text) !== $anchor->source_hash
                || ! is_int($anchor->start_offset) || ! is_int($anchor->end_offset)
                || mb_substr((string) $view->extracted_text, $anchor->start_offset,
                    $anchor->end_offset - $anchor->start_offset) !== $anchor->source_text) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            if ($anchor->source_key === $keys[0]
                && $anchor->start_offset === $range['start'] && $anchor->end_offset === $range['end']
                && $anchor->source_hash === hash('sha256', $quote)) {
                $verified = true;
                $proof = $anchor;
            }
            if ($anchor->source_key === $keys[1] && $anchor->structural_path === $target['id']
                && $anchor->start_offset === $target['range']['start']
                && $anchor->end_offset === $target['range']['end']
                && $anchor->source_hash === hash('sha256', $target['text'])) {
                $verified = true;
                $proof = $anchor;
            } elseif ($anchor->source_key === $keys[1]) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
        }
        if (! $verified) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        $result = ['type' => 'docx', 'documentId' => $document->id,
            'sourceId' => $source['sourceId'], 'intelligenceRevisionId' => $revision->id,
            'sourceRevision' => $textHash, 'anchor' => ['blockId' => $target['id'],
                'blockType' => $target['type'], 'sourceHash' => $proof->source_hash,
                'id' => $proof->id, 'text' => $proof->source_text,
                'range' => ['start' => $proof->start_offset, 'end' => $proof->end_offset],
                'quote' => $quote, 'quoteRange' => $range], ...$window];
        try {
            if (strlen(json_encode(['data' => $result], JSON_THROW_ON_ERROR)) > self::MAX_RESPONSE_BYTES) {
                throw new DomainException('SOURCE_TOO_COMPLEX');
            }
        } catch (JsonException) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        return $result;
    }
}
