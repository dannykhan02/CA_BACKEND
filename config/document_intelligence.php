<?php

return [
    // Explicit rollout switch; legacy ENV names retain their meaning.
    'incremental' => (bool) env('DOCINTEL_INCREMENTAL_PROCESSING', true),
    'pipeline_version' => '1',
    'prompt_version' => '1',
    'large_tokens' => 14000,
    'chunk_target_tokens' => 14000,
    'chunk_max_tokens' => 18000,
    'chunk_overlap_tokens' => 400,
    'minimum_split_chars' => 1000,
    'max_split_depth' => 6,
    'concurrency' => 2, // Matches existing Horizon extraction workers.
    'attempts' => 3,
    'synthesis_token_budget' => 16000,
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
    ],
    'structured_models' => ['claude-haiku-4-5-20251001', 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-sonnet-4-5-20250929'],
    'effort_models' => ['claude-sonnet-4-6'],
];
