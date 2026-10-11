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
        $modality = match ($document->type) {
            'XLSX' => 'xlsx', 'DOCX' => 'docx', 'PDF' => $document->ocrResults()->exists() ? 'ocr' : 'embedded_text',
            default => 'ocr',
        };
        $rows = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $key)->get();
        $structured = app(StructuredExtractionStore::class)->active($document);
        $structurallyBound = [];
        $parserVersion = 'raw-text.v1';
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
            $parserVersion = $structured->parserVersion.'@'.StructuredDocument::POLICY;
        }
        foreach ($rows as $row) {
            if (isset($structurallyBound[$row->id])) {
                continue;
            }
            $data = $row->data ?? [];
            foreach ($row->sources ?? [] as $source) {
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
                $anchors[$quoteKey] = ['key' => $quoteKey, 'kind' => 'text_span',
                    'modality' => $modality,
                    'page' => $source['page'] ?? null, 'start_offset' => $start,
                    'end_offset' => $end, 'text' => $quote];
                // A flattened table row has no defensible row/column ownership. Keep its quote,
                // but do not convert lexical proximity into field-role proof.
                if ($document->type === 'XLSX' || str_contains($quote, ' | ')) {
                    continue;
                }
                $fields = ['value' => $data['value'] ?? null, 'metric' => $data['label'] ?? null,
                    'subject' => $data['subject'] ?? null, 'period' => $data['period'] ?? null];
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
}
