<?php

/**
 * Single source of truth for supported document types. Extending support
 * means flipping 'enabled' here — no controller or job code changes,
 * as long as the corresponding extractor branch exists in
 * ExtractDocumentTextJob (see routing table below for what maps where).
 */
return [
    'PDF' => [
        'enabled' => true,
        'mime_types' => ['application/pdf'],
        'extensions' => ['pdf'],
        'route' => 'native_then_ocr', // try native text, fall back to OCR if empty
    ],
    'DOCX' => [
        'enabled' => true,
        'mime_types' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'extensions' => ['docx'],
        'route' => 'native_only',
    ],
    'DOC' => [
        'enabled' => false,
        'mime_types' => ['application/msword'],
        'extensions' => ['doc'],
        'route' => 'native_only',
    ],
    'XLSX' => [
        'enabled' => true, // extractor exists (SpreadsheetTextExtractor) — routing not yet wired
        'mime_types' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'extensions' => ['xlsx'],
        'route' => 'spreadsheet',
    ],
    'XLS' => [
        'enabled' => false,
        'mime_types' => ['application/vnd.ms-excel'],
        'extensions' => ['xls'],
        'route' => 'spreadsheet',
    ],
    'CSV' => [
        'enabled' => false,
        'mime_types' => ['text/csv'],
        'extensions' => ['csv'],
        'route' => 'spreadsheet',
    ],
    'PNG' => [
        'enabled' => false, // OcrEngineResolver/providers exist, routing not wired
        'mime_types' => ['image/png'],
        'extensions' => ['png'],
        'route' => 'ocr_only',
    ],
    'JPG' => [
        'enabled' => false,
        'mime_types' => ['image/jpeg'],
        'extensions' => ['jpg', 'jpeg'],
        'route' => 'ocr_only',
    ],
    'WEBP' => [
        'enabled' => false,
        'mime_types' => ['image/webp'],
        'extensions' => ['webp'],
        'route' => 'ocr_only',
    ],
    'BMP' => [
        'enabled' => false,
        'mime_types' => ['image/bmp'],
        'extensions' => ['bmp'],
        'route' => 'ocr_only',
    ],
    'TIFF' => [
        'enabled' => false,
        'mime_types' => ['image/tiff'],
        'extensions' => ['tiff', 'tif'],
        'route' => 'ocr_only',
    ],
    'HEIC' => [
        'enabled' => false,
        'mime_types' => ['image/heic'],
        'extensions' => ['heic'],
        'route' => 'ocr_only', // needs conversion step first, not just a provider
    ],
];
