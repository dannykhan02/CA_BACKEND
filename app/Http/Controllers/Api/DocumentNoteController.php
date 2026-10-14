<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentNoteRequest;
use App\Http\Requests\Document\UpdateDocumentNoteRequest;
use App\Http\Resources\DocumentNoteResource;
use App\Models\Document;
use App\Models\DocumentNote;
use App\Services\AuditLogger;
use App\Services\DocumentNoteAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DocumentNoteController extends Controller
{
    public function __construct(private DocumentNoteAccess $access, private AuditLogger $audit) {}

    public function index(Request $request, string $document): JsonResponse
    {
        $doc = $this->access->document($request->user(), $document);
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $notes = DocumentNote::where('workspace_id', $doc->workspace_id)
            ->where('document_id', $doc->id)->where('user_id', $request->user()->id)
            ->with('citations')->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => DocumentNoteResource::collection($notes->items())->resolve($request),
            'meta' => ['currentPage' => $notes->currentPage(), 'lastPage' => $notes->lastPage(),
                'perPage' => $notes->perPage(), 'total' => $notes->total()],
        ]);
    }

    public function store(StoreDocumentNoteRequest $request, string $document): JsonResponse
    {
        $data = $request->validated();
        $note = $this->safeTransaction(function () use ($request, $document, $data): DocumentNote {
            $doc = $this->access->document($request->user(), $document, true);
            $this->validateCitations($doc, $data['citations'] ?? []);
            $note = DocumentNote::create([
                'document_id' => $doc->id, 'workspace_id' => $doc->workspace_id,
                'user_id' => $request->user()->id, 'body' => $data['body'], 'version' => 1,
            ]);
            $this->replaceCitations($note, $data['citations'] ?? []);
            $this->audit->log($request->user(), 'document_note.created', $note,
                ['document_id' => $doc->id, 'version' => 1]);

            return $note;
        });

        return response()->json(['data' => (new DocumentNoteResource($note->load('citations')))->resolve($request)], 201);
    }

    public function update(UpdateDocumentNoteRequest $request, string $document, string $note): JsonResponse
    {
        $data = $request->validated();
        $updated = $this->safeTransaction(function () use ($request, $document, $note, $data): DocumentNote {
            $doc = $this->access->document($request->user(), $document, true);
            $current = $this->access->note($request->user(), $doc, $note, true);
            $this->assertVersion($request, $current, (int) $data['version']);
            $this->validateCitations($doc, $data['citations']);
            $current->body = $data['body'];
            $current->version++;
            $current->save();
            $this->replaceCitations($current, $data['citations']);
            $this->audit->log($request->user(), 'document_note.updated', $current,
                ['document_id' => $doc->id, 'version' => $current->version]);

            return $current;
        });

        return response()->json(['data' => (new DocumentNoteResource($updated->load('citations')))->resolve($request)]);
    }

    public function destroy(Request $request, string $document, string $note): JsonResponse
    {
        $deleted = $this->safeTransaction(function () use ($request, $document, $note): DocumentNote {
            $doc = $this->access->document($request->user(), $document, true);
            $current = $this->access->note($request->user(), $doc, $note, true);
            $match = $request->header('If-Match');
            if (! is_string($match) || ! preg_match('/^"([1-9][0-9]*)"$/D', $match, $matches)) {
                $this->fail(428, 'NOTE_VERSION_REQUIRED', 'A quoted If-Match version is required.');
            }
            $this->assertVersion($request, $current, (int) $matches[1]);
            $current->delete();
            $this->audit->log($request->user(), 'document_note.deleted', $current,
                ['document_id' => $doc->id, 'version' => $current->version]);

            return $current;
        });

        return response()->json(['data' => ['id' => $deleted->id, 'deleted' => true]]);
    }

    private function assertVersion(Request $request, DocumentNote $note, int $version): void
    {
        if ($note->version !== $version) {
            $this->fail(409, 'NOTE_VERSION_CONFLICT', 'Note changed since it was loaded.', [
                'current' => (new DocumentNoteResource($note->load('citations')))->resolve($request),
            ]);
        }
    }

    private function validateCitations(Document $document, array $citations): void
    {
        foreach ($citations as $citation) {
            $revisionId = $citation['intelligence_revision_id'] ?? null;
            $sourceId = $citation['source_id'] ?? null;
            if ($sourceId !== null && $revisionId === null) {
                $this->fail(422, 'INVALID_CITATION', 'A source ID requires a revision ID.');
            }
            if ($revisionId === null) {
                continue;
            }
            $revision = DB::table('document_intelligence_revisions')
                ->where('id', $revisionId)->where('workspace_id', $document->workspace_id)
                ->where('document_id', $document->id)->where('state', 'published')
                ->where('id', $document->published_intelligence_revision_id)->first(['id', 'pipeline_key']);
            if ($revision === null) {
                $this->fail(422, 'CITATION_REVISION_STALE', 'Citation revision is unavailable for this document.');
            }
            if ($sourceId !== null && DB::table('document_evidence')
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('pipeline_key', $revision->pipeline_key)->where('source_id', $sourceId)->count() !== 1) {
                $this->fail(422, 'CITATION_SOURCE_NOT_OWNED', 'Citation source is unavailable for this document.');
            }
        }
    }

    private function replaceCitations(DocumentNote $note, array $citations): void
    {
        $note->citations()->delete();
        foreach ($citations as $citation) {
            $note->citations()->create([
                'document_id' => $note->document_id,
                'intelligence_revision_id' => $citation['intelligence_revision_id'] ?? null,
                'source_id' => $citation['source_id'] ?? null,
                'finding_label' => $citation['finding_label'],
                'quote_snapshot' => $citation['quote_snapshot'],
                'page' => $citation['page'] ?? null,
                'created_at' => now(),
            ]);
        }
        $note->unsetRelation('citations');
    }

    private function fail(int $status, string $code, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(array_merge([
            'success' => false, 'message' => $message, 'errors' => [], 'error' => ['code' => $code],
        ], $extra), $status));
    }

    private function safeTransaction(\Closure $operation): DocumentNote
    {
        try {
            return DB::transaction($operation);
        } catch (HttpResponseException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Laravel's routing pipeline can render and report controller exceptions
            // before Notes middleware sees them. QueryException may contain bindings.
            app(LoggerInterface::class)->error('document note operation failed', [
                'exception_class' => $exception::class,
            ]);
            $this->fail(500, 'NOTE_OPERATION_FAILED', 'Unable to process note.');
        }
    }
}
