<?php

return [
    'max_upload_size_kb' => (int) env('DOC_MAX_UPLOAD_KB', 20480), // 20MB
    'archive_limits' => [
        'max_entries' => (int) env('DOC_ARCHIVE_MAX_ENTRIES', 10000),
        'max_expanded_bytes' => (int) env('DOC_ARCHIVE_MAX_EXPANDED_BYTES', 209715200),
        'max_entry_bytes' => (int) env('DOC_ARCHIVE_MAX_ENTRY_BYTES', 104857600),
        'max_ratio' => (int) env('DOC_ARCHIVE_MAX_RATIO', 1000),
    ],
    'pdf_max_pages' => (int) env('DOC_PDF_MAX_PAGES', 1000),
    'pdf_preflight_timeout_seconds' => (int) env('DOC_PDF_PREFLIGHT_TIMEOUT', 15),
    'xlsx_max_sheets' => (int) env('DOC_XLSX_MAX_SHEETS', 100),
    'xlsx_max_rows_per_sheet' => (int) env('DOC_XLSX_MAX_ROWS_PER_SHEET', 100000),
    'xlsx_max_cells' => (int) env('DOC_XLSX_MAX_CELLS', 500000),
    'image_max_pixels' => (int) env('DOC_IMAGE_MAX_PIXELS', 100000000),
    'ocr_batch_max_pages' => (int) env('DOC_OCR_BATCH_MAX_PAGES', 3),

    // Classification gate: which classifications are allowed to have their
    // full text sent to the Anthropic API automatically. Restricted is
    // always blocked regardless of this list — see docs/DECISIONS.md.
    'auto_extract_classifications' => explode(',', env('DOC_AUTO_EXTRACT_CLASSIFICATIONS', 'Public,Internal,Confidential')),

    'max_extraction_chars' => (int) env('DOC_MAX_EXTRACTION_CHARS', 60000), // ~15k tokens, keeps cost bounded
    'insights_max_kpis' => (int) env('DOC_INSIGHTS_MAX_KPIS', 12),
    'insights_max_charts' => (int) env('DOC_INSIGHTS_MAX_CHARTS', 3),
    'insights_max_chart_points' => (int) env('DOC_INSIGHTS_MAX_CHART_POINTS', 8),
    'insights_max_observations' => (int) env('DOC_INSIGHTS_MAX_OBSERVATIONS', 5),
    // Audit finding (MEDIUM) — search/Q&A retrieval had no relevance floor,
    // so irrelevant queries still returned top-N nearest neighbors regardless
    // of actual similarity. 0.3 is a starting point, not a tuned value.
    'min_search_similarity' => (float) env('DOC_MIN_SEARCH_SIMILARITY', 0.3),

    'clamav_enabled' => filter_var(env('CLAMAV_ENABLED', false), FILTER_VALIDATE_BOOL),
    'clamav_driver' => env('CLAMAV_DRIVER', 'cli'),
    'clamav_binary' => env('CLAMAV_BINARY', 'clamscan'),
];
