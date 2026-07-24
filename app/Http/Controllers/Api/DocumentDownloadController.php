<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function show(Request $request, Document $document): StreamedResponse
    {
        // Restricted documents get an extra explicit check here rather than
        // relying solely on route middleware — download is a distinct,
        // higher-risk action than viewing metadata (which DocumentController
        // already gates via auth:sanctum), so it gets its own gate.
        if ($document->classification === 'Restricted'
            && ! in_array($request->user()->role, ['Administrator', 'Reviewer'], true)) {
            abort(403, 'You are not authorized to download Restricted documents.');
        }

        if (! $document->file_path || ! Storage::disk('documents')->exists($document->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk('documents')->download(
            $document->file_path,
            $document->name
        );
    }
}
