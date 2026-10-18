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
    public function __construct(private MeasurementParser $measurements) {}

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
        if ($rawValue === '' || ! $this->containsValue($value['text'], $rawValue)) {
            return 'source_mismatch';
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
        if (! $this->containsConcept($value['text'], $concept !== '' ? $concept : (string) $stored->label)) {
            return 'concept_mismatch';
        }
        $fields = ['value' => $value];
        foreach (['unit' => (string) $stored->unit, 'period' => (string) $stored->period,
            'subject' => (string) (($stored->identity_metadata ?? [])['scope'] ?? '')] as $role => $expected) {
            $expected = trim($expected);
            if ($expected === '') {
                continue;
            }
            $candidate = $this->span($set, $context[$role.'_span_id'] ?? $context['value_span_id'] ?? null, $document);
            if ($candidate === null || ! $this->containsWords($candidate['text'], $expected)) {
                return $role.'_source_mismatch';
            }
            if ($document->type !== 'XLSX' && $candidate['span_id'] !== $value['span_id']
                && ! $this->governs($set, $candidate, $value)) {
                return $role.'_relationship_unverified';
            }
            $fields[$role] = $candidate + ['expected' => $expected];
        }
        if ($document->type === 'XLSX' && ! $this->xlsxRelationship($document, $value, $fields, $rawValue,
            $concept !== '' ? $concept : (string) $stored->label)) {
            return 'structured_relationship_unverified';
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
        $sourceId = 'kpi:'.$stored->id;
        DocumentEvidence::updateOrCreate([
            'document_id' => $document->id, 'pipeline_key' => $pipeline['key'],
            'identity' => hash('sha256', $sourceId),
        ], [
            'workspace_id' => $document->workspace_id, 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['label' => $stored->label, 'value' => $stored->value, 'unit' => $stored->unit,
                'period' => $stored->period, 'subject' => $identity['scope'] ?? '',
                'concept' => $identity['concept'] ?? null,
                'metric_type' => $identity['metric_type'] ?? null,
                'value_basis' => $identity['value_basis'] ?? null,
                'aggregation' => $identity['aggregation'] ?? null,
                'quantity_kind' => $identity['quantity_kind'] ?? null,
                'measurement_verification' => 'verified', 'source_fields_verified' => array_keys($fields),
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
            || ! in_array($heading['type'], ['heading', 'section', 'table_row'], true)
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
        if ($header === null || $rowLabel === null || ! $this->containsConcept((string) $rowLabel['text'], $concept)) {
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
