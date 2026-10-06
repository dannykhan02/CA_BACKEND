<?php

/*
 * Customer-visible AI credits. EVERYTHING here is provisional and NOT validated against
 * production cost quantiles (see docs/tasks/ai-credits-implementation.md). The system is
 * dormant until DOCINTEL_AI_CREDITS_ENABLED=true; with it off, 1 document = 1 unit as before.
 */
return [
    'enabled' => (bool) env('DOCINTEL_AI_CREDITS_ENABLED', false),
    // Stored on every quote so a historical charge never depends on today's config.
    'quote_version' => env('DOCINTEL_AI_CREDITS_QUOTE_VERSION', 'v1'),

    // Ordered smallest to largest; the first band whose max_tokens covers the document wins.
    // A dense (tabular/spreadsheet) document moves up `dense_band_shift` bands.
    'bands' => [
        'simple' => ['max_tokens' => (int) env('AI_CREDITS_SIMPLE_MAX_TOKENS', 6000), 'credits' => (int) env('AI_CREDITS_SIMPLE', 4)],
        'standard' => ['max_tokens' => (int) env('AI_CREDITS_STANDARD_MAX_TOKENS', 30000), 'credits' => (int) env('AI_CREDITS_STANDARD', 10)],
        'large' => ['max_tokens' => (int) env('AI_CREDITS_LARGE_MAX_TOKENS', 90000), 'credits' => (int) env('AI_CREDITS_LARGE', 30)],
        'very_large' => ['max_tokens' => (int) env('AI_CREDITS_VERY_LARGE_MAX_TOKENS', 250000), 'credits' => (int) env('AI_CREDITS_VERY_LARGE', 80)],
    ],
    'dense_band_shift' => (int) env('AI_CREDITS_DENSE_BAND_SHIFT', 1),
    // Bands at or above this need the customer's explicit confirmation before credits are reserved.
    'confirm_from_band' => env('AI_CREDITS_CONFIRM_FROM_BAND', 'large'),

    // Internal provider-cost ceiling per quoted credit, in USD (3.50 KES / 129.79 = 0.02697).
    // Deterministic: it never looks at a live FX rate. fx_kes_per_usd is for reporting only.
    'provider_cost_usd_per_credit' => (float) env('AI_CREDITS_PROVIDER_USD_PER_CREDIT', 0.027),
    'fx_kes_per_usd' => (float) env('AI_CREDITS_FX_KES_PER_USD', 129.79),
    'payment_fee_rate' => (float) env('AI_CREDITS_PAYMENT_FEE_RATE', 0.029),
    'margin_target' => (float) env('AI_CREDITS_MARGIN_TARGET', 0.70),

    // Conservative minimum provider spend a document needs to finish (extraction + synthesis).
    // If a band's cap is below this estimate the document moves up a band, or is declined.
    'min_completion_cost_usd' => [
        'base' => (float) env('AI_CREDITS_MIN_COST_BASE_USD', 0.05),
        'per_1000_tokens' => (float) env('AI_CREDITS_MIN_COST_PER_1K_USD', 0.006),
    ],

    // Cost assumed for a paid call whose usage is unknown (e.g. a timeout). Never zero.
    'unknown_usage_cost_usd' => (float) env('AI_CREDITS_UNKNOWN_USAGE_USD', 0.05),

    'ocr' => [
        'surcharge_credits' => (int) env('AI_CREDITS_OCR_SURCHARGE', 20),
        // Extra credits for each page beyond the first `included_pages` (0 = flat surcharge only).
        'included_pages' => (int) env('AI_CREDITS_OCR_INCLUDED_PAGES', 20),
        'extra_credits_per_page' => (int) env('AI_CREDITS_OCR_EXTRA_PER_PAGE', 1),
        // Hard stop: more pages than this are declined, never silently OCR'd.
        'max_pages' => (int) env('AI_CREDITS_OCR_MAX_PAGES', 60),
        'provider_cost_usd_per_page' => (float) env('AI_CREDITS_OCR_USD_PER_PAGE', 0.02),
    ],

    'comparison' => ['credits' => (int) env('AI_CREDITS_COMPARISON', 12)],

    // Explicit full re-analysis: 'band' charges the document's current band, or a fixed amount.
    'reanalysis' => ['pricing' => env('AI_CREDITS_REANALYSIS_PRICING', 'band'), 'fixed_credits' => (int) env('AI_CREDITS_REANALYSIS_FIXED', 10)],

    // Q&A has no production cost data yet: the customer debit is off, the abuse caps are on (when enabled).
    'qa' => [
        'credits' => (int) env('AI_CREDITS_QA', 0),
        'max_per_workspace_per_day' => (int) env('AI_CREDITS_QA_MAX_PER_DAY', 100),
        'max_per_user_per_hour' => (int) env('AI_CREDITS_QA_MAX_PER_HOUR', 30),
        'max_cost_usd_per_workspace_per_day' => (float) env('AI_CREDITS_QA_MAX_USD_PER_DAY', 2.0),
    ],

    // Failed work always releases. Needs Review releases unless product explicitly decides otherwise.
    'needs_review_policy' => env('AI_CREDITS_NEEDS_REVIEW_POLICY', 'release'), // release | settle

    // Whether saved credits may pay once a subscription's monthly credits are insufficient.
    // false preserves today's rule: saved credits are untouched while a subscription is active.
    'saved_fallback_during_subscription' => (bool) env('AI_CREDITS_SAVED_FALLBACK', false),

    'grants' => [
        'free_trial_credits' => (int) env('AI_CREDITS_FREE_TRIAL', 20),
        'referral_reward_credits' => (int) env('AI_CREDITS_REFERRAL_REWARD', 100),
    ],
    // Existing saved document units are converted lazily, only when a customer spends, so nothing
    // is lost and nothing is irreversibly rewritten by enabling the flag.
    'legacy_saved_credits_per_document' => (int) env('AI_CREDITS_LEGACY_PER_DOCUMENT', 10),

    // Monthly AI credits granted by NEW subscription periods. Annual plans can differ from monthly.
    'plans' => [
        'starter' => ['monthly' => (int) env('AI_CREDITS_STARTER_MONTHLY', 100), 'annual' => (int) env('AI_CREDITS_STARTER_ANNUAL', 100)],
        'professional' => ['monthly' => (int) env('AI_CREDITS_PROFESSIONAL_MONTHLY', 250), 'annual' => (int) env('AI_CREDITS_PROFESSIONAL_ANNUAL', 250)],
    ],
];
