<?php

namespace App\Services\Intelligence;

use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceSpanSet;
use Brick\Math\BigDecimal;

/** Verifies a cited PDF table cell against its cited column header. */
class PdfTabularCellVerifier
{
    public function __construct(private MeasurementParser $measurements,
        private PeriodParser $periods) {}

    /**
     * Returns null only for non-table prose. A table claim returns either a precise rejection or
     * the one cell that matches its claimed period/basis. No adjacent-column fallback exists.
     */
    public function verify(DocumentKpi $kpi, EvidenceSpanSet $set, array $value,
        ?array $header): ?array
    {
        if (($value['type'] ?? null) !== 'table_row') {
            return null;
        }
        [$cells, $delimiter] = $this->splitCells($value['text']);
        if ($delimiter === null || count($cells) < 3) {
            return ['rejection' => 'table_column_mismatch'];
        }
        if ($header === null || ($header['type'] ?? null) !== 'table_row'
            || $header['page'] !== $value['page']
            || $header['start_offset'] >= $value['start_offset']) {
            return ['rejection' => 'column_header_unverified'];
        }
        [$headerCells, $headerDelimiter] = $this->splitCells($header['text']);
        if ($headerDelimiter !== $delimiter) {
            return ['rejection' => 'table_column_mismatch'];
        }
        [$columns, $continuation] = $this->columns($set, $header, array_column($headerCells, 'text'), count($cells));
        if (count($columns) !== count($cells)) {
            return ['rejection' => 'table_column_mismatch'];
        }
        // Some reports print one value column and a per-row basis column. The final
        // header cell then gives the shared unit, not another measurement period.
        $rowBasis = count($cells) === 3
            && in_array(mb_strtolower(trim($cells[2]['text'])),
                ['actual', 'target', 'proposed', 'forecast', 'budget'], true)
            && trim((string) $kpi->unit) !== ''
            && mb_strtolower(trim($columns[2])) === mb_strtolower(trim((string) $kpi->unit));
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
        if ($rowBasis && preg_match_all('/\bQ[1-4]\s+(?:FY)?\d{4}\b/iu', $columns[1]) > 1) {
            return ['rejection' => 'period_ambiguous'];
        }
        if ($period !== '' && $this->periods->parse($period) === null
            && ! preg_match('/^Q[1-4]$/iuD', $period)) {
            return ['rejection' => 'period_ambiguous'];
        }
        if ($period === '' && $basis !== 'proposed') {
            return ['rejection' => 'period_ambiguous'];
        }
        $quarter = preg_match('/^(Q[1-4])(?:\s|$)/iu', $period, $match)
            ? strtoupper($match[1]) : null;
        $indexes = [];
        foreach ($columns as $index => $column) {
            if ($index === 0) {
                continue;
            }
            if ($period === '') {
                if (preg_match('/\bproposed\b/iu', $column)) {
                    $indexes[] = $index;
                }
                continue;
            }
            if (! $this->periodColumnMatches($column, $period, $quarter)) {
                continue;
            }
            if ($rowBasis) {
                if ($index === 1 && mb_strtolower(trim($cells[2]['text'])) === $basis) {
                    $indexes[] = $index;
                }
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
        $explicitCurrencies = $this->measurements->explicitCurrencyCodes($cells[$index]['text']);
        $claimCurrency = $this->measurements->isoCurrency((string) $kpi->unit);
        if (count($explicitCurrencies) > 1 || ($explicitCurrencies !== []
            && $explicitCurrencies[0] !== $claimCurrency)) {
            return ['rejection' => 'quantity_mismatch'];
        }
        if (preg_match('/\b(?:thousands?|millions?|billions?|trillions?)\b/iu',
            $cells[$index]['text'], $writtenScale)) {
            $claimedScale = $this->measurements->parse((string) $kpi->value, $kpi->unit, $kpi->label);
            $sourceScale = $this->measurements->parse((string) $kpi->value, $writtenScale[0], $kpi->label);
            if ($claimedScale === null || $sourceScale === null
                || $claimedScale->scale !== $sourceScale->scale) {
                return ['rejection' => 'scale_mismatch'];
            }
        }
        $claimed = $this->measurements->parse((string) $kpi->value, $kpi->unit, $kpi->label);
        $written = $this->measurements->parse($cells[$index]['text'], $kpi->unit, $kpi->label);
        if ($claimed === null || $written === null
            || $this->operator((string) $kpi->value) !== $this->operator($cells[$index]['text'])
            || ! BigDecimal::of($claimed->exactMagnitude)->isEqualTo($written->exactMagnitude)) {
            return ['rejection' => 'value_column_mismatch'];
        }
        $concept = (string) (($kpi->identity_metadata ?? [])['concept'] ?? $kpi->label);
        $conceptWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($concept), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $conceptWords = array_values(array_filter($conceptWords,
            static fn (string $word): bool => mb_strlen($word) >= 4
                && ! in_array($word, ['quarterly', 'rate', 'received', 'cases'], true)));
        $labelWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($cells[0]['text']), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $singular = static fn (string $word): string => str_ends_with($word, 'ies')
            ? substr($word, 0, -3).'y' : (str_ends_with($word, 's') ? substr($word, 0, -1) : $word);
        $labels = array_map($singular, $labelWords);
        $headerWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($columns[$index]), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $rowConcept = array_diff(array_map($singular, $conceptWords), $labels) === [];
        $headerConcept = array_diff(array_map($singular, $conceptWords),
            array_map($singular, $headerWords)) === [];
        $rowIdentity = mb_strtolower(trim($cells[0]['text']))
            === mb_strtolower(trim((string) $kpi->label))
            || mb_strtolower(trim($cells[0]['text']))
                === mb_strtolower(trim((string) ($kpi->identity_metadata['scope'] ?? '')));
        $legacyCaption = $this->precedingCaption($set, $header);
        $legacyCaptionWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($legacyCaption), -1,
            PREG_SPLIT_NO_EMPTY) ?: [];
        $legacyConcept = $rowConcept || ($period === ''
            && preg_match('/\btotal\s+proposed\b/iu', $cells[0]['text'])
            && array_diff(array_map($singular, $conceptWords),
                array_map($singular, $legacyCaptionWords)) === []);
        $conceptProven = config('document_intelligence.server_evidence_resolution', false)
            ? ($rowConcept || ($headerConcept && $rowIdentity)) : $legacyConcept;
        if ($conceptWords === [] || ! $conceptProven) {
            return ['rejection' => 'concept_row_mismatch'];
        }
        $cellStart = $value['start_offset'] + $cells[$index]['start'];

        return ['rejection' => null, 'source_value' => $cells[$index]['text'],
            'cell_start_offset' => $cellStart,
            'cell_end_offset' => $cellStart + mb_strlen($cells[$index]['text']),
            'basis_text' => trim($columns[$index]), 'header_span_id' => $header['span_id'],
            'header_continuation' => $continuation];
    }

    /** Accept one adjacent wrapped heading only when its lines complete the table's column count. */
    private function columns(EvidenceSpanSet $set, array $header, array $columns, int $cellCount): array
    {
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

    /** @return array{0:list<array{text:string,start:int}>,1:?string} */
    private function splitCells(string $text): array
    {
        $delimiter = str_contains($text, "\t") ? 'tab'
            : (substr_count($text, '|') >= 2 ? 'pipe'
                : (preg_match('/ {2,}/u', $text) ? 'space' : null));
        if ($delimiter === null) {
            return [[], null];
        }
        $pattern = match ($delimiter) {
            'tab' => '/(\t)/u', 'pipe' => '/(\|)/u', 'space' => '/( {2,})/u',
        };
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
        if (! is_array($parts)) {
            return [[], null];
        }
        $cells = [];
        foreach ($parts as $index => [$part, $byteStart]) {
            if ($index % 2 === 1) {
                continue;
            }
            if ($delimiter === 'pipe' && ($index === 0 || $index === count($parts) - 1)
                && trim($part) === '') {
                continue;
            }
            if (trim($part) === '') {
                return [[], $delimiter];
            }
            $leading = mb_strlen($part) - mb_strlen(ltrim($part));
            $cells[] = ['text' => trim($part),
                'start' => mb_strlen(substr($text, 0, $byteStart)) + $leading];
        }

        return [$cells, $delimiter];
    }

    private function periodColumnMatches(string $column, string $period, ?string $quarter): bool
    {
        $column = trim($column);
        if ($quarter !== null) {
            if (! preg_match('/^'.preg_quote($quarter, '/').'(?:\s|$)/iu', $column)) {
                return false;
            }
            if (preg_match('/^Q[1-4]\s+(?:FY)?\d{4}\b/iu', $column, $match)) {
                $claimed = $this->periods->parse($period);
                return $claimed === null && strtoupper($period) === $quarter
                    ? true : $this->periods->parse($match[0])?->label === $claimed?->label;
            }

            return true; // A bare quarter still needs the governing period-role proof.
        }
        if (! preg_match('/^(?:FY\s*\d{4}|\d{4})\b/iu', $column, $match)) {
            return false;
        }

        return $this->periods->parse($match[0])?->label === $this->periods->parse($period)?->label;
    }

    private function operator(string $value): ?string
    {
        if (! preg_match('/^\s*(≤|>=|≥|<=|<|>)/u', $value, $match)) {
            return null;
        }

        return match ($match[1]) {
            '<=' => '≤', '>=' => '≥', default => $match[1],
        };
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
