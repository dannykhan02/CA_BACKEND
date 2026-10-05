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
            + strlen(json_encode(['document_name' => str_repeat('x', 255), 'start_page' => 99999, 'end_page' => 99999, 'source_text' => ''])) + 512;
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

    private function outputPer1kInput(): float
    {
        return (float) config('document_intelligence.expected_records_per_1k_tokens')
            * (int) config('document_intelligence.output_tokens_per_record');
    }

    public function expectedOutputTokens(int $documentTokens): int
    {
        $records = (int) ceil($documentTokens / 1000 * (float) config('document_intelligence.expected_records_per_1k_tokens'));

        return $records * (int) config('document_intelligence.output_tokens_per_record') + 64; // JSON envelope.
    }

    /** Largest input slice whose expected output AND input both fit one request. */
    public function partitionTokens(): int
    {
        $byOutput = (int) floor(max(0, $this->outputCapacity() - 64) / max(1, $this->outputPer1kInput()) * 1000);
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
    public function decide(int $documentTokens): array
    {
        $capabilities = $this->capabilities();
        $input = $this->inputCapacity();
        $partition = $this->partitionTokens();
        $expected = $this->expectedOutputTokens($documentTokens);
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
        ];
    }
}
