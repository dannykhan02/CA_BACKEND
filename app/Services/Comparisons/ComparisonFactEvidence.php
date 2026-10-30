<?php

namespace App\Services\Comparisons;

use App\Models\Document;
use App\Models\DocumentEvidence;

/** Projection of existing pipeline evidence; does not extract new claims. */
class ComparisonFactEvidence
{
    /** Literal scope signals in this observation's validated source context, never absence-as-proof. */
    public function reportingScope(array $spans): ?string
    {
        $text = implode("\n", array_column($spans, 'quote'));
        $consolidated = preg_match('/\bconsolidated\b/iu', $text) === 1;
        $standalone = preg_match('/\bstand[\s-]?alone\b|\b(?:separate|company.only|parent.only)\s+(?:financial\s+statements|accounts|basis)\b/iu', $text) === 1;

        return $consolidated && $standalone ? 'ambiguous' : ($consolidated ? 'consolidated' : ($standalone ? 'standalone' : null));
    }

    public function read(Document $document): array
    {
        $key = $document->ai_pipeline['key'] ?? null;
        if ($key === null) {
            return [];
        }
        $out = [];
        foreach (DocumentEvidence::where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $key)->whereNotNull('source_id')->orderBy('id')->get() as $row) {
            $spans = [];
            foreach ($row->sources ?? [] as $source) {
                $quote = $source['quote'] ?? null;
                if (! is_string($quote) || trim($quote) === '') {
                    continue;
                }
                $start = $source['start_offset'] ?? null;
                $end = $source['end_offset'] ?? null;
                if (is_int($start) && is_int($end) && $start >= 0 && $end > $start
                    && mb_substr((string) $document->extracted_text, $start, $end - $start) === $quote) {
                    $spans[] = array_intersect_key($source, array_flip(['span_id', 'chunk_id', 'page', 'start_offset', 'end_offset', 'quote', 'roles']));
                }
            }
            $out[$row->source_id][] = ['id' => $row->id, 'data' => $row->data ?? [], 'spans' => $spans];
        }

        return $out;
    }

    public function context(string $kind, array $evidence): array
    {
        $out = [];
        if ($kind === 'deadlines') {
            foreach (['subject' => 'responsible_party', 'reference' => 'obligation_reference'] as $field => $target) {
                $values = [];
                foreach ($evidence as $entry) {
                    $value = trim((string) ($entry['data'][$field] ?? ''));
                    if ($value !== '' && count(array_filter($entry['spans'], fn ($s) => str_contains($s['quote'], $value))) > 0) {
                        $values[$value] = true;
                    }
                }
                $out[$target] = count($values) === 1 ? array_key_first($values) : null;
                if (count($values) > 1) {
                    $out['ambiguous_source_context'] = true;
                }
            }
        }

        return $out;
    }
}
