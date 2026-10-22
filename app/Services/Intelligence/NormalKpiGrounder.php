<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\Extraction\StructuredExtractionStore;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Verifies field roles before a normal-route KPI becomes metric evidence. */
class NormalKpiGrounder
{
    public function __construct(private MeasurementParser $measurements, private PeriodParser $periods) {}

    public function record(Document $document, DocumentKpi $stored, array $proposal): ?string
    {
        $pipeline = $document->ai_pipeline ?? [];
        $context = $proposal['source_context'] ?? null;
        if (($pipeline['route'] ?? null) !== 'normal'
            || ($pipeline['normal_span_grounding'] ?? false) !== true
            || ! is_string($pipeline['key'] ?? null) || ! str_starts_with($pipeline['key'], 'normal:')
            || ! is_array($context)) {
            return 'missing_source_reference';
        }
        $set = app(EvidenceGrounding::class)->spans($document);
        $value = $this->span($set, $context['value_span_id'] ?? null, $document);
        if ($value === null) {
            return 'missing_source_reference';
        }
        $rawValue = trim((string) $stored->value);
        $tableHeaderId = trim((string) $stored->period) === ''
            && ($stored->identity_metadata['value_basis'] ?? null) === 'proposed'
            ? ($context['unit_span_id'] ?? null) : ($context['period_span_id'] ?? null);
        $headerSpan = $this->span($set, $tableHeaderId, $document);
        $tableProof = match ($document->type) {
            'PDF' => app(PdfTabularCellVerifier::class)->verify($stored, $set, $value, $headerSpan),
            'DOCX' => app(DocxTableCellVerifier::class)->verify($document, $stored, $set, $value, $headerSpan),
            default => null,
        };
        if (($tableProof['rejection'] ?? null) !== null) {
            return $tableProof['rejection'];
        }
        if ($rawValue === '' || ($tableProof === null && ! $this->containsValue($value['text'], $rawValue))) {
            return 'source_mismatch';
        }
        if (in_array(mb_strtolower(trim((string) (($stored->identity_metadata ?? [])['metric_type'] ?? ''))),
            ['identifier', 'id', 'code', 'reference'], true)) {
            return 'non_measurement_numeric';
        }
        $claimed = $this->measurements->parse($rawValue, $stored->unit, $stored->label);
        $rowCurrencies = $this->measurements->explicitCurrencyCodes($value['text']);
        if ($claimed === null || count($rowCurrencies) > 1
            || ($rowCurrencies !== [] && $rowCurrencies[0] !== $claimed->currency)) {
            return 'quantity_mismatch';
        }
        preg_match_all('/\b(?:thousands?|millions?|billions?|trillions?)\b/iu', $value['text'], $scales);
        $rowScales = array_values(array_unique(array_map('mb_strtolower', $scales[0])));
        if ($rowScales !== []) {
            $rowScale = $this->measurements->parse($rawValue, $rowScales[0]);
            if (count($rowScales) > 1 || $rowScale === null || $rowScale->scale !== $claimed->scale) {
                return 'scale_mismatch';
            }
        }
        // A repeated number by itself has no field role. Require a concept or descriptive
        // label in the same row/sentence as the value, and a unique occurrence of the value.
        $concept = trim((string) (($stored->identity_metadata ?? [])['concept'] ?? ''));
        if ($document->type !== 'XLSX' && $tableProof === null
            && ! $this->containsConcept($value['text'], $concept !== '' ? $concept : (string) $stored->label)) {
            return 'concept_mismatch';
        }
        $fields = ['value' => $value];
        $periodContext = null;
        foreach (['unit' => (string) $stored->unit, 'period' => (string) $stored->period,
            'subject' => (string) (($stored->identity_metadata ?? [])['scope'] ?? '')] as $role => $expected) {
            $expected = trim($expected);
            if ($expected === '') {
                continue;
            }
            $candidate = $this->span($set, $context[$role.'_span_id'] ?? $context['value_span_id'] ?? null, $document);
            if ($candidate === null) {
                return $role.'_source_mismatch';
            }
            $columnUnit = $role === 'unit' && $tableProof !== null
                && $candidate['span_id'] === ($tableProof['header_span_id'] ?? null)
                && $this->containsWords($tableProof['basis_text'], $expected);
            if ($role === 'period' && ! $this->containsWords($candidate['text'], $expected)) {
                $periodContext = $tableProof === null ? null
                    : $this->quarterPageContext($set, $candidate, $value, $expected, $tableProof);
            }
            if (! $this->containsWords($candidate['text'], $expected)
                && ! ($role === 'period' && $periodContext !== null) && ! $columnUnit) {
                return $role.'_source_mismatch';
            }
            if ($role === 'subject' && $tableProof !== null
                && $this->conflictingTableOwner($set, $tableProof, $value, $expected)) {
                return 'subject_relationship_unverified';
            }
            $nativeHeader = $role === 'period' && $document->type === 'DOCX'
                && $tableProof !== null && isset($tableProof['period_header_start_offset'])
                && $candidate['start_offset'] <= $tableProof['period_header_start_offset']
                && $candidate['end_offset'] >= $tableProof['period_header_end_offset'];
            if ($document->type !== 'XLSX' && $candidate['span_id'] !== $value['span_id']
                && ! $columnUnit
                && ! $nativeHeader
                && ! ($role === 'subject' && $this->pageHeaderSubject($set, $candidate, $value))
                && ! $this->governs($set, $candidate, $value)) {
                return $role.'_relationship_unverified';
            }
            $fields[$role] = $candidate + ['expected' => $expected];
        }
        if ($document->type === 'XLSX' && ! $this->xlsxRelationship($document, $value, $fields, $rawValue,
            $concept !== '' ? $concept : (string) $stored->label)) {
            return 'structured_relationship_unverified';
        }
        $basisRejection = app(ValueBasisEvidence::class)->rejection(
            $stored->identity_metadata['value_basis'] ?? null,
            $tableProof === null ? implode("\n", array_unique(array_column($fields, 'text')))
                : $value['text']."\n".$tableProof['basis_text']);
        if ($basisRejection !== null) {
            return $basisRejection;
        }
        $rawPeriod = (string) $stored->period;
        if (trim($rawPeriod) !== '' && $this->periods->parse($rawPeriod) === null) {
            $resolved = $this->periods->resolveQuarter($rawPeriod,
                ($fields['period']['text'] ?? '')."\n".($tableProof === null ? '' : ($fields['subject']['text'] ?? '')),
                $value['text']);
            if ($resolved === null) {
                return 'period_ambiguous';
            }
            $identity = is_array($stored->identity_metadata) ? $stored->identity_metadata : [];
            $stored->forceFill(['period' => $resolved->label,
                'identity_metadata' => [...$identity, 'period' => $resolved->label,
                    'raw_period' => $rawPeriod]])->save();
        }
        $identity = $stored->identity_metadata ?? [];
        $kind = $identity['metric_type'] ?? null;
        if (trim((string) $stored->unit) === '' && in_array($kind, ['ratio', 'index'], true)
            && ! $this->containsWords($value['text'], $kind)) {
            return 'dimensionless_meaning_unverified';
        }
        $sources = [];
        foreach ($fields as $role => $span) {
            $key = $span['span_id'];
            if (! isset($sources[$key])) {
                $sources[$key] = ['span_id' => $key, 'quote' => $span['text'], 'page' => $span['page'],
                    'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'], 'roles' => []];
            }
            $sources[$key]['roles'][] = $role;
        }
        if ($periodContext !== null) {
            $key = $periodContext['span_id'];
            if (! isset($sources[$key])) {
                $sources[$key] = ['span_id' => $key, 'quote' => $periodContext['text'],
                    'page' => $periodContext['page'], 'start_offset' => $periodContext['start_offset'],
                    'end_offset' => $periodContext['end_offset'], 'roles' => []];
            }
            $sources[$key]['roles'][] = 'period';
        }
        if (($tableProof['header_continuation'] ?? null) !== null) {
            $continuation = $tableProof['header_continuation'];
            $sources[$continuation['span_id']] = ['span_id' => $continuation['span_id'],
                'quote' => $continuation['text'], 'page' => $continuation['page'],
                'start_offset' => $continuation['start_offset'],
                'end_offset' => $continuation['end_offset'], 'roles' => ['unit']];
        }
        $sourceId = 'kpi:'.$stored->id;
        DocumentEvidence::updateOrCreate([
            'document_id' => $document->id, 'pipeline_key' => $pipeline['key'],
            'identity' => hash('sha256', $sourceId),
        ], [
            'workspace_id' => $document->workspace_id, 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['label' => $stored->label, 'value' => $stored->value, 'unit' => $stored->unit,
                'period' => $stored->period, 'raw_period' => $rawPeriod,
                'subject' => $identity['scope'] ?? '',
                'concept' => $identity['concept'] ?? null,
                'metric_type' => $identity['metric_type'] ?? null,
                'value_basis' => $identity['value_basis'] ?? null,
                'aggregation' => $identity['aggregation'] ?? null,
                'quantity_kind' => $identity['quantity_kind'] ?? null,
                'measurement_verification' => 'verified', 'source_fields_verified' => array_keys($fields),
                'source_revision' => hash('sha256', (string) $document->extracted_text),
                'source_cell_start_offset' => $tableProof['cell_start_offset'] ?? null,
                'source_cell_end_offset' => $tableProof['cell_end_offset'] ?? null,
                'source_cell_value' => $tableProof['source_value'] ?? null,
                'source_column_basis' => $tableProof['basis_text'] ?? null,
                'raw_value' => $stored->value, 'raw_unit' => $stored->unit,
                'confidence' => 1.0], 'sources' => array_values($sources),
        ]);

