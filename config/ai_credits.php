<?php

/*
 * Customer-visible AI credits. EVERYTHING here is provisional and NOT validated against
 * production cost quantiles (see docs/tasks/ai-credits-implementation.md). The system is
 * dormant until DOCINTEL_AI_CREDITS_ENABLED=true; with it off, 1 document = 1 unit as before.
 */
return [
    'enabled' => (bool) env('DOCINTEL_AI_CREDITS_ENABLED', false),
    // Stored on every quote so a historical charge never depends on today's config.
    // v2: five bands (tiny..very_large), OCR no longer a separate charge, per-type token estimates.
    'quote_version' => env('DOCINTEL_AI_CREDITS_QUOTE_VERSION', 'v2'),

    // Ordered smallest to largest; the first band whose max_tokens covers the document wins.
    // A dense (tabular/spreadsheet) document moves up `dense_band_shift` bands, and a band whose provider
    // cap cannot pay for the minimum useful completion (see `min_completion_cost_usd`) moves up again.
    'bands' => [
        'tiny' => ['max_tokens' => (int) env('AI_CREDITS_TINY_MAX_TOKENS', 2000), 'credits' => (int) env('AI_CREDITS_TINY', 1)],
        'small' => ['max_tokens' => (int) env('AI_CREDITS_SMALL_MAX_TOKENS', 6000), 'credits' => (int) env('AI_CREDITS_SMALL', 2)],
        'medium' => ['max_tokens' => (int) env('AI_CREDITS_MEDIUM_MAX_TOKENS', 30000), 'credits' => (int) env('AI_CREDITS_MEDIUM', 10)],
        'large' => ['max_tokens' => (int) env('AI_CREDITS_LARGE_MAX_TOKENS', 90000), 'credits' => (int) env('AI_CREDITS_LARGE', 30)],
        'very_large' => ['max_tokens' => (int) env('AI_CREDITS_VERY_LARGE_MAX_TOKENS', 250000), 'credits' => (int) env('AI_CREDITS_VERY_LARGE', 80)],
    ],
    'dense_band_shift' => (int) env('AI_CREDITS_DENSE_BAND_SHIFT', 1),
    // A quote needs the customer's explicit confirmation before credits are reserved when it costs at least this
    // many credits, or when the document has more than this many pages.
    'confirm_from_credits' => (int) env('AI_CREDITS_CONFIRM_FROM_CREDITS', 30),
    'confirm_over_pages' => (int) env('AI_CREDITS_CONFIRM_OVER_PAGES', 100),

    // Characters (bytes, as ChunkPlanner counts them) per model token, by document type, used to estimate a
    // document's tokens at quote time. Measured: PDF ~3.0, XLSX ~1.7 (denser than 3), DOCX ~5.65 (sparser).
    // The provider's own count is used instead whenever it is already known.
    'chars_per_token' => [
        'PDF' => (float) env('AI_CREDITS_CPT_PDF', 3.0),
        'XLSX' => (float) env('AI_CREDITS_CPT_XLSX', 1.7),
        'DOCX' => (float) env('AI_CREDITS_CPT_DOCX', 5.6),
        'default' => (float) env('AI_CREDITS_CPT_DEFAULT', 3.0),
    ],

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

    // Scans are priced by the same bands as any document; OCR is not a separate customer charge. These settings only
    // bound OCR's provider spend and estimate a scan's size before any page has been read.
    'ocr' => [
        // Estimated tokens per page when only the page count is known. A scan never prices below `min_band`.
        'tokens_per_page' => (int) env('AI_CREDITS_OCR_TOKENS_PER_PAGE', 600),
        'min_band' => env('AI_CREDITS_OCR_MIN_BAND', 'small'),
        // Hard stop: more pages than this are declined, never silently OCR'd.
        'max_pages' => (int) env('AI_CREDITS_OCR_MAX_PAGES', 60),
        // Provider cost bound per page. It bounds OCR spend, and the document's band is raised until its cap
        // can also pay for this much OCR, so a scan's price never falls below its real OCR cost.
        // PROVISIONAL: the highest per-call OCR cost observed in production was about USD 0.003, so 0.006 is 2x that.
        // Confirm or move it with `php artisan docintel:cost-vs-size-report` (and the purpose = 'ocr' rows of document_ai_runs).
        'provider_cost_usd_per_page' => (float) env('AI_CREDITS_OCR_USD_PER_PAGE', 0.006),
        // Floor for the OCR operation's provider cap. One vision request reserves a hard ceiling of about USD 0.028 on the
        // default extraction model, so pages x per-page alone would refuse a one-page scan.
        'min_cap_usd' => (float) env('AI_CREDITS_OCR_MIN_CAP_USD', 0.05),
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
