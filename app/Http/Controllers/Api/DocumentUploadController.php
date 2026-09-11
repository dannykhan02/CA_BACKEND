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
use App\Services\Documents\SupportedDocumentTypes;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class DocumentUploadController extends Controller
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());

        // Workspace-scoped dedup: an identical file hash in a different workspace
        // is not a duplicate of *your* document, it's a coincidence (common form, template).
        // Track A / FU-4: this remains a fast-path convenience check, not the
        // enforcement mechanism — the DB-level partial unique index
        // (documents_workspace_file_hash_unique) added in
        // add_type_enum_and_hash_uniqueness_to_documents is what actually
        // prevents two concurrent uploads of the same file from both landing.
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

        // Track A / FU-1: sanitize the client-reported extension the same
        // way DocumentStorageService now does, so the belt-and-suspenders
        // type check here is consistent with what actually gets stored.
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension()));

        $supportedTypes = app(SupportedDocumentTypes::class);
        $documentType = $supportedTypes->typeForExtension($extension);

        // Belt-and-suspenders: UploadDocumentRequest's `extensions:`/
        // `mimetypes:` rules should already reject this before store() ever
        // runs — this exists so the controller stays safe even if that
        // request-level rule is ever changed, bypassed, or this endpoint
        // gets called from somewhere that skips FormRequest validation.
        if (! $documentType || ! $supportedTypes->isEnabled($documentType)) {
            return response()->json([
                'success' => false,
                'message' => 'This file type is not currently supported.',
                'errors' => [],
            ], 422);
        }

        $storage = app(DocumentStorageService::class);
        $path = $storage->store($file, $request->user()->current_workspace_id);

        // Track A / FU-3 + FU-4: the physical file now exists in R2 before
        // any DB row references it. If Document::create() throws for any
        // reason — including the FU-4 unique-constraint violation on a
        // genuine race with another concurrent upload — clean up the orphan
        // instead of leaving an unowned object behind. A unique-violation
        // specifically is translated into the same 409 the pre-check above
        // would have returned, so a losing concurrent request gets a clean
        // response instead of a raw 500.
        try {
            $document = Document::create([
                'name' => $file->getClientOriginalName(),
                'type' => $documentType, // resolved via SupportedDocumentTypes, matches documents_type_check
                'size_kb' => (int) ceil($file->getSize() / 1024),
                'status' => 'Processing',
                'classification' => $request->validated('classification'),
                'year' => (int) now()->year,
                'uploaded_by' => $request->user()->id,
                'workspace_id' => $request->user()->current_workspace_id,
                'pages' => 0,
                'has_structured_data' => false,
                'power_bi_status' => 'not-synced',
                'insights' => [],
                'file_path' => $path,
                'file_hash' => $hash,
                'progress' => 0,
            ]);
        } catch (QueryException $e) {
            $storage->delete($path);

            if (($e->errorInfo[0] ?? null) === '23505') {
                // Lost the race against a concurrent identical upload —
                // documents_workspace_file_hash_unique fired. Same response
                // shape as the pre-check branch above.
                $winner = Document::where('file_hash', $hash)
                    ->where('workspace_id', $request->user()->current_workspace_id)
                    ->first();

                return response()->json([
                    'message' => 'This exact file has already been uploaded.',
                    'data' => $winner ? new DocumentResource($winner) : null,
                ], 409);
            }

            Log::error('Document row creation failed after physical upload succeeded — cleaning up orphaned storage object.', [
                'path' => $path,
                'workspace_id' => $request->user()->current_workspace_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Document row creation failed after physical upload succeeded — cleaning up orphaned storage object.', [
                'path' => $path,
                'workspace_id' => $request->user()->current_workspace_id,
                'error' => $e->getMessage(),
            ]);
            $storage->delete($path);
            throw $e;
        }

        app(\App\Services\AuditLogger::class)->log(
            $request->user(),
            'document.uploaded',
            $document,
            ['name' => $document->name, 'type' => $document->type, 'size_kb' => $document->size_kb]
        );

        // Chain jobs in order:
        // 1. Scan for malware/viruses
        // 2. Extract text from the document
        // 3. Generate insights from extracted text
        // 4. Analyze embedded visual content (charts, images) not captured in text extraction
        // 5. Generate embeddings for vector search
        ScanUploadedFileJob::withChain([
            (new ExtractDocumentTextJob($document->id))->onQueue('extraction'),
            (new GenerateInsightsJob($document->id))->onQueue('extraction'),
            (new \App\Jobs\AnalyzeEmbeddedVisualsJob($document->id))->onQueue('extraction'),
            (new \App\Jobs\GenerateEmbeddingsJob($document->id))->onQueue('extraction'),
        ])->onQueue('default')->dispatch($document->id);

        return response()->json([
            'message' => 'Upload accepted, processing started.',
            'data' => new DocumentResource($document->fresh()),
        ], 202);
    }
}
