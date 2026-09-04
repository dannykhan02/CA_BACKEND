<?php

return [
    'max_upload_size_kb' => (int) env('DOC_MAX_UPLOAD_KB', 20480), // 20MB

    // Classification gate: which classifications are allowed to have their
    // full text sent to the Anthropic API automatically. Restricted is
    // always blocked regardless of this list — see docs/DECISIONS.md.
    'auto_extract_classifications' => explode(',', env('DOC_AUTO_EXTRACT_CLASSIFICATIONS', 'Public,Internal,Confidential')),

    'max_extraction_chars' => (int) env('DOC_MAX_EXTRACTION_CHARS', 60000), // ~15k tokens, keeps cost bounded
    // Audit finding (MEDIUM) — search/Q&A retrieval had no relevance floor,
    // so irrelevant queries still returned top-N nearest neighbors regardless
    // of actual similarity. 0.3 is a starting point, not a tuned value.
    'min_search_similarity' => (float) env('DOC_MIN_SEARCH_SIMILARITY', 0.3),

    'clamav_enabled' => filter_var(env('CLAMAV_ENABLED', false), FILTER_VALIDATE_BOOL),
    'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => env('CLAMAV_PORT', 3310),
];