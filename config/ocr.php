<?php

return [
    'providers' => [
        'claude_vision' => \App\Services\Ocr\ClaudeVisionOcrProvider::class,
        'tesseract' => \App\Services\Ocr\TesseractOcrProvider::class,
    ],
    'default_provider' => env('OCR_DEFAULT_PROVIDER', 'claude_vision'),
    'tesseract_binary' => env('TESSERACT_BINARY', '/usr/bin/tesseract'),
];
