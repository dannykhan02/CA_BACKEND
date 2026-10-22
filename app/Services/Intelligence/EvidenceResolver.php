<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentKpi;
use App\Services\AI\Incremental\EvidenceSpanSet;

/** Bounded repair of insufficient citations from a genuine, unchanged source anchor. */
class EvidenceResolver
{
    public function __construct(private PeriodParser $periods,
        private PdfTabularCellVerifier $tables) {}

    /** @return array{context:array,period:?string,changes:array} */
    public function resolve(Document $document, DocumentKpi $kpi, EvidenceSpanSet $set,
        array $context): array
    {
        $unchanged = ['context' => $context, 'period' => null, 'changes' => []];
        if ($document->type !== 'PDF'
            || ($document->ai_pipeline['text_hash'] ?? hash('sha256', (string) $document->extracted_text))
                !== hash('sha256', (string) $document->extracted_text)) {
            return $unchanged;
        }
        $value = $this->span($document, $set, $context['value_span_id'] ?? null);
        $headerId = trim((string) $kpi->period) === ''
            && ($kpi->identity_metadata['value_basis'] ?? null) === 'proposed'
            ? ($context['unit_span_id'] ?? null) : ($context['period_span_id'] ?? null);
        $header = $this->span($document, $set, $headerId);
        if ($value === null || $header === null || $value['type'] !== 'table_row'
            || $header['type'] !== 'table_row' || $value['page'] === null
            || $header['page'] !== $value['page']) {
            return $unchanged;
        }
        $period = trim((string) $kpi->period);
        $basis = mb_strtolower(trim((string) ($kpi->identity_metadata['value_basis'] ?? '')));
        $columnPeriod = $period;
        if (preg_match('/^(Q[1-4])\s+(target|proposed|forecast|budget)$/iuD', $period, $match)) {
            if (mb_strtolower($match[2]) !== $basis
                || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($period, '/').'(?![\p{L}\p{N}])/iu',
                    $header['text']) !== 1) {
                return $unchanged;
            }
            $columnPeriod = strtoupper($match[1]);
        }
        $resolvedPeriod = null;
        if (preg_match('/^Q[1-4]$/iuD', $columnPeriod)) {
            $pageHeader = null;
            foreach ($set->all() as $span) {
                if ($span['page'] === $value['page']) {
                    $pageHeader = $set->resolve($span['key']);
                    break;
                }
            }
            if ($pageHeader !== null && $pageHeader['start_offset'] < $header['start_offset']) {
                $resolvedPeriod = $this->periods->resolveQuarter($columnPeriod,
                    $pageHeader['text'])?->label;
            }
        }
        $trial = clone $kpi;
        $trial->period = $resolvedPeriod ?? $columnPeriod;
        $proof = $this->tables->verify($trial, $set, $value, $header);
        if ($proof === null || ! array_key_exists('rejection', $proof)
            || $proof['rejection'] !== null) {
            return $unchanged;
        }
        $changes = [];
        if ($trial->period !== $period) {
            $changes['period'] = ['from' => $period, 'to' => $trial->period,
                'header_span_id' => $header['span_id']];
        }
        $scope = trim((string) ($kpi->identity_metadata['scope'] ?? ''));
        if ($scope !== '' && ($context['subject_span_id'] ?? null) === $value['span_id']) {
            $owners = [];
            $previous = null;
            foreach ($set->all() as $span) {
                if ($span['key'] === $header['span_id']) {
                    if ($previous !== null && $previous['page'] === $value['page']) {
                        $caption = $set->resolve($previous['key']);
                        if ($caption !== null && $this->containsWords($caption['text'], $scope)) {
                            $owners[] = $caption;
                        }
                    }
                    break;
                }
                $previous = $span;
            }
            if ($owners === []) {
                foreach ($set->all() as $span) {
                    if ($span['page'] !== $value['page'] || $span['start_offset'] >= $header['start_offset']
                        || ! in_array($span['type'], ['heading', 'section', 'table_row'], true)) {
                        continue;
                    }
                    $source = $set->resolve($span['key']);
                    if ($source !== null && $this->containsWords($source['text'], $scope)) {
                        $owners[] = $source;
                    }
                }
            }
            if (count($owners) === 1) {
                $context['subject_span_id'] = $owners[0]['span_id'];
                $changes['subject'] = ['from' => $value['span_id'],
                    'to' => $owners[0]['span_id']];
            }
        }
        return ['context' => $context, 'period' => $changes['period']['to'] ?? null,
            'changes' => $changes];
    }

    private function span(Document $document, EvidenceSpanSet $set, mixed $id): ?array
    {
        if (! is_string($id)) {
            return null;
        }
        $span = $set->resolve($id);
        return $span !== null && mb_substr((string) $document->extracted_text,
            $span['start_offset'], $span['end_offset'] - $span['start_offset']) === $span['text']
            ? $span : null;
    }

    private function containsWords(string $text, string $expected): bool
    {
        foreach (preg_split('/\s+/u', $expected) ?: [] as $word) {
            if (! preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                return false;
            }
        }
        return true;
    }
}
