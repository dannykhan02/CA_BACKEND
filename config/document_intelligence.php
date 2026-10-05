<?php

return [
    // Explicit rollout switch; legacy ENV names retain their meaning.
    'incremental' => (bool) env('DOCINTEL_INCREMENTAL_PROCESSING', true),
    'pipeline_version' => '1',
    'prompt_version' => '1',
    // Eligibility for the legacy four-job path only: it truncates at
    // document_processing.max_extraction_chars, so it is used only when that is lossless.
    // It is NOT a chunking threshold; incremental routing is ExtractionCapacity::decide().
    'large_tokens' => 14000,
    // Published limits (https://platform.claude.com/docs/en/about-claude/models/overview).
    'model_capabilities' => [
        'claude-haiku-4-5-20251001' => ['context_window' => 200000, 'max_output_tokens' => 64000],
        'claude-haiku-4-5' => ['context_window' => 200000, 'max_output_tokens' => 64000],
        'claude-sonnet-4-5-20250929' => ['context_window' => 200000, 'max_output_tokens' => 64000],
        'claude-sonnet-4-6' => ['context_window' => 1000000, 'max_output_tokens' => 128000],
        'claude-sonnet-5-5' => ['context_window' => 1000000, 'max_output_tokens' => 128000],
    ],
    // Unused context kept free for tokenizer/JSON-escaping drift.
    'context_safety_ratio' => 0.10,
    // Planned output is 75% of the request cap; the rest absorbs denser-than-expected slices.
    'output_fill_ratio' => 0.75,
    // Structured-output estimate. sector_report.pdf (62k tokens) needed far more than 4096
    // output tokens per 14k-token slice; one schema record is ~120-180 output tokens.
    'expected_records_per_1k_tokens' => 4,
    'output_tokens_per_record' => 150,
    // Optional operator ceiling on partition input tokens; null = derived from capacity.
    'chunk_max_tokens' => null,
    // Cross-boundary context (about two paragraphs), capped at 15% of a slice.
    'chunk_overlap_tokens' => 400,
    // A child below this size would mean degenerate output, not oversize input.
    'minimum_split_chars' => 2000,
    'max_split_depth' => 4,
    'concurrency' => 2, // Matches existing Horizon extraction workers.
    'attempts' => 3,
    'synthesis_token_budget' => 16000,
    // Original source text offered to synthesis when it fits; otherwise evidence-anchored excerpts.
    'synthesis_source_max_tokens' => 80000,
    'synthesis_excerpt_radius_chars' => 600,
    // Per-request output bounds. Incremental extraction has its own cap (still clamped to the
    // model's max output); ANTHROPIC_MAX_TOKENS keeps governing only the legacy four-job path.
    // Partition sizing derives from this value, so lowering it yields more, smaller partitions.
    'extraction_max_tokens' => (int) env('ANTHROPIC_EXTRACTION_MAX_TOKENS', 16000),
    'extraction_timeout_seconds' => 120,
    'synthesis_max_tokens' => 8192,
    'repair_max_tokens' => 2048,
    'context_max_tokens' => 1000,
    'visual_cap' => 12,
    'visual_min_bytes' => 10000,
    'visual_min_dimension' => 200,
    'budget_base_usd' => 0.50,
    'budget_per_1000_tokens_usd' => 0.025,
    'budget_max_usd' => (float) env('DOCINTEL_MAX_DOCUMENT_COST_USD', 10),
    'pricing' => [ // USD / million tokens; 5 minute cache writes only.
        'claude-haiku-4-5-20251001' => [1, 5, 1.25, 0.10],
        'claude-haiku-4-5' => [1, 5, 1.25, 0.10],
        'claude-sonnet-4-6' => [3, 15, 3.75, 0.30],
        'claude-sonnet-4-5-20250929' => [3, 15, 3.75, 0.30],
        // https://platform.claude.com/docs/en/models/sonnet-5-5/overview
        'claude-sonnet-5-5' => [2, 10, 2.50, 0.20],
    ],
    'structured_models' => ['claude-haiku-4-5-20251001', 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-sonnet-4-5-20250929', 'claude-sonnet-5-5'],
    'effort_models' => ['claude-sonnet-4-6', 'claude-sonnet-5-5'],
];
