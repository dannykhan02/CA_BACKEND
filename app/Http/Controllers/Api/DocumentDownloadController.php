<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    /**
     * Every extension a type may legitimately already carry, first one first.
     *
     * Audit VAL-2: previously `$document->type === 'PDF' ? 'pdf' : 'docx'`,
     * which mislabels any other enabled type (e.g. XLSX) with a wrong
     * download extension. Not a security issue — a functional/content-type
     * bug. Extend this map whenever a new document type is enabled in
     * config/document_types.php / SupportedDocumentTypes.
     *
     * A *list* per type, not one extension, because a type can have more than
     * one correct spelling and JPG is the reason. A file uploaded as
     * `scan.jpeg` has type JPG; with a single extension the name already ends
     * in a valid one, the `str_ends_with` guard below does not recognise it,
     * and the reader is handed `scan.jpeg.jpg`. Note that merely adding
     * `'JPG' => 'jpg'` to the old map would not have fixed that: the fallback
     * `strtolower($document->type)` already produced exactly `jpg`. The bug
     * was never the missing entry, it was comparing against one spelling.
     *
     * The first entry is what gets appended when the name carries none of
     * them, so it is the canonical spelling for the type.
     */
    private const TYPE_EXTENSIONS = [
        'PDF' => ['pdf'],
        'DOCX' => ['docx'],
        'XLSX' => ['xlsx'],
        'JPG' => ['jpg', 'jpeg'],
        'PNG' => ['png'],
    ];

    /**
     * The content type each type is served as, rather than whatever the disk's
     * mime guesser makes of the stored object (a .docx can come back as
     * application/zip, which a browser will not offer to open as Word).
     */
    private const TYPE_CONTENT_TYPES = [
        'PDF' => 'application/pdf',
        'DOCX' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'JPG' => 'image/jpeg',
        'PNG' => 'image/png',
    ];

    /**
     * Refusal code for a document that never had an original file recorded.
     *
     * A code, not just a status, because 404 is already ambiguous on this
     * route: route-model binding answers 404 for an unknown id, and a recorded
     * key whose object is missing answers 404 too. Both of those render as the
     * generic `Resource not found.` from the NotFoundHttpException handler in
     * bootstrap/app.php, which discards the message passed to `abort()`. The
     * code is the only thing a caller can branch on.
     */
    public const NO_ORIGINAL_STORED = 'NO_ORIGINAL_STORED';

    public function show(Request $request, Document $document, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        // Same classification-aware gate as DocumentController::show() — this
        // file previously had NO authorization at all (see audit F-Critical-1).
        $this->authorize('view', $document);

        // Defense in depth: never stream bytes for a document that hasn't
        // cleared the pipeline. In particular this blocks download of a file
        // that is mid-scan, or that somehow still has status !== 'Failed' on
        // disk after a malware hit raced with a request (ScanUploadedFileJob
        // deletes the file on FOUND, but this guard means we never depend on
        // that deletion alone).
        if (! in_array($document->status, ['Ready', 'Needs Review'], true)) {
            abort(409, 'This document is not currently available for download.');
        }

        // `documents.file_path` is nullable and production carries rows with no
        // key at all (seeded documents nothing was ever uploaded for). Passing
        // that null to `exists()` reached Flysystem's
        // `has(string $location)` and died with an uncaught TypeError — a 500
        // a reader could not tell from a real outage. An empty or
        // whitespace-only key was no better: on a directory-backed driver
        // `exists('')` answers *true*, so the request walked past the missing
        // -object check and blew up in `download()` on a mime-type lookup of
        // the disk root instead.
        //
        // So this is answered from the row, above any disk call, and it is
        // deliberately a different response from the missing-object 404 below.
        // Those are two different events: this one is expected and permanent,
        // while a recorded key that does not resolve is a storage anomaly —
        // the shape of the 2026-10-17 source-viewer incident — and must keep
        // reading as one.
        //
        // Not 409. On this endpoint 409 already means "not downloadable *yet*"
        // (the status gate above), a state that clears itself once processing
        // or approval finishes. A row with no original will never become
        // downloadable, and telling the frontend to retry it would be a lie.
        if (! $document->hasRecordedOriginal()) {
            // Both envelopes in one body on purpose: `message` so the generic
            // error handling already on this route says something true instead
            // of `Resource not found.`, and `error.code` in the shape the
            // source routes use, which is what the frontend branches on.
            return response()->json([
                'success' => false,
                'message' => 'No original file is stored for this document.',
                'errors' => [],
                'error' => ['code' => self::NO_ORIGINAL_STORED],
            ], 404);
        }

        $disk = Storage::disk('documents');

        if (! $disk->exists($document->file_path)) {
            abort(404, 'The underlying file could not be found.');
        }

        // Use the stored display name — never the UUID-on-disk name — for
        // the Content-Disposition header the client/browser sees.
        // An unknown type still gets a sensible single-extension fallback, so a
        // type enabled before this map is updated is mislabelled at worst and
        // never double-suffixed with a spelling it already has.
        $extensions = self::TYPE_EXTENSIONS[$document->type] ?? [strtolower($document->type)];
        $lowerName = strtolower($document->name);
        $alreadyCorrect = false;
        foreach ($extensions as $candidate) {
            if (str_ends_with($lowerName, ".{$candidate}")) {
                $alreadyCorrect = true;
                break;
            }
        }
        $downloadName = $alreadyCorrect
            ? $document->name
            : "{$document->name}.{$extensions[0]}";

        $audit->log($request->user(), 'document.download', $document, [
            'classification' => $document->classification,
        ]);

        $headers = isset(self::TYPE_CONTENT_TYPES[$document->type])
            ? ['Content-Type' => self::TYPE_CONTENT_TYPES[$document->type]]
            : [];

        return $disk->download($document->file_path, $downloadName, $headers);
    }
}
