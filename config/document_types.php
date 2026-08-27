<?php

/**
 * Single source of truth for supported document types — extensions, MIME
 * types, whether OCR fallback applies, and whether uploads are currently
 * accepted. Nothing else in the codebase should hardcode a type list;
 * everything reads through App\Services\Documents\SupportedDocumentTypes.
 *
 * `enabled` is the application-level kill switch, independent of the DB
 * check constraint (which only says the schema CAN store the value).
 * Flip a type to true only after it has been proven end-to-end with real
 * execution — per the Version 1 testing principle, not before.
 */
return [
    'types' => [
        'PDF' => [
            'label' => 'PDF Document',
            'extensions' => ['pdf'],
            'mime_types' => ['application/pdf'],
            'enabled' => true,
            'ocr_eligible' => true,
        ],
        'DOCX' => [
            'label' => 'Word Document',
            'extensions' => ['docx'],
            'mime_types' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'enabled' => true,
            'ocr_eligible' => false,
        ],
        'XLSX' => [
            'label' => 'Excel Spreadsheet',
            'extensions' => ['xlsx'],
            'mime_types' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'enabled' => true,
            'ocr_eligible' => false,
        ],
        'JPG' => [
            'label' => 'JPEG Image',
            'extensions' => ['jpg', 'jpeg'],
            'mime_types' => ['image/jpeg'],
            'enabled' => true, // TEMPORARY — Day 10 real-document E2E test in progress, see chat log
            'ocr_eligible' => true,
        ],
        'PNG' => [
            'label' => 'PNG Image',
            'extensions' => ['png'],
            'mime_types' => ['image/png'],
            'enabled' => true, // TEMPORARY — Day 10 real-document E2E test in progress, see chat log
            'ocr_eligible' => true,
        ],
        'TIFF' => [
            'label' => 'TIFF Image',
            'extensions' => ['tif', 'tiff'],
            'mime_types' => ['image/tiff'],
            'enabled' => false, // no pipeline support yet — extraction job needs a branch first
            'ocr_eligible' => true,
        ],
        'CSV' => [
            'label' => 'CSV File',
            'extensions' => ['csv'],
            'mime_types' => ['text/csv'],
            'enabled' => false, // no pipeline support yet
            'ocr_eligible' => false,
        ],
        'DOC' => [
            'label' => 'Legacy Word Document',
            'extensions' => ['doc'],
            'mime_types' => ['application/msword'],
            'enabled' => false, // no pipeline support yet — legacy binary format, different parser needed
            'ocr_eligible' => false,
        ],
    ],
];
