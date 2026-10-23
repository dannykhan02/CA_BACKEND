<?php

return [
    'providers' => [
        'claude_vision' => \App\Services\Ocr\ClaudeVisionOcrProvider::class,
        'tesseract' => \App\Services\Ocr\TesseractOcrProvider::class,
    ],
    'default_provider' => env('OCR_DEFAULT_PROVIDER', 'claude_vision'),
    'tesseract_binary' => env('TESSERACT_BINARY', '/usr/bin/tesseract'),

    /*
     * Word-box location for JPG/PNG sources.
     *
     * This is geometry only. Text extraction and everything downstream of it
     * stay on 'default_provider' (claude_vision) exactly as before; tesseract
     * runs locally, inside our own container, purely to say where on the image
     * a word sits. See docs/IMAGE_TEXT_LOCATION.md.
     */
    'image_location' => [
        // Kill switch. Off means the stage is never dispatched and never runs;
        // existing stored boxes stay readable, new documents simply have none.
        'enabled' => filter_var(env('OCR_IMAGE_LOCATION_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Tesseract's per-word confidence, 0-100. Words below this are dropped
        // before storage, so a low-confidence reading can never back a highlight.
        'min_word_confidence' => (int) env('OCR_IMAGE_LOCATION_MIN_CONFIDENCE', 60),

        // Longest side, in pixels. Larger images are downscaled before OCR;
        // boxes are normalised to 0..1, so this costs precision, not correctness.
        'max_dimension' => (int) env('OCR_IMAGE_LOCATION_MAX_DIMENSION', 4000),

        // Hard wall-clock cap on one tesseract run.
        'timeout_seconds' => (int) env('OCR_IMAGE_LOCATION_TIMEOUT', 45),

        // Kenyan documents are commonly English and Kiswahili; both traineddata
        // files are installed in the image.
        'languages' => env('OCR_IMAGE_LOCATION_LANGUAGES', 'eng+swa'),

        // 3 = fully automatic page segmentation without orientation detection.
        // Orientation is already applied by ImageOrienter, so asking tesseract
        // to detect it again would contradict the stored dimensions.
        'page_segmentation_mode' => (int) env('OCR_IMAGE_LOCATION_PSM', 3),

        // Upper bound on stored words per image, and on words read back per
        // source lookup.
        'max_words' => (int) env('OCR_IMAGE_LOCATION_MAX_WORDS', 5000),

        // Shortest quote, in whole words, that may be located at all. A
        // one-word highlight on a photograph is too weak to trust by default:
        // set this to 1 to allow a uniquely matched single word.
        'min_matched_words' => (int) env('OCR_IMAGE_LOCATION_MIN_MATCHED_WORDS', 2),
    ],

    /*
     * Word-box location for scanned PDF pages: the same idea as image_location,
     * applied to pages that went through OCR (no usable text layer). Geometry
     * only; extracted text, analysis and charging are untouched. Words are read
     * with the image_location languages, confidence floor and page segmentation
     * mode. See docs/PDF_TEXT_LOCATION.md.
     */
    'pdf_location' => [
        // Kill switch. Off means the stage is never dispatched and never runs;
        // stored boxes stay readable.
        'enabled' => filter_var(env('OCR_PDF_LOCATION_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Pages located per document (lowest page numbers first). A longer scan
        // keeps its text and analysis; only the later pages have no highlight.
        'max_pages' => (int) env('OCR_PDF_LOCATION_MAX_PAGES', 10),

        // Render resolution for pdftoppm. Boxes are normalised to 0..1, so this
        // trades tesseract accuracy against time, not correctness.
        'dpi' => (int) env('OCR_PDF_LOCATION_DPI', 200),

        // Wall-clock cap for rendering one page and, separately, for reading it.
        'page_timeout_seconds' => (int) env('OCR_PDF_LOCATION_PAGE_TIMEOUT', 45),

        // Whole-job budget. Must stay below LocatePdfTextJob::$timeout (the
        // default pool kills a job at 180s); pages that do not fit are skipped.
        'total_budget_seconds' => (int) env('OCR_PDF_LOCATION_TOTAL_BUDGET', 120),
    ],
];
