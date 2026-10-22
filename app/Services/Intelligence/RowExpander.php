<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceGrounding;

/** Enumerates only explicitly dated sibling measurements in a proven source row. */
class RowExpander
{
    public function __construct(private PeriodParser $periods,
        private PdfTabularCellVerifier $tables) {}

    public function expand(Document $document, array $modelKpis): array
    {
        if ($document->type !== 'PDF' || ! config('document_intelligence.server_evidence_resolution', false)
            || ($document->ai_pipeline['text_hash'] ?? hash('sha256', (string) $document->extracted_text))
                !== hash('sha256', (string) $document->extracted_text)) {
            return [];
        }
        $set = app(EvidenceGrounding::class)->spans($document);
        $seen = [];
        foreach ($modelKpis as $kpi) {
            $seen[$this->key($kpi)] = true;
        }
        $expanded = [];
        foreach ($modelKpis as $kpi) {
            $context = $kpi['source_context'] ?? [];
            $row = $set->resolve((string) ($context['value_span_id'] ?? ''));
            $header = $set->resolve((string) ($context['period_span_id'] ?? ''));
            if ($row === null || $header === null || $row['type'] !== 'table_row'
                || $header['type'] !== 'table_row' || $row['page'] === null
                || $row['page'] !== $header['page']
                || $row['start_offset'] <= $header['start_offset']) {
                continue;
            }
            $between = mb_substr((string) $document->extracted_text, $header['end_offset'],
                $row['start_offset'] - $header['end_offset']);
            if (preg_match('/\R\s*\R/u', $between)) {
                continue; // A visible table boundary cannot be crossed by row expansion.
            }
            $rows = $this->cells($row['text']);
            $headers = $this->cells($header['text']);
            if ($rows === null || $headers === null || count($rows) !== count($headers)
                || count($rows) < 3 || count($rows) > 24) {
                continue;
            }
            $periods = [];
            foreach (array_slice($headers, 1, null, true) as $index => $heading) {
                $parsed = $this->periods->parse($heading);
                if ($parsed === null || ! in_array($parsed->granularity,
                    ['quarter', 'month', 'year', 'half'], true)) {
                    $periods = [];
                    break;
                }
                $periods[$index] = $parsed->label;
            }
            if (count($periods) < 2 || count(array_unique($periods)) !== count($periods)) {
                continue;
            }
            // The model's anchor must already prove its own cell. Other cells are then
            // submitted independently to the same ordinary grounder and integrity gates.
            $original = new DocumentKpi;
            $original->forceFill(['label' => $kpi['label'], 'value' => $kpi['value'],
                'unit' => $kpi['unit'], 'period' => $kpi['period'],
                'identity_metadata' => $kpi['identity']]);
            $proof = $this->tables->verify($original, $set, $row, $header);
            if ($proof === null || ($proof['rejection'] ?? null) !== null) {
                continue;
            }
            foreach ($periods as $index => $period) {
                $value = trim($rows[$index]);
                if ($value === '' || $this->periods->parse($period) === null) {
                    continue;
                }
                $candidate = $kpi;
                $candidate['value'] = $value;
                $candidate['period'] = $period;
                $candidate['identity'] = [...$kpi['identity'], 'period' => $period,
                    'origin' => 'server_row_expansion',
                    'expanded_from_span_id' => $row['span_id']];
                $key = $this->key($candidate);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $expanded[] = $candidate;
            }
        }
        return $expanded;
    }

    private function cells(string $text): ?array
    {
        if (str_contains($text, "\t")) {
            return array_map('trim', explode("\t", $text));
        }
        if (substr_count($text, '|') >= 2) {
            return array_values(array_filter(array_map('trim', explode('|', $text)),
                static fn ($part) => $part !== ''));
        }
        return null;
    }

    private function key(array $kpi): string
    {
        $identity = $kpi['identity'] ?? [];
        return hash('sha256', implode("\0", [
            mb_strtolower(trim((string) ($identity['concept'] ?? $kpi['label'] ?? ''))),
            mb_strtolower(trim((string) ($identity['scope'] ?? ''))),
            mb_strtolower(trim((string) ($identity['value_basis'] ?? ''))),
            mb_strtolower(trim((string) ($identity['period'] ?? $kpi['period'] ?? ''))),
        ]));
    }
}
