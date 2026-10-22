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
        if ($matches->isEmpty() || $matches->pluck('parent')->unique()->count() !== 1) {
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
        $nodeOrder = array_flip(array_column($source->nodes, 'id'));
        $headers = collect($source->nodes)->filter(fn ($node) => ($node['type'] ?? null) === 'row'
            && ($node['parent'] ?? null) === $table['id']
            && ($node['meta']['explicit_header'] ?? false) === true
            && ($nodeOrder[$node['id']] ?? PHP_INT_MAX) < ($nodeOrder[$row['id']] ?? -1));
        $period = trim((string) $kpi->period);
        $basis = mb_strtolower(trim((string) ($kpi->identity_metadata['value_basis'] ?? '')));
        if ($period === '' && $basis !== 'proposed') {
            return ['rejection' => 'period_ambiguous'];
        }
        $matchingHeaders = $cells->filter(function ($header) use ($headers, $period): bool {
            if (! $headers->contains(fn ($row) => $row['id'] === ($header['parent'] ?? null))) {
                return false;
            }
            if (($header['meta']['vertical_merge'] ?? null) === 'continue') {
                return false;
            }
            $text = (string) $header['text'];
            $hasPeriod = $period === '' ? preg_match('/\bproposed\b/iu', $text) === 1
                : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($period, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
            return $hasPeriod;
        });
        $options = [];
        foreach ($matchingHeaders as $periodHeader) {
            $start = (int) ($periodHeader['meta']['grid_column'] ?? 0);
            $width = max(1, (int) ($periodHeader['meta']['grid_span'] ?? 1));
            for ($candidateColumn = max(2, $start); $candidateColumn < $start + $width; $candidateColumn++) {
                $basisText = (string) $periodHeader['text'];
                $basisNodes = [];
                foreach ($cells as $later) {
                    $laterRow = $later['parent'] ?? null;
                    if (! $headers->contains(fn ($item) => $item['id'] === $laterRow)
                        || ($nodeOrder[$laterRow] ?? -1) <= ($nodeOrder[$periodHeader['parent']] ?? -1)
                        || ($later['meta']['vertical_merge'] ?? null) === 'continue') {
                        continue;
                    }
                    $laterStart = (int) ($later['meta']['grid_column'] ?? 0);
                    $laterWidth = max(1, (int) ($later['meta']['grid_span'] ?? 1));
                    if ($candidateColumn >= $laterStart && $candidateColumn < $laterStart + $laterWidth) {
                        $basisText .= ' '.(string) $later['text'];
                        $basisNodes[] = $later;
                    }
                }
                $target = preg_match('/\b(?:target|ceiling|goal|threshold)\b/iu', $basisText) === 1;
                if (($basis === 'target') === $target) {
                    $options[] = ['column' => $candidateColumn, 'period_header' => $periodHeader,
                        'basis_text' => trim($basisText), 'basis_nodes' => $basisNodes];
                }
            }
        }
        if (count($options) !== 1) {
            return ['rejection' => 'period_column_ambiguous'];
        }
        $option = $options[0];
        $column = $option['column'];
        $header = $option['period_header'];
        $selected = $matches->filter(fn ($candidate) => (int) ($candidate['meta']['grid_column'] ?? 0) === $column
            && max(1, (int) ($candidate['meta']['grid_span'] ?? 1)) === 1);
        if ($selected->count() !== 1) {
            return ['rejection' => 'value_column_mismatch'];
        }
        $cell = $selected->first();
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
        $basisSources = [];
        foreach ($option['basis_nodes'] as $node) {
            $span = null;
            foreach ($set->all() as $candidate) {
                if ($candidate['start_offset'] <= $node['start']
                    && $candidate['end_offset'] >= $node['end']) {
                    $span = $set->resolve($candidate['key']);
                    break;
                }
            }
            if ($span === null) {
                return ['rejection' => 'column_header_unverified'];
            }
            $basisSources[$span['span_id']] = $span;
        }
        return ['rejection' => null, 'source_value' => $cell['text'],
            'cell_start_offset' => $cell['start'], 'cell_end_offset' => $cell['end'],
            'basis_text' => $option['basis_text'], 'header_span_id' => $periodCitation['span_id'],
            'period_header_start_offset' => $header['start'],
            'period_header_end_offset' => $header['end'],
            'basis_sources' => array_values($basisSources)];
    }
}
