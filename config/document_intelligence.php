<?php

return [
    // Explicit rollout switch; legacy ENV names retain their meaning.
    'incremental' => (bool) env('DOCINTEL_INCREMENTAL_PROCESSING', true),
    'pipeline_version' => '1',
    // 2: extraction requests carry a per-request record limit (max_records).
    'prompt_version' => '2',
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
    // Evidence-dense input (spreadsheets, numeric tables) yields more records per input token.
    // sector_report.pdf hit max_tokens on 15k-token roots and on 2k-token depth-4 children, so
    // dense text is planned with this rate instead of the prose rate above.
    'dense_records_per_1k_tokens' => 6,
    // Share of whitespace-separated tokens that are numeric, and share of non-empty lines that
    // look like table rows (tabs, pipes or 3+ numeric cells), above which text counts as dense.
    'dense_numeric_ratio' => 0.18,
    'dense_tabular_line_ratio' => 0.30,
    // Optional operator ceiling on partition input tokens; null = derived from capacity.
    'chunk_max_tokens' => null,
    // Cross-boundary context (about two paragraphs), capped at 15% of a slice.
    'chunk_overlap_tokens' => 400,
    // A child below this size would mean degenerate output, not oversize input.
    'minimum_split_chars' => 2000,
    // Splitting is recovery, not planning. Production depth-3/4 children (~2-4k tokens) still hit
    // max_tokens, so deeper recursion only spent budget. Requests now carry a record limit.
    'max_split_depth' => 2,
    // Whole-tree bound: at most this many split parents per root partition. sector_report.pdf
    // reached 32 split parents on about 5 roots (6 per root). Depth 2 alone allows 3 per root;
    // 2 per root still lets every root halve once. Further capacity failures end as split_limit
    // (existing partial-coverage semantics) instead of re-sending the text again.
    'max_split_parents_per_root' => 2,
    // A truncated extraction keeps its validated complete records and re-requests only the
    // remaining output for the same slice, listing what was already returned. At most this many
    // continuations per slice; after that the remainder is disclosed as saturated coverage.
    'max_truncation_continuations' => 2,
    'continuation_quote_chars' => 160,
    // Outstanding extraction jobs per document. Effective parallelism is also capped globally by
    // the Horizon extraction supervisor's maxProcesses (HORIZON_EXTRACTION_MAX_PROCESSES).
    'concurrency' => max(1, (int) env('DOCINTEL_EXTRACTION_CONCURRENCY', 2)),
    // Global cap on simultaneous Anthropic HTTP calls across every worker, replica and web request
    // (atomic Redis leases). Distinct from `concurrency` above, which bounds one document's
    // outstanding chunk dispatches. Raise in measured steps (2 -> 4 -> 6 -> 8), never by
    // adding Horizon processes or replicas alone.
    // Minimum age before recovery even checks a queued unit; the decision itself is whether
    // its dispatch token is still in Redis (lost dispatch vs. backed-up queue).
    'recovery_queued_minutes' => 10,
    'provider_gate' => [
        'max_inflight' => max(1, (int) env('ANTHROPIC_MAX_INFLIGHT', 2)),
        'driver' => 'redis',
        // Same Redis the queue uses, so web and every worker replica share one semaphore.
        'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'prefix' => 'docintel:anthropic-gate',
        // Must exceed the longest permit hold: whole-job holds are bounded by job timeouts
        // (chunk 180s, summary 200s, comparison 120s, visual 90s); a crashed holder's permit
        // expires after this instead of leaking.
        'lease_seconds' => 240,
        // Calls not pre-admitted by their job (legacy batch jobs, OCR pages, merge context)
        // wait at most this long in a worker; web Q&A waits less and then answers "busy".
        'job_wait_seconds' => 10,
        'web_wait_seconds' => 4,
        // Deferred jobs re-enqueue after this delay plus jitter (no retry attempt consumed).
        'busy_retry_seconds' => 15,
        'busy_retry_jitter_seconds' => 15,
        // A document denied a permit stays "waiting" this long, so the last free permit is
        // kept for it rather than given to a document that already holds one.
        'waiter_seconds' => 60,
    ],
    'attempts' => 3,
    'synthesis_token_budget' => 16000,
    // Original source text offered to synthesis when it fits; otherwise evidence-anchored excerpts.
    'synthesis_source_max_tokens' => 80000,
    'synthesis_excerpt_radius_chars' => 600,
    // Synthesis fallback ladder. Evidence is identical at every level; only source context shrinks:
    // 0 full (or excerpts if the document does not fit), 1 half the level-0 source budget,
    // 2 short excerpts around cited evidence only, 3 evidence only.
    'synthesis_levels' => [
        ['source' => 'full', 'source_fraction' => 1.0, 'radius_fraction' => 1.0, 'timeout' => 110],
        ['source' => 'reduced', 'source_fraction' => 0.5, 'radius_fraction' => 1.0, 'timeout' => 90],
        ['source' => 'excerpts', 'source_fraction' => 0.125, 'radius_fraction' => 0.5, 'timeout' => 75],
        ['source' => 'none', 'source_fraction' => 0.0, 'radius_fraction' => 0.0, 'timeout' => 60],
    ],
    // Only these synthesis failures descend the ladder. Auth/billing/model/schema failures never do.
    'synthesis_degradable_failures' => ['timeout', 'max_tokens', 'truncated', 'context_overflow'],
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
    // The only models DocIntel is meant to call: FAST (extraction) and SMART (synthesis).
    // Requests are not blocked; any other model is logged and fails docintel:verify-models.
    'approved_models' => ['claude-haiku-4-5-20251001', 'claude-sonnet-5-5'],
    'structured_models' => ['claude-haiku-4-5-20251001', 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-sonnet-4-5-20250929', 'claude-sonnet-5-5'],
    'effort_models' => ['claude-sonnet-4-6', 'claude-sonnet-5-5'],
];
