<?php

namespace App\Services\AI\Incremental;

use App\Services\AI\AiModels;

/**
 * Context-aware extraction routing. Every number is derived from the configured
 * model's context window / output cap, the real prompt + schema overhead and the
 * configured output headroom - never from a fixed document-size threshold.
 *
 *   document_tokens + prompt_overhead + output_tokens + safety_margin <= context_window
 *   expected_output(document_tokens) <= output_tokens * output_fill_ratio
 *
 * direct: one request holds the whole document AND its expected structured output.
 * coarse: the document fits the context, but its expected output does not, so it is
 *         split into the fewest partitions whose output fits one response.
 * deep:   the document exceeds one request's safe context. Partitions are sized the
 *         same way; recursive splitting stays a runtime fallback for every mode and
 *         only for capacity failures.
 */
class ExtractionCapacity
{
    public function model(): string
    {
        return app(AiModels::class)->forTask('extraction');
    }

    /** Explicit configured capabilities; an unknown model gets the most conservative configured entry. */
    public function capabilities(?string $model = null): array
    {
        $model ??= $this->model();
        // Model IDs can contain dots, so avoid Laravel's dotted lookup.
        $all = config('document_intelligence.model_capabilities', []);
        if (isset($all[$model])) {
            return [...$all[$model], 'known' => true];
        }
        $context = $all ? min(array_column($all, 'context_window')) : 0;
        $output = $all ? min(array_column($all, 'max_output_tokens')) : 0;

        return ['context_window' => $context, 'max_output_tokens' => $output, 'known' => false];
    }

    /** Per-request output cap: configured headroom bounded by the model's own maximum. */
    public function outputTokens(): int
    {
        return max(1, min((int) config('document_intelligence.extraction_max_tokens'), $this->capabilities()['max_output_tokens']));
    }

    /** Bytes are a conservative upper bound for tokens of the fixed request envelope. */
    public function promptOverheadTokens(): int
    {
        return strlen(EvidenceSchema::instructions()) + strlen(json_encode(EvidenceSchema::extraction()))
            + strlen(json_encode(['document_name' => str_repeat('x', 255), 'start_page' => 99999, 'end_page' => 99999, 'max_records' => 99999, 'source_text' => ''])) + 512;
    }

    public function safetyMarginTokens(): int
    {
        return (int) ceil($this->capabilities()['context_window'] * (float) config('document_intelligence.context_safety_ratio'));
    }

    /** Largest source slice one extraction request can safely carry. */
    public function inputCapacity(): int
    {
        return max(0, $this->capabilities()['context_window'] - $this->outputTokens()
            - $this->promptOverheadTokens() - $this->safetyMarginTokens());
    }

    /** Planned structured output per request; the remainder is headroom for dense slices. */
    public function outputCapacity(): int
    {
        return (int) floor($this->outputTokens() * (float) config('document_intelligence.output_fill_ratio'));
    }

    /**
     * Deterministic evidence-density signals (metadata only). Spreadsheets and numeric tables
     * produce one schema record per figure, so they are planned with the dense record rate.
     */
    public function density(string $text, ?string $type = null): array
    {
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $numeric = count(preg_grep('/^[(\-+]?[$€£]?\d[\d,.]*%?\)?$/u', $tokens));
        $lines = array_filter(preg_split('/\R/u', $text) ?: [], fn ($line) => trim($line) !== '');
        // A table row: tab- or pipe-delimited, or at least three figures making up 40%+ of its tokens.
        // A prose paragraph that merely mentions a few figures is not tabular.
        $tabular = count(array_filter($lines, function ($line) {
            if (str_contains($line, "\t") || substr_count($line, '|') >= 2) {
                return true;
            }
            $cells = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $figures = count(preg_grep('/^[(\-+]?[$€£]?\d[\d,.]*%?\)?$/u', $cells));

            return $figures >= 3 && $figures / max(1, count($cells)) >= 0.4;
        }));
        $numericRatio = $tokens ? round($numeric / count($tokens), 3) : 0.0;
        $tabularRatio = $lines ? round($tabular / count($lines), 3) : 0.0;
        $spreadsheet = in_array(strtoupper((string) $type), ['XLSX', 'XLS', 'CSV'], true);
        $dense = $spreadsheet || $numericRatio >= (float) config('document_intelligence.dense_numeric_ratio')
            || $tabularRatio >= (float) config('document_intelligence.dense_tabular_line_ratio');

        return ['dense' => $dense, 'spreadsheet' => $spreadsheet, 'numeric_ratio' => $numericRatio, 'tabular_line_ratio' => $tabularRatio,
            'records_per_1k_tokens' => (float) config($dense ? 'document_intelligence.dense_records_per_1k_tokens' : 'document_intelligence.expected_records_per_1k_tokens')];
    }

