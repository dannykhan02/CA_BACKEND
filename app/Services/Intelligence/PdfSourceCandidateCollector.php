<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Extraction\StructuredExtractionStore;

/** Mints separate, revision-bound facts from projected PDF cells; model claims stay untouched. */
final class PdfSourceCandidateCollector
{
    public function __construct(private StructuredExtractionStore $extractions,
        private PeriodParser $periods, private MeasurementParser $measurements) {}

    public function collect(Document $document): array
    {
        if (! config('document_intelligence.pdf_table_structure') || $document->type !== 'PDF') {
            return ['extracted' => 0, 'candidates' => 0];
        }
        $source = $this->extractions->active($document);
        if ($source === null || ! str_contains($source->parserVersion, '+poppler-grid.v1')) {
            $this->sourceFacts($document)->delete();

            return ['extracted' => 0, 'candidates' => 0];
        }
        $nodes = array_column($source->nodes, null, 'id');
        $key = (string) ($document->ai_pipeline['key'] ?? 'legacy');
        $extracted = 0;
        $candidates = 0;
        $current = [];
        foreach ($source->nodes as $cell) {
            if (($cell['type'] ?? null) !== 'cell' || ($cell['meta']['header'] ?? false)
                || ($cell['meta']['grid_column'] ?? 0) < 2 || ! isset($cell['start'], $cell['end'])) {
                continue;
            }
            $row = $nodes[$cell['parent'].'/cell[0]'] ?? null;
            $table = $nodes[dirname(dirname($cell['id']))] ?? null;
            if ($row === null || $table === null || ($table['type'] ?? null) !== 'table') {
                continue;
            }
            $extracted++;
            $headers = array_map(static fn ($id) => $nodes[$id] ?? null,
                $cell['meta']['header_path_ids'] ?? []);
            if ($headers === [] || in_array(null, $headers, true)) {
                continue;
            }
            $periodSource = implode(' ', array_column($headers, 'text'));
            $period = $this->periods->parse($periodSource);
            $caption = $nodes[$table['meta']['unit_heading_id'] ?? ''] ?? null;
            $entity = $nodes[$table['meta']['entity_heading_id'] ?? ''] ?? null;
            $label = trim((string) $row['text']);
            $value = trim((string) $cell['text']);
            if ($label === '' || $this->measurements->parse($value, null, $label) === null) {
                continue;
            }
            $captionText = (string) ($caption['text'] ?? '');
            preg_match_all('/\b(thousands?|millions?|billions?)\b/iu', $captionText, $scaleMatches);
            $scales = array_unique(array_map('mb_strtolower', $scaleMatches[1]));
            $scale = count($scales) === 1 ? $scales[0] : null;
            $codes = $this->measurements->explicitCurrencyCodes($captionText.' '.$periodSource.' '.$label.' '.$value);
            $markers = array_map(static fn ($id) => $nodes[$id]['text'] ?? null,
                $cell['meta']['currency_marker_ids'] ?? []);
            $currency = count($codes) === 1 ? $codes[0]
                : (count($codes) === 0 && count($markers) === 2
                    && $markers[0] === $markers[1] ? $markers[0] : null);
            $perShare = preg_match('/\bper\s+(?:common\s+)?share\b/iu', $label) === 1;
            $unit = $currency === null ? '' : ($perShare ? $currency.' per share'
                : ($scale === null ? '' : $currency.' '.$scale));
            $rowStart = $row['start'];
            $lineStart = mb_strrpos(mb_substr($source->text, 0, $rowStart), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = mb_strpos($source->text, "\n", $cell['end']);
            $lineEnd = $lineEnd === false ? mb_strlen($source->text) : $lineEnd;
            $quote = mb_substr($source->text, $lineStart, $lineEnd - $lineStart);
            $id = hash('sha256', $document->file_hash.'|'.$cell['id']);
            $sourceId = 'pdf-cell:'.$id;
            $current[] = $sourceId;
            $data = ['label' => $label, 'value' => $value, 'period' => $periodSource,
                'unit' => $unit, 'subject' => '', 'source_candidate' => true,
                'source_file_hash' => $document->file_hash,
                'canonical_representation' => ['label' => $label,
                    'period' => $period?->label, 'unit' => $unit],
                'source_representation' => ['label' => $row['text'], 'value' => $cell['text'],
                    'period' => $periodSource, 'period_fragments' => array_column($headers, 'text'),
                    'unit' => $captionText,
                    'entity' => $entity['text'] ?? null, 'cell_id' => $cell['id']]];
            DocumentEvidence::updateOrCreate(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => $key, 'source_id' => $sourceId],
                ['identity' => $id, 'kind' => 'metric', 'data' => $data,
                    'sources' => [['quote' => $quote, 'start_offset' => $lineStart,
                        'end_offset' => $lineEnd, 'page' => $cell['page'],
                        'span_id' => 'pdf-row:'.hash('sha256', $cell['parent'])]]]);
            $candidates++;
        }
        $stale = $this->sourceFacts($document);
        if ($current !== []) {
            $stale->whereNotIn('source_id', $current);
        }
        $stale->delete();

        return compact('extracted', 'candidates');
    }

    private function sourceFacts(Document $document)
    {
        return DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? 'legacy')
            ->where('source_id', 'like', 'pdf-cell:%');
    }
}
