<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\Extraction\StructuredExtractionStore;
use Brick\Math\BigDecimal;

/** Proves a DOCX measurement using native OOXML row, grid column and header edges. */
class DocxTableCellVerifier
{
    public function __construct(private MeasurementParser $measurements) {}

    public function verify(Document $document, DocumentKpi $kpi, EvidenceSpanSet $set,
        array $value, ?array $periodCitation): ?array
    {
        $source = app(StructuredExtractionStore::class)->active($document);
        if ($source?->format !== 'DOCX') {
            return $value['type'] === 'table_row'
                ? ['rejection' => 'structured_relationship_unverified'] : null;
        }
        $nodes = collect($source->nodes)->keyBy('id');
        $cells = collect($source->nodes)->filter(fn ($node) => ($node['type'] ?? null) === 'cell'
            && isset($node['start'], $node['end']));
        $overlaps = $cells->filter(fn ($cell) => $cell['start'] < $value['end_offset']
            && $cell['end'] > $value['start_offset']);
        if ($overlaps->isEmpty()) {
            return $value['type'] === 'table_row'
                ? ['rejection' => 'structured_relationship_unverified'] : null;
        }
        $raw = trim((string) $kpi->value);
        $matches = $overlaps->filter(fn ($cell) => trim((string) $cell['text']) === $raw
            && $cell['start'] >= $value['start_offset'] && $cell['end'] <= $value['end_offset']);
        if ($matches->count() !== 1) {
            return ['rejection' => 'value_column_mismatch'];
        }
        $cell = $matches->first();
        $row = $nodes->get($cell['parent'] ?? '');
        $table = $nodes->get($row['parent'] ?? '');
        if (($row['type'] ?? null) !== 'row' || ($table['type'] ?? null) !== 'table') {
            return ['rejection' => 'structured_relationship_unverified'];
        }
        $column = (int) ($cell['meta']['grid_column'] ?? 0);
        if ($column < 2 || ($cell['meta']['vertical_merge'] ?? null) === 'continue') {
            return ['rejection' => 'structured_relationship_unverified'];
        }
        $headers = collect($source->nodes)->filter(fn ($node) => ($node['type'] ?? null) === 'row'
            && ($node['parent'] ?? null) === $table['id']
            && ($node['meta']['explicit_header'] ?? false) === true
            && strcmp($node['id'], $row['id']) < 0);
        $period = trim((string) $kpi->period);
        $basis = mb_strtolower(trim((string) ($kpi->identity_metadata['value_basis'] ?? '')));
        if ($period === '' && $basis !== 'proposed') {
            return ['rejection' => 'period_ambiguous'];
        }
        $matchingHeaders = $cells->filter(function ($header) use ($headers, $period, $basis): bool {
            if (! $headers->contains(fn ($row) => $row['id'] === ($header['parent'] ?? null))) {
                return false;
            }
            if (($header['meta']['vertical_merge'] ?? null) === 'continue') {
                return false;
            }
            $text = (string) $header['text'];
            $hasPeriod = $period === '' ? preg_match('/\bproposed\b/iu', $text) === 1
                : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($period, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
            $target = preg_match('/\b(?:target|ceiling|goal|threshold)\b/iu', $text) === 1;
            return $hasPeriod && (($basis === 'target') === $target);
        });
        $candidates = $matchingHeaders->filter(function ($header) use ($column): bool {
            $start = (int) ($header['meta']['grid_column'] ?? 0);
            $width = max(1, (int) ($header['meta']['grid_span'] ?? 1));
            return $column >= $start && $column < $start + $width;
        });
        if ($candidates->count() !== 1) {
            return ['rejection' => $matchingHeaders->count() === 1 && $candidates->isEmpty()
                ? 'value_column_mismatch' : 'period_column_ambiguous'];
        }
        $header = $candidates->first();
        if ($periodCitation === null || $header['start'] < $periodCitation['start_offset']
            || $header['end'] > $periodCitation['end_offset']) {
            return ['rejection' => 'column_header_unverified'];
        }
        $claimed = $this->measurements->parse($raw, $kpi->unit, $kpi->label);
        $written = $this->measurements->parse((string) $cell['text'], $kpi->unit, $kpi->label);
        if ($claimed === null || $written === null
            || ! BigDecimal::of($claimed->exactMagnitude)->isEqualTo($written->exactMagnitude)) {
            return ['rejection' => 'value_column_mismatch'];
        }
        $label = $cells->first(fn ($other) => ($other['parent'] ?? null) === $row['id']
            && (int) ($other['meta']['grid_column'] ?? 0) === 1);
        $concept = trim((string) ($kpi->identity_metadata['concept'] ?? $kpi->label));
        if ($label === null || $concept === ''
            || preg_match('/(?<!\p{L})'.preg_quote($concept, '/').'(?!\p{L})/iu', (string) $label['text']) !== 1) {
            return ['rejection' => 'concept_row_mismatch'];
        }
        return ['rejection' => null, 'source_value' => $cell['text'],
            'cell_start_offset' => $cell['start'], 'cell_end_offset' => $cell['end'],
            'basis_text' => $header['text'], 'header_span_id' => $periodCitation['span_id'],
            'period_header_start_offset' => $header['start'],
            'period_header_end_offset' => $header['end']];
    }
}
