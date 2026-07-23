<?php

return [
    'max_upload_size_kb' => (int) env('DOC_MAX_UPLOAD_KB', 20480), // 20MB

    'allowed_mimes' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],

    // Classification gate: which classifications are allowed to have their
    // full text sent to the Anthropic API automatically. Restricted is
    // always blocked regardless of this list — see docs/DECISIONS.md.
    'auto_extract_classifications' => explode(',', env('DOC_AUTO_EXTRACT_CLASSIFICATIONS', 'Public,Internal,Confidential')),

    'max_extraction_chars' => (int) env('DOC_MAX_EXTRACTION_CHARS', 60000), // ~15k tokens, keeps cost bounded

    'clamav_enabled' => filter_var(env('CLAMAV_ENABLED', false), FILTER_VALIDATE_BOOL),
    'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
];