<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\SourceSpanBuilder;

/** Source locations supplied as span IDs by the existing normal-route calls. */
class NormalRouteProvenance
{
    public function eligible(Document $document, string $text): bool
    {
        if ($text !== (string) $document->extracted_text || $text === '') {
            return false;
        }
        $grounding = app(EvidenceGrounding::class);
        $spans = app(SourceSpanBuilder::class)->build($text, $grounding->pagesKnown($document) ? 1 : null);
        if ($spans === [] || count($spans) > 500) {
            return false;
        }
        $rendered = (new EvidenceSpanSet('', $text, $spans))->render();

        return mb_strlen($rendered) <= (int) config('document_processing.max_extraction_chars');
    }

    public function promptText(Document $document, string $text): string
    {
        if (($document->ai_pipeline['route'] ?? null) !== 'normal'
            || ! str_starts_with((string) ($document->ai_pipeline['key'] ?? ''), 'normal:')
            || ($document->ai_pipeline['normal_span_grounding'] ?? false) !== true
            || $text !== (string) $document->extracted_text) {
            return $text;
        }

        return app(EvidenceGrounding::class)->spans($document)->render();
    }

    public function instruction(): string
    {
        return "\nFor each returned item, include source_span_id with exactly one source span identifier (for example E001, without brackets) that contains its supporting evidence. The application validates this ID against the original extracted text. If no single span supports the item, use null. Keep the existing response fields and their meanings.";
    }

    public function record(Document $document, string $kind, string $id, array $item): void
    {
        $key = $document->ai_pipeline['key'] ?? null;
        $spanId = $item['source_span_id'] ?? null;
        if (($document->ai_pipeline['route'] ?? null) !== 'normal'
            || ! is_string($key) || ! str_starts_with($key, 'normal:')
            || ($document->ai_pipeline['normal_span_grounding'] ?? false) !== true
            || ! is_string($spanId) || ! preg_match('/^E[0-9]{3,}$/D', $spanId)
        ) {
            return;
        }
        $span = app(EvidenceGrounding::class)->spans($document)->resolve($spanId);
        if ($span === null || $span['text'] === ''
            || mb_substr((string) $document->extracted_text, $span['start_offset'],
                $span['end_offset'] - $span['start_offset']) !== $span['text']) {
            return;
        }
        $support = $kind === 'entity' ? ($item['value'] ?? null) : ($item['evidence'] ?? null);
        if (! is_string($support) || trim($support) === '' || ! str_contains($span['text'], $support)) {
            return;
        }
        $sourceId = $kind.':'.$id;
        DocumentEvidence::updateOrCreate([
            'document_id' => $document->id, 'pipeline_key' => $key,
            'identity' => hash('sha256', $sourceId),
        ], [
            'workspace_id' => $document->workspace_id, 'kind' => 'normal_source',
            // The source ID proves the occurrence, not a field-role relationship.
            'source_id' => $sourceId, 'data' => ['quote' => $span['text']],
            'sources' => [['span_id' => $spanId, 'quote' => $span['text'],
                'page' => $span['page'], 'start_offset' => $span['start_offset'],
                'end_offset' => $span['end_offset']]],
        ]);
    }

    public function publish(Document $document): void
    {
        if (($document->ai_pipeline['route'] ?? null) !== 'normal') {
            return;
        }
        $key = $document->ai_pipeline['key'] ?? null;
        if (! is_string($key) || ! str_starts_with($key, 'normal:')
            || ! DocumentEvidence::where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)->where('pipeline_key', $key)
                ->where('kind', 'normal_source')->exists()) {
            return;
        }
        app(TextRoleProvenanceProducer::class)->publish($document);
    }
}
