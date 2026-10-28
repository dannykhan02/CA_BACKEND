<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentStorageService;
use App\Services\AiCredits\PlanLimits;
use App\Services\Documents\SupportedDocumentTypes;
use App\Services\Documents\UploadDispatchRecovery;
use App\Services\EntitlementService;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Support\QueueTopology;
use App\Support\SafeExceptionContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class DocumentUploadController extends Controller
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        // Plan limits first: a type the plan does not include is refused before anything is stored, held or dispatched.
        $planType = app(SupportedDocumentTypes::class)->typeForExtension(preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension())));
        if ($planType !== null && ($planBlock = app(PlanLimits::class)->uploadBlock($request->user()->current_workspace_id, $planType)) !== null) {
            return response()->json($planBlock + ['message' => 'Your plan does not include this file type.'], 403);
        }
        app(EntitlementService::class)->assertProcessing($request->user()->current_workspace_id, (int) ceil($file->getSize() / 1024) * 1024);
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
            $document = DB::transaction(function () use ($request, $file, $documentType, $path, $hash) {
                Workspace::whereKey($request->user()->current_workspace_id)->lockForUpdate()->firstOrFail();
                app(EntitlementService::class)->assertProcessing($request->user()->current_workspace_id, (int) ceil($file->getSize() / 1024) * 1024);
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
                app(EntitlementService::class)->reserveDocument($document);
                app(DocumentRevisionPublisher::class)->begin($document);
                ProcessingJob::create(['workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'intelligence_revision_id' => $document->fresh()->active_intelligence_revision_id,
                    'stage' => 'virus_scan', 'status' => 'pending',
                    'input' => ['source' => 'upload_dispatch']]);

                return $document;
            }, 3);
        } catch (QueryException $e) {
            $this->cleanupUnownedUpload($storage, $path);

            if (($e->errorInfo[0] ?? null) === '23505'
                && str_contains($e->getMessage(), 'documents_workspace_file_hash_unique')) {
                Log::error('Document upload conflicted with an existing file.', SafeExceptionContext::for($e, [
                    'user_id' => $request->user()->id,
                    'workspace_id' => $request->user()->current_workspace_id,
                ]));

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
            $this->cleanupUnownedUpload($storage, $path);
            throw $e;
        }

        app(AuditLogger::class)->log(
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
        $revisionId = $document->fresh()->active_intelligence_revision_id;
        try {
            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id, $revisionId))->onQueue(QueueTopology::for(ExtractDocumentTextJob::class)),
                (new GenerateInsightsJob($document->id, revisionId: $revisionId))->onQueue(QueueTopology::for(GenerateInsightsJob::class)),
                (new AnalyzeEmbeddedVisualsJob($document->id, $revisionId))->onQueue(QueueTopology::for(AnalyzeEmbeddedVisualsJob::class)),
                (new GenerateEmbeddingsJob($document->id))->onQueue(QueueTopology::for(GenerateEmbeddingsJob::class)),
            ])->onQueue(QueueTopology::for(ScanUploadedFileJob::class))->dispatch($document->id, $revisionId);
        } catch (\Throwable $dispatchError) {
            Log::error('Initial upload queue dispatch failed.', ['document_id' => $document->id,
                'error_type' => $dispatchError::class]);
            app(UploadDispatchRecovery::class)->failIfUnclaimed($document->id, $revisionId);

            return response()->json(['message' => 'Upload saved, but processing could not start. Please retry processing.',
                'data' => new DocumentResource($document->fresh())], 503);
        }

        return response()->json([
            'message' => 'Upload accepted, processing started.',
            'data' => new DocumentResource($document->fresh()),
        ], 202);
    }

    private function cleanupUnownedUpload(DocumentStorageService $storage, string $path): void
    {
        try {
            // Storage keys are generated per upload. Still check historical
            // rows before deletion so a storage adapter collision cannot
            // remove a valid document's private object.
            if (Document::withTrashed()->where('file_path', $path)->exists()) {
                Log::warning('Upload cleanup retained an object referenced by a document.', ['path' => $path]);

                return;
            }
            if (! $storage->delete($path)) {
                Log::error('Upload cleanup could not remove an unowned object.', ['path' => $path]);
            }
        } catch (\Throwable $cleanupError) {
            // Preserve the original creation failure and leave a trace for
            // private storage reconciliation; cleanup must not mask it.
            Log::error('Upload cleanup failed.', ['path' => $path, 'error_type' => $cleanupError::class]);
        }
    }
}