        return null;
    }

    private function span(EvidenceSpanSet $set, mixed $id, Document $document): ?array
    {
        if (! is_string($id) || ! preg_match('/^E[0-9]{3,}$/D', $id)) {
            return null;
        }
        $span = $set->resolve($id);
        if ($span === null || $span['text'] === ''
            || mb_substr((string) $document->extracted_text, $span['start_offset'],
                $span['end_offset'] - $span['start_offset']) !== $span['text']) {
            return null;
        }

        return $span;
    }

    private function containsValue(string $text, string $value): bool
    {
        $number = preg_replace('/[,%\s]/u', '', $value);
        if (! is_numeric($number)) {
            return false;
        }
        // Source-facing representation is retained; silently rounding an AI value would
        // destroy the link between a KPI and the exact number on the page.
        $pattern = '/(?<![\d.])'.preg_quote($value, '/').'(?![\d.])/u';

        return preg_match_all($pattern, $text) === 1;
    }

    private function containsConcept(string $text, string $concept): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($concept), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 4 && ! preg_match('/^\d+$/', $word)));
        if ($words === []) {
            return false;
        }
        $source = mb_strtolower($text);

        // A concept may be reworded in the source, but the cited value still needs a
        // descriptive lexical anchor. Other semantic claims remain proposals.
        return count(array_filter($words, fn ($word) => preg_match('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u', $source) === 1))
            >= min(2, count($words));
    }

    private function containsWords(string $text, string $expected): bool
    {
        if ($expected === '%') {
            return str_contains($text, '%');
        }
        $words = preg_split('/\s+/u', trim($expected)) ?: [];
        foreach ($words as $word) {
            if (! preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                return false;
            }
        }

        return $words !== [];
    }

    private function governs(EvidenceSpanSet $set, array $heading, array $value): bool
    {
        if ($heading['page'] === null || $heading['page'] !== $value['page']
            || $heading['start_offset'] >= $value['start_offset']
            || ! in_array($heading['type'], ['heading', 'section', 'table_row', 'list_item'], true)
            || $value['type'] !== 'table_row') {
            return false;
        }
        $between = 0;
        foreach ($set->all() as $span) {
            if ($span['start_offset'] <= $heading['start_offset'] || $span['start_offset'] >= $value['start_offset']) {
                continue;
            }
            if ($span['type'] !== 'table_row' || ++$between > 25) {
                return false;
            }
        }

        return true;
    }

    private function pageHeaderSubject(EvidenceSpanSet $set, array $heading, array $value): bool
    {
        if ($heading['page'] === null || $heading['page'] !== $value['page']) {
            return false;
        }
        foreach ($set->all() as $span) {
            if ($span['page'] === $value['page']) {
                return $span['key'] === $heading['span_id']
                    && $heading['start_offset'] < $value['start_offset'];
            }
        }

        return false;
    }

    /** A directly governing table caption outranks a running document banner. */
    private function conflictingTableOwner(EvidenceSpanSet $set, array $tableProof,
        array $value, string $subject): bool
    {
        if (isset($tableProof['header_span_id'])) {
            $previous = null;
            foreach ($set->all() as $span) {
                if ($span['key'] === $tableProof['header_span_id']) {
                    if ($previous !== null && $previous['page'] === $value['page']) {
                        $caption = $set->resolve($previous['key']);
                        if ($caption !== null && $subject !== ''
                            && preg_match('/\b(?:Ltd|Limited|PLC|LLC|Inc|Corporation)\b/iu',
                            $caption['text']) === 1
                            && ! $this->containsWords($caption['text'], $subject)) {
                            return true;
                        }
                    }
                    break;
                }
                $previous = $span;
            }
        }
        return false;
    }

    /** A Q3 column under a Q3 2026 page heading proves the full period only for Q3. */
    private function quarterPageContext(EvidenceSpanSet $set, array $column, array $value,
        string $expected, array $tableProof): ?array
    {
        $period = $this->periods->parse($expected);
        if ($period?->granularity !== 'quarter'
            || ! preg_match('/^(Q[1-4])\s+(?:FY)?\d{4}$/iuD', $period->label, $match)
            || ! preg_match('/^'.preg_quote($match[1], '/').'(?:\s|$)/iu', trim($tableProof['basis_text'] ?? ''))
            || $column['page'] === null || $column['page'] !== $value['page']) {
            return null;
        }
        $pageHeader = null;
        foreach ($set->all() as $span) {
            if ($span['page'] !== $value['page']) {
                continue;
            }
            $pageHeader = $span;
            break;
        }
        $pageHeader = $pageHeader === null ? null : $set->resolve($pageHeader['key']);
        if ($pageHeader === null || ! $this->pageHeaderSubject($set, $pageHeader, $value)
            || ! $this->containsWords($pageHeader['text'], $period->label)) {
            return null;
        }
        // A conflicting explicit year on the way to the cited row makes the context ambiguous.
        foreach ($set->all() as $span) {
            if ($span['page'] !== $value['page'] || $span['start_offset'] >= $value['start_offset']) {
                continue;
            }
            $span = $set->resolve($span['key']);
            if (preg_match_all('/\b'.preg_quote($match[1], '/').'\s+(?:FY)?\d{4}\b/iu',
                $span['text'], $periods)) {
                foreach ($periods[0] as $written) {
                    if ($this->periods->parse($written)?->label !== $period->label) {
                        return null;
                    }
                }
            }
        }

        return $pageHeader;
    }

    private function xlsxRelationship(Document $document, array $valueSpan, array $fields,
        string $rawValue, string $concept): bool
    {
        $source = app(StructuredExtractionStore::class)->active($document);
        if ($source?->format !== 'XLSX') {
            return false;
        }
        $nodes = collect($source->nodes)->keyBy('id');
        $cells = $nodes->filter(fn ($node) => ($node['type'] ?? null) === 'cell');
        $matches = $cells->filter(fn ($cell) => isset($cell['start'], $cell['end'])
            && $cell['start'] >= $valueSpan['start_offset'] && $cell['end'] <= $valueSpan['end_offset']
            && trim((string) ($cell['text'] ?? '')) === $rawValue && $this->visibleCell($cell));
        if ($matches->count() !== 1) {
            return false;
        }
        $valueCell = $matches->first();
        $owners = $nodes->filter(fn ($node) => ($node['type'] ?? null) === 'table'
            && ($node['sheet'] ?? null) === ($valueCell['sheet'] ?? null)
            && ($node['meta']['header_row'] ?? false) === true
            && isset($node['meta']['range'])
            && $this->inRange($valueCell['meta']['coordinate'], $node['meta']['range']));
        if ($owners->count() !== 1) {
            return false;
        }
        $table = $owners->first();
        $bounds = Coordinate::rangeBoundaries($table['meta']['range']);
        [$column, $row] = Coordinate::coordinateFromString($valueCell['meta']['coordinate']);
        $columnNumber = Coordinate::columnIndexFromString($column);
        if ($row <= $bounds[0][1] || $columnNumber <= $bounds[0][0]) {
            return false;
        }
        $header = $cells->first(fn ($cell) => ($cell['sheet'] ?? null) === $valueCell['sheet']
            && ($cell['meta']['coordinate'] ?? null) === $column.$bounds[0][1]
            && $this->visibleCell($cell));
        $rowLabelCoordinate = Coordinate::stringFromColumnIndex($bounds[0][0]).$row;
        $rowLabel = $cells->first(fn ($cell) => ($cell['sheet'] ?? null) === $valueCell['sheet']
            && ($cell['meta']['coordinate'] ?? null) === $rowLabelCoordinate
            && $this->visibleCell($cell));
        if ($header === null || $rowLabel === null
            || (! $this->containsConcept((string) $rowLabel['text'], $concept)
                && ! $this->containsConcept((string) $header['text'], $concept))) {
            return false;
        }
        foreach (['unit', 'period'] as $role) {
            if (isset($fields[$role]) && (! isset($header['start'], $header['end'])
                || $header['start'] < $fields[$role]['start_offset']
                || $header['end'] > $fields[$role]['end_offset']
                || ! $this->containsWords((string) $header['text'], (string) $fields[$role]['expected']))) {
                return false;
            }
        }
        if (isset($fields['subject']) && $fields['subject']['span_id'] !== $valueSpan['span_id']) {
            return false;
        }

        return true;
    }

    private function inRange(string $coordinate, string $range): bool
    {
        $bounds = Coordinate::rangeBoundaries($range);
        [$column, $row] = Coordinate::coordinateFromString($coordinate);
        $number = Coordinate::columnIndexFromString($column);

        return $number >= $bounds[0][0] && $number <= $bounds[1][0]
            && $row >= $bounds[0][1] && $row <= $bounds[1][1];
    }

    private function visibleCell(array $cell): bool
    {
        $meta = $cell['meta'] ?? [];

        return ($meta['sheet_visibility'] ?? 'visible') === 'visible'
            && ($meta['row_visible'] ?? true) === true
            && ($meta['column_visible'] ?? true) === true
            && ($meta['formula'] ?? null) === null;
    }
}
