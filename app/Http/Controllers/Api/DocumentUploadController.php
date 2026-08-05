<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DocumentUploadController extends Controller
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());

        // Workspace-scoped dedup: an identical file hash in a different workspace
        // is not a duplicate of *your* document, it's a coincidence (common form, template).
        $existing = Document::where('file_hash', $hash)
            ->where('workspace_id', $request->user()->current_workspace_id)
            ->first();

        if ($existing) {
            // Gate check to avoid leaking existence/metadata for documents the user cannot view.
            if (Gate::forUser($request->user())->denies('view', $existing)) {
                return response()->json([
                    'message' => 'This file cannot be uploaded.',
                ], 409);
            }

            return response()->json([
                'message' => 'This exact file has already been uploaded.',
                'data' => new DocumentResource($existing),
            ], 409);
        }

        // Never trust the client-supplied filename for the actual disk path.
        // Routed through DocumentStorageService (not Storage::disk() here
        // directly) so swapping local storage for S3 later is a change in
        // one service class, not every controller that touches files.
        $extension = strtolower($file->getClientOriginalExtension());
        $path = app(DocumentStorageService::class)->store($file, $request->user()->current_workspace_id);

        $document = Document::create([
            'name' => $file->getClientOriginalName(),
            'type' => strtoupper($extension), // matches documents_type_check: PDF|DOCX
            'size_kb' => (int) ceil($file->getSize() / 1024),
            'status' => 'Processing',
            'classification' => $request->validated('classification'),
            'year' => (int) now()->year,
            'uploaded_by' => $request->user()->id,
            'workspace_id' => $request->user()->current_workspace_id, // now set
            'pages' => 0,
            'has_structured_data' => false,
            'power_bi_status' => 'not-synced',
            'insights' => [],
            'file_path' => $path,
            'file_hash' => $hash,
            'progress' => 0,
        ]);

        ScanUploadedFileJob::withChain([
            new ExtractDocumentTextJob($document->id),
            (new GenerateInsightsJob($document->id))->onQueue('extraction'),
            (new \App\Jobs\GenerateEmbeddingsJob($document->id))->onQueue('extraction'),
        ])->onQueue('default')->dispatch($document->id);

        return response()->json([
            'message' => 'Upload accepted, processing started.',
            'data' => new DocumentResource($document->fresh()),
        ], 202);
    }
}