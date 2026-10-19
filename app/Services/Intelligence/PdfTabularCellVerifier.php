<?php

namespace App\Services\Intelligence;

use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceSpanSet;
use Brick\Math\BigDecimal;

/** Verifies a cited tab-delimited PDF row against its cited column header. */
class PdfTabularCellVerifier
{
    public function __construct(private MeasurementParser $measurements) {}

    /**
     * Returns null for non-table prose. A table claim returns either a precise rejection or
     * the one cell that matches the claimed period/basis. Tabs come from stored PDF text;
     * no value is accepted from an adjacent column, even if the same number recurs.
     */
    public function verify(DocumentKpi $kpi, EvidenceSpanSet $set, array $value,
        ?array $header): ?array
    {
        if (($value['type'] ?? null) !== 'table_row' || ! str_contains($value['text'], "\t")) {
            return null;
        }
        if ($header === null || ($header['type'] ?? null) !== 'table_row'
            || ! str_contains($header['text'], "\t") || $header['page'] !== $value['page']
            || $header['start_offset'] >= $value['start_offset']) {
            return ['rejection' => 'column_header_unverified'];
        }
        $columns = explode("\t", $header['text']);
        $cells = explode("\t", $value['text']);
        if (count($columns) !== count($cells) || count($cells) < 3) {
            return ['rejection' => 'table_column_mismatch'];
        }
        $between = 0;
        foreach ($set->all() as $span) {
            if ($span['start_offset'] <= $header['start_offset']
                || $span['start_offset'] >= $value['start_offset']) {
                continue;
            }
            if ($span['type'] !== 'table_row' || ++$between > 25) {
                return ['rejection' => 'column_header_relationship_unverified'];
            }
        }
        $period = trim((string) $kpi->period);
        if (! preg_match('/^(Q[1-4])(?:\s+(?:FY)?\d{4})?$/iuD', $period, $match)) {
            return ['rejection' => 'period_ambiguous'];
        }
        $quarter = strtoupper($match[1]);
        $basis = mb_strtolower(trim((string) (($kpi->identity_metadata ?? [])['value_basis'] ?? '')));
        $indexes = [];
        foreach ($columns as $index => $column) {
            if ($index === 0 || ! preg_match('/^'.preg_quote($quarter, '/').'(?:\s|$)/iu', trim($column))) {
                continue;
            }
            $targetColumn = preg_match('/\b(?:target|ceiling|goal|threshold)\b/iu', $column) === 1;
            if (($basis === 'target') === $targetColumn) {
                $indexes[] = $index;
            }
        }
        if (count($indexes) !== 1) {
            return ['rejection' => 'period_column_ambiguous'];
        }
        $index = $indexes[0];
        $claimed = $this->measurements->parse((string) $kpi->value, $kpi->unit, $kpi->label);
        $written = $this->measurements->parse(trim($cells[$index]), $kpi->unit, $kpi->label);
        if ($claimed === null || $written === null
            || ! BigDecimal::of($claimed->exactMagnitude)->isEqualTo($written->exactMagnitude)) {
            return ['rejection' => 'value_column_mismatch'];
        }
        $concept = (string) (($kpi->identity_metadata ?? [])['concept'] ?? $kpi->label);
        $conceptWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($concept), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $conceptWords = array_values(array_filter($conceptWords,
            static fn (string $word): bool => mb_strlen($word) >= 4
                && ! in_array($word, ['quarterly', 'rate', 'received', 'cases'], true)));
        $labelWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($cells[0]), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $singular = static fn (string $word): string => str_ends_with($word, 'ies')
            ? substr($word, 0, -3).'y' : (str_ends_with($word, 's') ? substr($word, 0, -1) : $word);
        $labels = array_map($singular, $labelWords);
        if ($conceptWords === [] || array_diff(array_map($singular, $conceptWords), $labels) !== []) {
            return ['rejection' => 'concept_row_mismatch'];
        }
        $cellStart = $value['start_offset'] + mb_strlen(implode("\t", array_slice($cells, 0, $index))) + 1;

        return ['rejection' => null, 'source_value' => trim($cells[$index]),
            'cell_start_offset' => $cellStart,
            'cell_end_offset' => $cellStart + mb_strlen($cells[$index]),
            'basis_text' => trim($columns[$index])];
    }
}
