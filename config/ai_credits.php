<?php

/*
 * Customer-visible AI credits. EVERYTHING here is provisional and NOT validated against
 * production cost quantiles (see docs/tasks/ai-credits-implementation.md). The system is
 * dormant until DOCINTEL_AI_CREDITS_ENABLED=true; with it off, 1 document = 1 unit as before.
 */
use App\Support\ConfigEnv;

return [
    'enabled' => (bool) env('DOCINTEL_AI_CREDITS_ENABLED', false),
    // Stored on every quote so a historical charge never depends on today's config.
    // v3: six bands chosen by token edges calibrated on measured cost (docintel:cost-vs-size-report), no linear
    // completion gate, a 1.15 local-estimate margin and a spreadsheet multiplier. v2 was the five-band linear-gate engine.
    'quote_version' => env('DOCINTEL_AI_CREDITS_QUOTE_VERSION', 'v3'),

    // Ordered smallest to largest; the first band whose max_tokens covers the document wins. A band's provider spend cap
    // is its credits x `provider_cost_usd_per_credit`. A dense (tabular/spreadsheet) document moves up `dense_band_shift`
    // bands. Measured cost (USD, 24 Ready documents) against these caps: <=1k tokens 0.016-0.036 (cap 0.054); 2-4k
    // 0.034-0.051 (0.054); ~12k 0.226 and 15-18k 0.195-0.228 (0.27); 27k 0.355 (0.54); 40-54k PDF 0.657 (0.81);
    // 77k 0.575-0.677 (0.81); 39k XLSX 0.824 (0.81, so a spreadsheet's dense shift to the next band is what covers it).
    // Env names carry the BAND_ prefix so a variable set for an earlier band layout cannot silently apply here.
    'bands' => [
        'small' => ['max_tokens' => (int) env('AI_CREDITS_BAND_SMALL_MAX_TOKENS', 4000), 'credits' => (int) env('AI_CREDITS_BAND_SMALL_CREDITS', 2)],
        'medium' => ['max_tokens' => (int) env('AI_CREDITS_BAND_MEDIUM_MAX_TOKENS', 8000), 'credits' => (int) env('AI_CREDITS_BAND_MEDIUM_CREDITS', 6)],
        'regular' => ['max_tokens' => (int) env('AI_CREDITS_BAND_REGULAR_MAX_TOKENS', 18000), 'credits' => (int) env('AI_CREDITS_BAND_REGULAR_CREDITS', 10)],
        'large' => ['max_tokens' => (int) env('AI_CREDITS_BAND_LARGE_MAX_TOKENS', 35000), 'credits' => (int) env('AI_CREDITS_BAND_LARGE_CREDITS', 20)],
        'xlarge' => ['max_tokens' => (int) env('AI_CREDITS_BAND_XLARGE_MAX_TOKENS', 90000), 'credits' => (int) env('AI_CREDITS_BAND_XLARGE_CREDITS', 35)],
        'very_large' => ['max_tokens' => (int) env('AI_CREDITS_BAND_VERY_LARGE_MAX_TOKENS', 250000), 'credits' => (int) env('AI_CREDITS_BAND_VERY_LARGE_CREDITS', 80)],
    ],
    'dense_band_shift' => (int) env('AI_CREDITS_DENSE_BAND_SHIFT', 1),
    // Off: bands follow token edges only. Measured, the rule lifted 7-, 46- and 40-page PDFs one band too high and counted a
    // spreadsheet twice (its x1.6 token multiplier already prices its density). `dense_band_shift` applies only when this is on.
    'dense_shift' => ['enabled' => (bool) env('AI_CREDITS_DENSE_SHIFT_ENABLED', false)],

    // Provider-request admission against the quote's cap. 'realistic' (default) admits a request when what is already spent plus
    // its realistic cost fits the cap, and its worst case (every output token) fits `overrun_factor` x the cap. 'strict' admits
    // only when spent plus the worst case fits the cap. A refusal is `budget_exceeded`: partial / Needs Review, hold released.
    'admission_mode' => env('AI_CREDITS_ADMISSION_MODE', 'realistic'),
    'overrun_factor' => (float) env('AI_CREDITS_OVERRUN_FACTOR', 3.0),
    // Realistic output per purpose, from production p95-p99 of successful runs: estimate = min(cap, base + per_input_token x
    // the request's input tokens), then never above the request's max_tokens. Input tokens are the request's input bound / 3. A
    // purpose that is not listed is admitted at its worst case. Override one value with AI_CREDITS_REALISTIC_<PURPOSE>_BASE,
    // _PER_TOKEN or _CAP (for example AI_CREDITS_REALISTIC_ENTITIES_PER_TOKEN=0.2).
    'stage_realistic_output' => (function (): array {
        $defaults = [
            'document_summary' => [2200, 0.0, 2500], 'insights' => [3500, 0.0, 5200], 'entities' => [1500, 0.14, 16000],
            'deadlines' => [2500, 0.0, 4000], 'risks' => [3000, 0.0, 4096], 'document_type' => [500, 0.0, 600],
            'ocr' => [300, 0.0, 400], 'chart_vision' => [800, 0.0, 1000],
        ];
        $out = [];
        foreach ($defaults as $purpose => [$base, $perToken, $cap]) {
            $key = 'AI_CREDITS_REALISTIC_'.strtoupper($purpose);
            $out[$purpose] = ['base' => (int) env($key.'_BASE', $base), 'per_input_token' => (float) env($key.'_PER_TOKEN', $perToken),
                'cap' => (int) env($key.'_CAP', $cap)];
        }

        return $out;
    })(),
    // What the incremental route's quote and pipeline hold for synthesis before extraction spends anything. 'primary_only'
    // holds the primary synthesis request alone times `headroom`; the fallback and the repair are paid from the remaining cap
    // when they are sent, and OperationSpend refuses one that does not fit (the document ends Needs Review, hold released).
    // 'full' holds primary + degraded fallback + repair, as before. Any other value behaves as 'full'.
    // Headroom 1.25 would lift a 15k-token document from regular to large (primary 0.234 x 1.25 > 0.27), so it ships at 1.0.
    'synthesis_reservation' => [
        'mode' => env('AI_CREDITS_SYNTHESIS_RESERVATION_MODE', 'primary_only'),
        'headroom' => (float) env('AI_CREDITS_SYNTHESIS_RESERVATION_HEADROOM', 1.0),
    ],
    // A quote needs the customer's explicit confirmation before credits are reserved when it costs at least this
    // many credits, or when the document has more than this many pages.
    'confirm_from_credits' => (int) env('AI_CREDITS_CONFIRM_FROM_CREDITS', 30),
    'confirm_over_pages' => (int) env('AI_CREDITS_CONFIRM_OVER_PAGES', 100),

    // Characters (bytes, as ChunkPlanner counts them) per model token, by document type, for the LOCAL estimate.
    // Measured: PDF ~3.0, DOCX ~5.65 (sparser). XLSX has no entry on purpose (default 3.0): its measured density is
    // covered by `spreadsheet_token_multiplier` below, and listing 1.7 as well would count it twice.
    'chars_per_token' => [
        'PDF' => (float) env('AI_CREDITS_CPT_PDF', 3.0),
        'DOCX' => (float) env('AI_CREDITS_CPT_DOCX', 5.6),
        'default' => (float) env('AI_CREDITS_CPT_DEFAULT', 3.0),
    ],
    // The provider's own token count (ai_pipeline.count_method = 'anthropic') is used as is whenever it exists. Only
    // when the quote has to rely on the local estimate is the estimate multiplied by this margin, because it differs
    // from the provider's count by about +/-30% and an under-count under-prices. 1.0 turns the margin off.
    'local_estimate_multiplier' => (float) env('AI_CREDITS_LOCAL_ESTIMATE_MULTIPLIER', 1.15),
    // Spreadsheets cost more per token than prose (one measured sample: 39k XLSX tokens cost USD 0.824, about 1.5x a
    // 40-54k PDF per token). PROVISIONAL, from a single document. Applied to the tokens used for banding, provider
    // count or estimate. 1.0 turns it off.
    'spreadsheet_token_multiplier' => (float) env('AI_CREDITS_SPREADSHEET_TOKEN_MULTIPLIER', 1.6),

    // Internal provider-cost ceiling per quoted credit, in USD (3.50 KES / 129.79 = 0.02697).
    // Deterministic: it never looks at a live FX rate. fx_kes_per_usd is for reporting only.
    'provider_cost_usd_per_credit' => (float) env('AI_CREDITS_PROVIDER_USD_PER_CREDIT', 0.027),
    'fx_kes_per_usd' => (float) env('AI_CREDITS_FX_KES_PER_USD', 129.79),
    'payment_fee_rate' => (float) env('AI_CREDITS_PAYMENT_FEE_RATE', 0.029),
    'margin_target' => (float) env('AI_CREDITS_MARGIN_TARGET', 0.70),

    // What a document is charged when it settles. 'quoted' charges the quote, as before. 'actual' charges
    // clamp(ceil(recorded provider cost x buffer / provider_cost_usd_per_credit), settlement_min, quoted credits) and releases the
    // rest. A document with no complete cost record is charged its quote (basis 'quoted_fallback').
    'settlement_mode' => env('AI_CREDITS_SETTLEMENT_MODE', 'quoted'), // quoted | actual
    'settlement_buffer' => (float) env('AI_CREDITS_SETTLEMENT_BUFFER', 1.25),
    'settlement_min' => (int) env('AI_CREDITS_SETTLEMENT_MIN', 1),

    // With settlement_mode 'actual' only: a document may start when a bucket has at least ceil(quote x this) credits, and that
    // bucket's available credits (never more than the quote) are held. 1.0 = the whole quote must be available, as before. The
    // quote shown to the customer does not change; if the final cost exceeds the hold, the hold is charged and the rest absorbed.
    // Anything outside (0, 1] or not a number is 1.0 (the whole quote), with one warning at boot.
    'admission_min_ratio' => ConfigEnv::number('AI_CREDITS_ADMISSION_MIN_RATIO', env('AI_CREDITS_ADMISSION_MIN_RATIO'), 1.0, 0.0, 1.0, true),

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
    // false preserves today's rule: saved credits are untouched while a subscription is active. Monthly credits are always tried first.
    // Env: AI_CREDITS_SAVED_FALLBACK (default false). Admission, refusal messages and the credits summary all follow it.
    'saved_fallback_during_subscription' => (bool) env('AI_CREDITS_SAVED_FALLBACK', false),

    'grants' => [
        // AI_CREDITS_FREE_TRIAL is the earlier name and still works when AI_CREDITS_FREE_TRIAL_CREDITS is not set.
        'free_trial_credits' => ConfigEnv::integer('AI_CREDITS_FREE_TRIAL_CREDITS', env('AI_CREDITS_FREE_TRIAL_CREDITS', env('AI_CREDITS_FREE_TRIAL')), 20, 0),
        'referral_reward_credits' => (int) env('AI_CREDITS_REFERRAL_REWARD', 100),
    ],
    // Existing saved document units are converted lazily, only when a customer spends, so nothing
    // is lost and nothing is irreversibly rewritten by enabling the flag.
    'legacy_saved_credits_per_document' => (int) env('AI_CREDITS_LEGACY_PER_DOCUMENT', 10),

    // Trials granted after this is set expire after this many days, into a separate trial pocket (workspace_credits.trial_credits_*).
    // Null/unset = trial credits are saved credits that never expire, as before. A trial still unexpired when the first paid
    // subscription starts is carried over into saved credits, once.
    'free_trial_days' => ConfigEnv::integer('AI_CREDITS_FREE_TRIAL_DAYS', env('AI_CREDITS_FREE_TRIAL_DAYS'), null, 1),

    // What each plan may do. A workspace without an active paid subscription period is 'free'; monthly and annual variants of
    // a plan share its tier. Defaults are today's behaviour: every type, OCR on, no size limit. allowed_types is a comma list of
    // extensions (pdf,docx,xlsx,jpg,png; jpg also covers jpeg); empty = all. max_quote_tokens is checked against the quote's tokens.
    'plan_limits' => (function (): array {
        $limits = [];
        foreach (['free', 'starter', 'professional'] as $plan) {
            $key = 'AI_CREDITS_'.strtoupper($plan);
            $ocr = env($key.'_ALLOW_OCR');
            $limits[$plan] = [
                'allowed_types' => (string) env($key.'_ALLOWED_TYPES', ''),
                'allow_ocr' => $ocr === null || $ocr === '' ? true : filter_var($ocr, FILTER_VALIDATE_BOOLEAN),
                'max_quote_tokens' => ConfigEnv::integer($key.'_MAX_QUOTE_TOKENS', env($key.'_MAX_QUOTE_TOKENS'), null, 1),
            ];
        }

        return $limits;
    })(),

    // Monthly AI credits granted by NEW subscription periods. Annual plans can differ from monthly.
    'plans' => [
        'starter' => ['monthly' => (int) env('AI_CREDITS_STARTER_MONTHLY', 100), 'annual' => (int) env('AI_CREDITS_STARTER_ANNUAL', 100)],
        'professional' => ['monthly' => (int) env('AI_CREDITS_PROFESSIONAL_MONTHLY', 250), 'annual' => (int) env('AI_CREDITS_PROFESSIONAL_ANNUAL', 250)],
    ],

    // Variables above that were ignored as unusable (see App\Support\ConfigEnv); AppServiceProvider logs one warning.
    'invalid_env' => ConfigEnv::invalid(),
];
