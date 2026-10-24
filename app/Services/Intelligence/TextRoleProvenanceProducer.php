<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;

/** Binds only unique literal occurrences inside one stored source quote using raw character offsets. */
class TextRoleProvenanceProducer
{
    public function __construct(private SourceProvenance $store) {}

    public function publish(Document $document): string
    {
        $anchors = [];
        $relations = [];
        $bindings = [];
        $text = (string) $document->extracted_text;
        $key = (string) ($document->ai_pipeline['key'] ?? 'legacy');
        $ocrPages = $document->ocrResults()
            ->where('intelligence_revision_id', $document->active_intelligence_revision_id)
            ->orderBy('page_number')->get(['page_number', 'raw_text', 'metadata']);
        $structured = app(StructuredExtractionStore::class)->active($document);
        $pageOffsets = [];
        $ocrPageNumbers = [];
        if ($structured?->format === 'PDF') {
            foreach ($structured->nodes as $node) {
                if (($node['type'] ?? null) === 'page' && isset($node['page'], $node['start'])) {
                    $pageOffsets[$node['page']] = $node['start'];
                    if (($node['meta']['modality'] ?? null) === 'ocr') {
                        $ocrPageNumbers[$node['page']] = $node['text'] ?? '';
                    }
                }
            }
        }
        $ocrTextMatches = $ocrPages->isNotEmpty() && $ocrPages->pluck('raw_text')->implode("\f") === $text;
        if ($ocrTextMatches && $pageOffsets === []) {
            $cursor = 0;
            foreach ($ocrPages as $page) {
                $pageOffsets[$page->page_number] = $cursor;
                $ocrPageNumbers[$page->page_number] = (string) $page->raw_text;
                $cursor += mb_strlen((string) $page->raw_text) + 1;
            }
        }
        $modality = match ($document->type) {
            'XLSX' => 'xlsx', 'DOCX' => 'docx', 'PDF' => $ocrTextMatches ? 'ocr' : 'embedded_text',
            default => $ocrTextMatches ? 'ocr' : 'embedded_text',
        };
        $currentOcrPages = $ocrPages->filter(static fn ($page) => isset($ocrPageNumbers[$page->page_number])
            && $ocrPageNumbers[$page->page_number] === (string) $page->raw_text);
        $ocrWords = $this->ocrWordRegions($currentOcrPages, $pageOffsets);
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $key)->get();
        $structurallyBound = [];
        $parserVersion = 'raw-text.v2';
        if ($structured !== null) {
            [$nativeAnchors, $nativeRelations, $nativeBindings, $structurallyBound] =
                app(StructuredProvenanceProducer::class)->build($structured, $rows);
            foreach ($nativeAnchors as $anchor) {
                $anchors[$anchor['key']] = $anchor;
            }
            foreach ($nativeRelations as $relation) {
                $relations[$relation['from'].'|'.$relation['to'].'|'.$relation['type']] = $relation;
            }
            array_push($bindings, ...$nativeBindings);
            $roleVersion = $structured->format === 'PDF'
                && str_contains($structured->parserVersion, '+poppler-grid.v1')
                ? 'roles.v3' : 'roles.v2';
            $parserVersion = $structured->parserVersion.'@'.StructuredDocument::POLICY.'@'.$roleVersion;
        }
        foreach ($rows as $row) {
            if (isset($structurallyBound[$row->id])) {
                continue;
            }
            $data = $row->data ?? [];
            foreach ($row->sources ?? [] as $source) {
                if (($source['source_graph_derived'] ?? false) === true) {
                    continue;
                }
                $start = $source['start_offset'] ?? null;
                $end = $source['end_offset'] ?? null;
                $quote = $source['quote'] ?? null;
                if (! is_int($start) || ! is_int($end) || ! is_string($quote) || $end <= $start
                    || mb_substr($text, $start, $end - $start) !== $quote) {
                    continue;
                }
                // A legacy quote may have been assigned to its first identical occurrence. Without
                // an explicit span ID, repeated quote text in its chunk is ambiguous.
                if (! isset($source['span_id']) && mb_substr_count($text, $quote) > 1) {
                    continue;
                }
                $quoteKey = 'raw:'.$start.':'.$end;
                $page = $source['page'] ?? null;
                $sourceModality = isset($ocrPageNumbers[$page])
                    && isset($pageOffsets[$page])
                    && $start >= $pageOffsets[$page]
                    && $end <= $pageOffsets[$page] + mb_strlen($ocrPageNumbers[$page])
                    ? 'ocr' : $modality;
                $anchors[$quoteKey] = ['key' => $quoteKey, 'kind' => 'text_span',
                    'modality' => $sourceModality,
                    'page' => $source['page'] ?? null, 'start_offset' => $start,
                    'end_offset' => $end, 'text' => $quote];
                // A flattened table row has no defensible row/column ownership. Keep its quote,
                // but do not convert lexical proximity into field-role proof.
                if ($document->type === 'XLSX' || str_contains($quote, ' | ')) {
                    continue;
                }
                $fields = ['value' => $data['value'] ?? null, 'metric' => $data['label'] ?? null,
                    'subject' => $data['subject'] ?? null, 'period' => $data['period'] ?? null,
                    'unit' => $data['unit'] ?? null];
                $found = [];
                foreach ($fields as $role => $needle) {
                    if (! is_string($needle) || trim($needle) === '') {
                        continue;
                    }
                    $at = mb_strpos($quote, $needle);
                    if ($at === false || mb_strpos($quote, $needle, $at + 1) !== false) {
                        continue;
                    }
                    $found[$role] = [$start + $at, $start + $at + mb_strlen($needle)];
                }
                if (! isset($found['value'])) {
                    continue;
                }
                [$valueStart, $valueEnd] = $found['value'];
                $valueText = mb_substr($text, $valueStart, $valueEnd - $valueStart);
                if (preg_match('/\b(?:USD|EUR|GBP|CAD|AUD|KES)\b|[$€£]/u', $valueText, $currency)
                    && mb_substr_count($valueText, $currency[0]) === 1) {
                    $at = mb_strpos($valueText, $currency[0]);
                    $found['currency'] = [$valueStart + $at, $valueStart + $at + mb_strlen($currency[0])];
                }
                if (preg_match('/\b(?:thousand|million|billion|trillion)\b/iu', $valueText, $scale)
                    && mb_substr_count(mb_strtolower($valueText), mb_strtolower($scale[0])) === 1) {
                    $at = mb_stripos($valueText, $scale[0]);
                    $found['scale'] = [$valueStart + $at, $valueStart + $at + mb_strlen($scale[0])];
                }
                foreach ($found as $role => [$from, $to]) {
                    $roleKey = 'raw:'.$from.':'.$to;
                    $anchors[$roleKey] = ['key' => $roleKey, 'kind' => 'text_span',
                        'modality' => $anchors[$quoteKey]['modality'], 'page' => $source['page'] ?? null,
                        'start_offset' => $from, 'end_offset' => $to,
                        'text' => mb_substr($text, $from, $to - $from)];
                    if ($role === 'value' && isset($ocrWords[$roleKey])) {
                        $anchors[$roleKey] = array_merge($anchors[$roleKey], $ocrWords[$roleKey]);
                    }
                    if ($roleKey !== $quoteKey) {
                        $relations[$roleKey.'|'.$quoteKey] = ['from' => $roleKey,
                            'to' => $quoteKey, 'type' => 'contained_by'];
                    }
                    $bindings[] = ['evidence_id' => $row->id, 'anchor' => $roleKey, 'role' => $role];
                }
                break; // One canonical fact has one value occurrence; other citations remain in sources.
            }
        }

