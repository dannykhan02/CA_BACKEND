<?php

namespace App\Services\AI\Incremental;

/** Deterministic, source-only estimate. It is never used to reject extracted records. */
class ProactiveChunkRisk
{
    public function assess(string $text, int $inputTokens, ?EvidenceSpanSet $spans = null, ?string $type = null): array
    {
        $capacity = app(ExtractionCapacity::class);
        $density = $capacity->density($text, $type);
        $units = $spans?->all() ?? app(SourceSpanBuilder::class)->build($text);
        $tables = count(array_filter($units, fn ($unit) => $unit['type'] === 'table_row'));
        $lists = count(array_filter($units, fn ($unit) => $unit['type'] === 'list_item'));
        $base = (int) ceil($inputTokens / 1000 * $density['records_per_1k_tokens']);
        // One figure-heavy row can yield several records; this floor catches dense islands
        // diluted by prose when a whole-document average classifies the root as narrative.
        $records = max($base, (int) ceil($tables * 1.5 + $lists * 0.5));
        $output = $records * (int) config('document_intelligence.output_tokens_per_record') + 64;
        $safe = $capacity->outputCapacity();
        $minimum = (int) config('document_intelligence.minimum_split_chars');

        return [
            'input_tokens' => $inputTokens, 'source_chars' => mb_strlen($text), 'source_spans' => count($units),
            'table_rows' => $tables, 'list_items' => $lists, 'numeric_ratio' => $density['numeric_ratio'],
            'expected_records' => $records, 'expected_output_tokens' => $output,
            'safe_output_tokens' => $safe, 'output_saturation_risk' => $output >= $safe,
            // A 10% headroom below the existing safe output target is reserved only for
            // structurally dense chunks; narrative needs to exceed the target itself.
            'split' => mb_strlen($text) >= $minimum * 2
                && ($output > $safe || (($tables >= 12 || $lists >= 30) && $output >= (int) floor($safe * 0.9))),
        ];
    }
}
