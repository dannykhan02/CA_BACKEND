<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AuditLogger;
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

    public function show(Request $request, Document $document, AuditLogger $audit): StreamedResponse
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

        return $disk->download($document->file_path, $downloadName);
    }
}
