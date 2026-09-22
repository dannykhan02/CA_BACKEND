<?php

return [
    'free_initial_credits' => (int) env('BILLING_FREE_INITIAL_CREDITS', 5),
    'free_storage_bytes' => (int) env('BILLING_FREE_STORAGE_BYTES', 1073741824),
    'currency' => env('BILLING_CURRENCY', 'KES'),
    'pipeline_hours' => (int) env('BILLING_PIPELINE_HOURS', 24),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 0),
    // Unclassified historical purchases retain their original functionality pending live/test verification.
    'preserve_unknown_legacy_access' => true,
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'description' => 'For individuals who occasionally work with important professional documents.',
            'documents' => (int) env('BILLING_STARTER_DOCUMENTS', 20),
            'comparisons' => (int) env('BILLING_STARTER_COMPARISONS', 5),
            'storage_bytes' => (int) env('BILLING_STARTER_STORAGE_BYTES', 1073741824),
            'prices' => ['monthly' => (int) env('BILLING_STARTER_MONTHLY_AMOUNT', 150000), 'annual' => (int) env('BILLING_STARTER_ANNUAL_AMOUNT', 1500000)],
            'plan_codes' => ['monthly' => env('PAYSTACK_STARTER_MONTHLY_PLAN_CODE'), 'annual' => env('PAYSTACK_STARTER_ANNUAL_PLAN_CODE')],
        ],
        'professional' => [
            'name' => 'Professional',
            'description' => 'For professionals who regularly work with contracts, tenders, reports, audits, policies, and other business documents.',
            'documents' => (int) env('BILLING_PROFESSIONAL_DOCUMENTS', 100),
            'comparisons' => (int) env('BILLING_PROFESSIONAL_COMPARISONS', 30),
            'storage_bytes' => (int) env('BILLING_PROFESSIONAL_STORAGE_BYTES', 5368709120),
            'prices' => ['monthly' => (int) env('BILLING_PROFESSIONAL_MONTHLY_AMOUNT', 350000), 'annual' => (int) env('BILLING_PROFESSIONAL_ANNUAL_AMOUNT', 3500000)],
            'plan_codes' => ['monthly' => env('PAYSTACK_PROFESSIONAL_MONTHLY_PLAN_CODE'), 'annual' => env('PAYSTACK_PROFESSIONAL_ANNUAL_PLAN_CODE')],
        ],
    ],
];
