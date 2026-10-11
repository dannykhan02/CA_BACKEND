<?php

namespace App\Services\Extraction;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publishes the exact text and its projection in the same document transaction. */
class StructuredExtractionStore
{
    public function publish(Document $document, StructuredDocument $result): void
    {
        if ($document->workspace_id === null || ! in_array($result->format, ['DOCX', 'XLSX', 'PDF'], true)) {
            throw new \InvalidArgumentException('Invalid structured extraction owner.');
        }
        $textHash = hash('sha256', $result->text);
        DB::transaction(function () use ($document, $result, $textHash): void {
            DB::table('documents')->where('id', $document->id)->where('workspace_id', $document->workspace_id)
                ->lockForUpdate()->first(['id']);
            $owner = DB::table('documents')->where('id', $document->id)
                ->where('workspace_id', $document->workspace_id)->first(['file_hash']);
            if ($owner === null || ! is_string($owner->file_hash) || $owner->file_hash === '') {
                throw new \LogicException('Document source revision is unavailable.');
            }
            $existing = DB::table('structured_extractions')->where('document_id', $document->id)
                ->where('source_hash', $owner->file_hash)->where('text_hash', $textHash)
                ->where('parser_version', $result->parserVersion)
                ->where('policy_version', StructuredDocument::POLICY)->first();
            $nodes = json_encode($result->nodes, JSON_THROW_ON_ERROR);
            $capabilities = json_encode($result->capabilities, JSON_THROW_ON_ERROR);
            if ($existing !== null && ($existing->nodes !== $nodes || $existing->capabilities !== $capabilities)) {
                throw new \LogicException('Structured extraction changed without a parser version change.');
            }
            if ($existing === null) {
                DB::table('structured_extractions')->insert(['id' => (string) Str::uuid(),
                    'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                    'source_hash' => $owner->file_hash, 'text_hash' => $textHash,
                    'parser_version' => $result->parserVersion, 'policy_version' => StructuredDocument::POLICY,
                    'format' => $result->format, 'nodes' => $nodes, 'capabilities' => $capabilities,
                    'active' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('structured_extractions')->where('document_id', $document->id)->update(['active' => false]);
            DB::table('structured_extractions')->where('document_id', $document->id)
                ->where('source_hash', $owner->file_hash)->where('text_hash', $textHash)
                ->where('parser_version', $result->parserVersion)
                ->where('policy_version', StructuredDocument::POLICY)->update(['active' => true]);
            DB::table('documents')->where('id', $document->id)->where('workspace_id', $document->workspace_id)
                ->update(['extracted_text' => $result->text]);
        });
        $document->extracted_text = $result->text;
    }

    public function active(Document $document): ?StructuredDocument
    {
        $row = DB::table('structured_extractions')->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('source_hash', $document->file_hash)
            ->where('text_hash', hash('sha256', (string) $document->extracted_text))
            ->where('policy_version', StructuredDocument::POLICY)->where('active', true)->first();
        if ($row === null) {
            return null;
        }

        return new StructuredDocument($row->format, $row->parser_version, (string) $document->extracted_text,
            json_decode($row->nodes, true, flags: JSON_THROW_ON_ERROR),
            json_decode($row->capabilities, true, flags: JSON_THROW_ON_ERROR));
    }
}