    private function recordsPer1k(?float $recordsPer1k): float
    {
        return $recordsPer1k ?? (float) config('document_intelligence.expected_records_per_1k_tokens');
    }

    private function outputPer1kInput(?float $recordsPer1k = null): float
    {
        return $this->recordsPer1k($recordsPer1k) * (int) config('document_intelligence.output_tokens_per_record');
    }

    public function expectedOutputTokens(int $documentTokens, ?float $recordsPer1k = null): int
    {
        $records = (int) ceil($documentTokens / 1000 * $this->recordsPer1k($recordsPer1k));

        return $records * (int) config('document_intelligence.output_tokens_per_record') + 64; // JSON envelope.
    }

    /**
     * Records one request may return. Sent with every extraction request so the model ends its
     * response inside the output budget instead of being truncated at max_tokens (which discards
     * the whole response and forces a split).
     */
    public function recordLimit(): int
    {
        return max(1, intdiv(max(0, $this->outputCapacity() - 64), max(1, (int) config('document_intelligence.output_tokens_per_record'))));
    }

    /** Largest input slice whose expected output AND input both fit one request. */
    public function partitionTokens(?float $recordsPer1k = null): int
    {
        // Whole records, matching expectedOutputTokens()' rounding: a full-size slice's expected
        // output (ceil of its records) never exceeds the planned output capacity.
        $byOutput = (int) floor($this->recordLimit() / max(0.001, $this->recordsPer1k($recordsPer1k)) * 1000);
        $tokens = min($this->inputCapacity(), $byOutput);
        // Optional operator ceiling (null by default): never larger than derived capacity.
        if (config('document_intelligence.chunk_max_tokens')) {
            $tokens = min($tokens, (int) config('document_intelligence.chunk_max_tokens'));
        }

        return max(1, $tokens);
    }

    /** Preflight bound for one request's actual counted input. */
    public function maxRequestInputTokens(): int
    {
        $tokens = $this->inputCapacity();
        if (config('document_intelligence.chunk_max_tokens')) {
            $tokens = min($tokens, (int) config('document_intelligence.chunk_max_tokens'));
        }

        return max(1, $tokens);
    }

    /** Deterministic route decision plus metadata-only diagnostics. */
    public function decide(int $documentTokens, ?array $density = null): array
    {
        $capabilities = $this->capabilities();
        $input = $this->inputCapacity();
        $rate = $density['records_per_1k_tokens'] ?? null;
        $partition = $this->partitionTokens($rate);
        $expected = $this->expectedOutputTokens($documentTokens, $rate);
        $mode = match (true) {
            $documentTokens <= $partition && $expected <= $this->outputCapacity() => 'direct',
            $documentTokens <= $input => 'coarse',
            default => 'deep',
        };

        return [
            'mode' => $mode,
            'model' => $this->model(),
            'capabilities_known' => $capabilities['known'],
            'document_tokens' => $documentTokens,
            'context_window' => $capabilities['context_window'],
            'max_output_tokens' => $this->outputTokens(),
            'prompt_overhead_tokens' => $this->promptOverheadTokens(),
            'safety_margin_tokens' => $this->safetyMarginTokens(),
            'input_capacity_tokens' => $input,
            'output_capacity_tokens' => $this->outputCapacity(),
            'expected_output_tokens' => $expected,
            'partition_tokens' => $partition,
            // Reporting only: positive = spare capacity for one whole-document request.
            'input_headroom_tokens' => $input - $documentTokens,
            'output_headroom_tokens' => $this->outputCapacity() - $expected,
            'planned_partitions' => $mode === 'direct' ? 1 : (int) ceil($documentTokens / $partition),
            'record_limit' => $this->recordLimit(),
            'dense' => (bool) ($density['dense'] ?? false),
            'records_per_1k_tokens' => $this->recordsPer1k($rate),
            'numeric_ratio' => (float) ($density['numeric_ratio'] ?? 0),
            'tabular_line_ratio' => (float) ($density['tabular_line_ratio'] ?? 0),
        ];
    }
}
