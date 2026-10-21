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
];
