<?php

return [
    'trial_documents' => 10,
    'referral_reward_documents' => (int) env('REFERRAL_REWARD_DOCUMENTS', 10),
    'packages' => [
        'documents-100' => [
            'documents' => 100,
            'currency' => 'KES',
            // Fixed KES 2,000, expressed in Paystack's cents (100 per KES).
            'amount_kobo_or_cents' => 200000,
        ],
    ],
];
