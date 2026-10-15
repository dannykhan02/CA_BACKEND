<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Services\Extraction\StructuredDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Reads only the structured extraction pinned to the published revision. */
class SourceCellWindowReader
{
    private const MAX_STORED_NODES_BYTES = 8 * 1024 * 1024;

    private const MAX_CAPABILITIES_BYTES = 4096;

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
        if ($source['state'] !== 'CURRENT'
            || ($source['documentId'] ?? null) !== $document->id
            || ! in_array($source['matchState'], ['EXACT', 'STRUCTURALLY_RESOLVED'], true)
            || $source['revision'] !== $textHash
            || $revision->extracted_text_hash !== $textHash
            || $revision->file_hash !== $document->file_hash
            || $revision->provenance_set_id === null
            || $revision->provenance_policy !== SourceProvenance::POLICY
            || $source['provenancePolicy'] !== SourceProvenance::POLICY
            || $revision->structured_extraction_id === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }

        // The JSON column is immutable source data but can be large. Do not
        // transport or decode an extraction beyond this read budget.
        $row = DB::table('structured_extractions')
            ->where('id', $revision->structured_extraction_id)
            ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('source_hash', $revision->file_hash)->where('text_hash', $textHash)
            ->where('parser_version', $revision->parser_version)
            ->where('policy_version', $revision->structure_policy)
            ->where('policy_version', StructuredDocument::POLICY)
            ->where('format', 'XLSX')
            ->selectRaw('CASE WHEN octet_length(capabilities::text) <= ? THEN capabilities::text ELSE NULL END as capabilities_json',
                [self::MAX_CAPABILITIES_BYTES])
            ->selectRaw('octet_length(nodes::text) as nodes_bytes')
            ->selectRaw('CASE WHEN octet_length(nodes::text) <= ? THEN nodes::text ELSE NULL END as nodes_json',
                [self::MAX_STORED_NODES_BYTES])
            ->first();
        if ($row === null) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        if ($row->nodes_json === null || $row->nodes_bytes > self::MAX_STORED_NODES_BYTES) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        if ($row->capabilities_json === null) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        try {
            $capabilities = json_decode($row->capabilities_json, true, flags: JSON_THROW_ON_ERROR);
            $nodes = json_decode($row->nodes_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('SOURCE_UNAVAILABLE');
        }
        if (! is_array($nodes) || count($nodes) > 100000 || ! is_array($capabilities)) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        if (($capabilities['nodes_truncated'] ?? true) !== false
            || ($capabilities['cells'] ?? null) !== 'complete') {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }

        $window = app(XlsxCellWindowBuilder::class)->build($nodes, $source, (string) $view->extracted_text);

        $result = ['documentId' => $document->id, 'sourceId' => $source['sourceId'],
            'intelligenceRevisionId' => $revision->id, 'sourceRevision' => $textHash,
            ...$window];
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
