<?php

namespace App\Services\AI\Incremental;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentSourceSpan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the evidence-grounding mode and the document's evidence spans.
 *
 * legacy_quote:    the model returns a verbatim quote, which must occur in the slice it was
 *                  given. Harmless formatting differences in PDF-extracted text reject it.
 * span_reference:  DocIntel labels the source with stable span IDs, the model returns only
 *                  IDs, and DocIntel retrieves the exact original text itself.
 *
 * A pipeline's mode is decided once, when its root chunks are planned, and recorded in
 * Document::ai_pipeline. The mode is part of the pipeline key, so flipping the feature flag
 * starts a new pipeline instead of mixing two record shapes inside one.
 */
class EvidenceGrounding
{
    public const LEGACY = 'legacy_quote';

    public const SPANS = 'span_reference';

    private const INSERT_BATCH = 1000;

    /** @var array<string,EvidenceSpanSet> */
    private array $cache = [];

    public function __construct(private SourceSpanBuilder $builder) {}

    /** Whether new analyses should be planned with span-based grounding. */
    public function enabled(): bool
    {
        return (bool) config('document_intelligence.evidence_spans');
    }

    /** The mode a document's current pipeline was planned with, never the live flag. */
    public function mode(Document $document): string
    {
        return ($document->ai_pipeline['grounding'] ?? null) === self::SPANS ? self::SPANS : self::LEGACY;
    }

    public function usesSpans(Document $document): bool
    {
        return $this->mode($document) === self::SPANS;
    }

    /**
     * Fingerprint of the exact text the spans describe plus the segmenter that produced them.
     * Different extracted text, or a different segmenter, is a different version: existing IDs are
     * never silently remapped onto new text.
     */
    public function version(Document $document): string
    {
        return substr(hash('sha256', implode('|', [
            hash('sha256', (string) $document->extracted_text),
            (string) config('document_intelligence.span_segmenter_version'),
        ])), 0, 32);
    }

    /** The version a document's pipeline was planned against (null for a legacy pipeline). */
    public function pipelineVersion(Document $document): ?string
    {
        return $document->ai_pipeline['extraction_version'] ?? null;
    }

    /**
     * Page numbers are only reported when the extracted text's page map is known to be complete,
     * which is the same condition the chunk planner uses for chunk pages.
     */
    public function pagesKnown(Document $document): bool
    {
        return $document->type === 'PDF'
            && substr_count((string) $document->extracted_text, "\f") + 1 === (int) $document->pages;
    }

    /**
     * The document's spans for its current extracted text. Built once and persisted, then loaded
     * on every later call: one deterministic segmentation per extraction version, not one per chunk.
     */
    public function spans(Document $document): EvidenceSpanSet
    {
        $version = $this->version($document);
        $key = $document->id.':'.$version;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        // One document at a time: a long-lived worker must not accumulate every document's text.
        $this->cache = [];
        $rows = DocumentSourceSpan::where('document_id', $document->id)->where('extraction_version', $version)
            ->orderBy('ordinal')->get(['span_key', 'ordinal', 'page', 'start_offset', 'end_offset', 'type']);
        $spans = $rows->isEmpty()
            ? $this->persist($document, $version)
            : $rows->map(fn ($row) => ['ordinal' => (int) $row->ordinal, 'key' => $row->span_key,
                'page' => $row->page === null ? null : (int) $row->page, 'start_offset' => (int) $row->start_offset,
                'end_offset' => (int) $row->end_offset, 'type' => $row->type])->all();

        return $this->cache[$key] = new EvidenceSpanSet($version, (string) $document->extracted_text, $spans);
    }

    /** The spans a chunk actually showed the model: the only IDs its response may reference. */
    public function chunkSpans(Document $document, DocumentChunk $chunk): EvidenceSpanSet
    {
        return $this->spans($document)->forRange((int) $chunk->start_offset, (int) $chunk->end_offset);
    }

    /**
     * The source text as the provider receives it: labeled spans in span mode, the raw slice in
     * legacy mode. The input hash stored on the chunk stays the hash of the raw slice either way.
     */
    public function payload(Document $document, DocumentChunk $chunk, string $raw): string
    {
        return $this->usesSpans($document) ? $this->chunkSpans($document, $chunk)->render() : $raw;
    }

    /** @return list<array{ordinal:int,key:string,page:int|null,start_offset:int,end_offset:int,type:string}> */
    private function persist(Document $document, string $version): array
    {
        $spans = $this->builder->build((string) $document->extracted_text, $this->pagesKnown($document) ? 1 : null);
        $now = now();
        $rows = array_map(fn ($span) => ['id' => (string) Str::uuid(),
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'extraction_version' => $version, 'span_key' => $span['key'], 'ordinal' => $span['ordinal'],
            'page' => $span['page'], 'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
            'type' => $span['type'], 'created_at' => $now], $spans);
        // One transaction: a reader either sees the whole span set for this version or none of it.
        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                DB::table('document_source_spans')->insertOrIgnore($batch);
            }
        });

        return $spans;
    }
}
