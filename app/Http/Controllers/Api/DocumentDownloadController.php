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
        $extension = strtolower($document->type === 'PDF' ? 'pdf' : 'docx');
        $downloadName = str_ends_with(strtolower($document->name), ".{$extension}")
            ? $document->name
            : "{$document->name}.{$extension}";

        $audit->log($request->user(), 'document.download', $document, [
            'classification' => $document->classification,
        ]);

        return $disk->download($document->file_path, $downloadName);
    }
}
