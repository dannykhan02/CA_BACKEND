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
        $cells = explode("\t", $value['text']);
        [$columns, $continuation] = $this->columns($set, $header, count($cells));
        if (count($columns) !== count($cells) || count($cells) < 3) {
            return ['rejection' => 'table_column_mismatch'];
        }
        $between = 0;
        foreach ($set->all() as $span) {
            if ($span['start_offset'] <= $header['start_offset']
                || $span['start_offset'] >= $value['start_offset']) {
                continue;
            }
            if (($continuation === null || $span['key'] !== $continuation['span_id'])
                && ($span['type'] !== 'table_row' || ++$between > 25)) {
                return ['rejection' => 'column_header_relationship_unverified'];
            }
        }
        $period = trim((string) $kpi->period);
        $basis = mb_strtolower(trim((string) (($kpi->identity_metadata ?? [])['value_basis'] ?? '')));
        if ($period !== '' && ! preg_match('/^(Q[1-4])(?:\s+(?:FY)?\d{4})?$/iuD', $period, $match)) {
            return ['rejection' => 'period_ambiguous'];
        }
        if ($period === '' && $basis !== 'proposed') {
            return ['rejection' => 'period_ambiguous'];
        }
        $quarter = $period === '' ? null : strtoupper($match[1]);
        $indexes = [];
        foreach ($columns as $index => $column) {
            if ($index === 0) {
                continue;
            }
            if ($quarter === null) {
                if (preg_match('/\bproposed\b/iu', $column)) {
                    $indexes[] = $index;
                }
                continue;
            }
            if (! preg_match('/^'.preg_quote($quarter, '/').'(?:\s|$)/iu', trim($column))) {
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
        $caption = $this->precedingCaption($set, $header);
        $captionWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($caption), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        if ($conceptWords === [] || (array_diff(array_map($singular, $conceptWords), $labels) !== []
            && ($quarter !== null || ! preg_match('/\btotal\s+proposed\b/iu', $cells[0])
                || array_diff(array_map($singular, $conceptWords), array_map($singular, $captionWords)) !== []))) {
            return ['rejection' => 'concept_row_mismatch'];
        }
        $cellStart = $value['start_offset'] + mb_strlen(implode("\t", array_slice($cells, 0, $index))) + 1;

        return ['rejection' => null, 'source_value' => trim($cells[$index]),
            'cell_start_offset' => $cellStart,
            'cell_end_offset' => $cellStart + mb_strlen($cells[$index]),
            'basis_text' => trim($columns[$index]), 'header_span_id' => $header['span_id'],
            'header_continuation' => $continuation];
    }

    /** Accept one adjacent wrapped heading only when its lines complete the table's column count. */
    private function columns(EvidenceSpanSet $set, array $header, int $cellCount): array
    {
        $columns = explode("\t", $header['text']);
        if (count($columns) === $cellCount) {
            return [$columns, null];
        }
        if (count($columns) !== $cellCount - 1 || ! str_contains(end($columns), '(')
            || str_contains(end($columns), ')')) {
            return [$columns, null];
        }
        $spans = $set->all();
        foreach ($spans as $index => $span) {
            if ($span['key'] !== $header['span_id']) {
                continue;
            }
            $next = isset($spans[$index + 1]) ? $set->resolve($spans[$index + 1]['key']) : null;
            if ($next === null || $next['page'] !== $header['page']
                || $next['type'] !== 'sentence'
                || ! preg_match('/^([^\r\n]+\))\R([^\r\n]+)$/uD', $next['text'], $parts)) {
                break;
            }
            $columns[count($columns) - 1] .= ' '.trim($parts[1]);
            $columns[] = trim($parts[2]);

            return [$columns, $next];
        }

        return [$columns, null];
    }

    private function precedingCaption(EvidenceSpanSet $set, array $header): string
    {
        $previous = null;
        foreach ($set->all() as $span) {
            if ($span['key'] === $header['span_id']) {
                return $previous && $previous['page'] === $header['page']
                    ? (string) ($set->resolve($previous['key'])['text'] ?? '') : '';
            }
            $previous = $span;
        }

        return '';
    }
}