        return $this->store->publish($document, $parserVersion, array_values($anchors),
            array_values($relations), $bindings);
    }

    /** Tesseract boxes are navigable only when the entire page sequence equals stored text. */
    private function ocrWordRegions(iterable $pages, array $pageOffsets): array
    {
        $words = [];
        foreach ($pages as $page) {
            $pageOffset = $pageOffsets[$page->page_number] ?? null;
            if (! is_int($pageOffset)) {
                continue;
            }
            $metadata = $page->metadata ?? [];
            $width = $metadata['image_width'] ?? null;
            $height = $metadata['image_height'] ?? null;
            if (($metadata['source'] ?? null) === 'tesseract'
                && ($metadata['coordinate_system'] ?? null) === 'image_pixels'
                && is_int($width) && $width > 0 && is_int($height) && $height > 0) {
                foreach (array_slice($metadata['regions'] ?? [], 0, 5000) as $region) {
                    $start = $region['start'] ?? null;
                    $end = $region['end'] ?? null;
                    $left = $region['left'] ?? null;
                    $top = $region['top'] ?? null;
                    $boxWidth = $region['width'] ?? null;
                    $boxHeight = $region['height'] ?? null;
                    if (! is_int($start) || ! is_int($end) || $end <= $start
                        || ! is_int($left) || ! is_int($top) || ! is_int($boxWidth) || ! is_int($boxHeight)
                        || $left < 0 || $top < 0 || $boxWidth < 1 || $boxHeight < 1
                        || $left + $boxWidth > $width || $top + $boxHeight > $height
                        || $end > mb_strlen($page->raw_text)
                        || mb_substr($page->raw_text, $start, $end - $start) === '') {
                        continue;
                    }
                    $key = 'raw:'.($pageOffset + $start).':'.($pageOffset + $end);
                    $words[$key] = ['kind' => 'ocr_region', 'page' => $page->page_number,
                        'geometry' => ['system' => 'image_pixels_top_left',
                            'box' => [$left, $top, $boxWidth, $boxHeight],
                            'width' => $width, 'height' => $height, 'rotation' => 0]];
                }
            }
        }

        return $words;
    }
}
