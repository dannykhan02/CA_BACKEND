<?php

return [
    'referral_reward_documents' => (int) env('REFERRAL_REWARD_DOCUMENTS', 10),
    // Historical one-time package metadata only. Current checkout uses billing.plans;
    // completed legacy purchases retain their stored quantity and amount snapshot.
    'packages' => [
        'documents-100' => [
            'documents' => 100,
            'currency' => 'KES',
            // Fixed KES 2,000, expressed in Paystack's cents (100 per KES).
            'amount_kobo_or_cents' => 200000,
        ],
    ],
];
