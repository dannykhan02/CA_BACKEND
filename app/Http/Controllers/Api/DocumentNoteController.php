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
use App\Services\RichNoteContent;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DocumentNoteController extends Controller
{
    public function __construct(private DocumentNoteAccess $access, private AuditLogger $audit, private RichNoteContent $richContent) {}

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
            $this->assertRichAvailable($data);
            if (array_key_exists('contentSchemaVersion', $data) && ! array_key_exists('content', $data)) {
                $this->fail(422, 'NOTE_INVALID_CONTENT', 'A schema version requires rich content.');
            }
            $rich = array_key_exists('content', $data) ? $this->validateRich($data) : null;
            $this->validateCitations($doc, $data['citations'] ?? []);
            $note = DocumentNote::create([
                'document_id' => $doc->id, 'workspace_id' => $doc->workspace_id,
                'user_id' => $request->user()->id, 'body' => $rich['body'] ?? $data['body'], 'version' => 1,
            ]);
            $references = $this->replaceCitations($note, $data['citations'] ?? []);
            if ($rich !== null) {
                $note->content = $this->resolveReferences($data['content'], $rich['references'], $references);
                $note->content_format = 'tiptap_v1';
                $note->save();
            }
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
            $this->assertRichAvailable($data);
            $rich = isset($data['content']) ? $this->validateRich($data) : null;
            if (array_key_exists('content', $data) && $data['content'] === null && ! isset($data['body'])) {
                $this->fail(422, 'NOTE_INVALID_BODY', 'A body is required to replace rich content with plain text.');
            }
            if ($current->content_format === 'tiptap_v1' && isset($data['body']) && ! array_key_exists('content', $data)) {
                $this->fail(422, 'NOTE_FORMAT_CHANGE_REQUIRED', 'Explicitly clear content to replace rich text with plain text.');
            }
            if (array_key_exists('contentSchemaVersion', $data) && ! isset($data['content'])) {
                $this->fail(422, 'NOTE_INVALID_CONTENT', 'A schema version requires rich content.');
            }
            if (array_key_exists('citations', $data) && array_key_exists('appendCitations', $data)) {
                $this->fail(422, 'INVALID_CITATION', 'Citation replacement and append cannot be combined.');
            }
            if (array_key_exists('citations', $data)) {
                $this->validateCitations($doc, $data['citations']);
                $references = $this->replaceCitations($current, $data['citations']);
            } else {
                $references = $current->citations()->pluck('id', 'id')->all();
                if (array_key_exists('appendCitations', $data)) {
                    $new = $data['appendCitations'];
                    if (count($references) + count($new) > 50) {
                        $this->fail(422, 'TOO_MANY_CITATIONS', 'A note may have at most 50 citations.');
                    }
                    foreach ($new as $citation) {
                        if (! isset($citation['intelligence_revision_id'], $citation['source_id'])) {
                            $this->fail(422, 'INVALID_CITATION', 'Appended citations require a current source and revision.');
                        }
                    }
                    $this->validateCitations($doc, $new);
                    $references = array_merge($references, $this->createCitations($current, $new));
                }
            }
            if ($rich !== null) {
                $current->content = $this->resolveReferences($data['content'], $rich['references'], $references);
                $current->content_format = 'tiptap_v1';
                $current->body = $rich['body'];
            } elseif (array_key_exists('content', $data)) {
                $current->content = null;
                $current->content_format = 'plain_text';
                $current->body = $data['body'];
            } elseif (isset($data['body'])) {
                $current->body = $data['body'];
            } elseif ($current->content_format === 'tiptap_v1') {
                // Citation replacement must not leave references to deleted rows.
                $existing = $this->validateRich(['content' => $current->content, 'contentSchemaVersion' => 1]);
                if (array_key_exists('citations', $data) && $existing['references'] !== []) {
                    $this->fail(422, 'NOTE_CITATION_REFERENCE_INVALID', 'Replacing referenced citations requires an explicit content replacement.');
                }
                $current->content = $this->resolveReferences($current->content, $existing['references'], $references);
            }
            $current->version++;
            $current->save();
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

    /** @return array<string, string> Draft reference to stored citation UUID. */
    private function replaceCitations(DocumentNote $note, array $citations): array
    {
        $note->citations()->delete();

        return $this->createCitations($note, $citations);
    }

    /** @return array<string, string> New draft reference to stored citation UUID. */
    private function createCitations(DocumentNote $note, array $citations): array
    {
        $references = [];
        foreach ($citations as $citation) {
            $created = $note->citations()->create([
                'document_id' => $note->document_id,
                'intelligence_revision_id' => $citation['intelligence_revision_id'] ?? null,
                'source_id' => $citation['source_id'] ?? null,
                'finding_label' => $citation['finding_label'],
                'quote_snapshot' => $citation['quote_snapshot'],
                'page' => $citation['page'] ?? null,
                'created_at' => now(),
            ]);
            if (isset($citation['client_ref'])) {
                $references[$citation['client_ref']] = $created->id;
            }
        }
        $note->unsetRelation('citations');

        return $references;
    }

    private function assertRichAvailable(array $data): void
    {
        if (array_key_exists('content', $data) && ! config('document_intelligence.notes_rich_text_enabled')) {
            $this->fail(404, 'NOTE_RICH_TEXT_DISABLED', 'Rich text notes are unavailable.');
        }
    }

    /** @return array{body:string,references:array<string,bool>} */
    private function validateRich(array $data): array
    {
        if (($data['contentSchemaVersion'] ?? null) !== 1 || ! is_array($data['content'] ?? null)) {
            $this->fail(422, 'NOTE_INVALID_CONTENT', 'Invalid rich note content.');
        }
        try {
            return $this->richContent->validate($data['content']);
        } catch (InvalidArgumentException) {
            $this->fail(422, 'NOTE_INVALID_CONTENT', 'Invalid rich note content.');
        }
    }

    /** Replace only citation IDs. Citation snapshots remain solely in their immutable rows. */
    private function resolveReferences(array $content, array $references, array $available): array
    {
        foreach (array_keys($references) as $id) {
            if (! isset($available[$id])) {
                $this->fail(422, 'NOTE_CITATION_REFERENCE_INVALID', 'Citation reference is unavailable for this note.');
            }
        }
        $rewrite = function (array &$node) use (&$rewrite, $available): void {
            if (($node['type'] ?? null) === 'citationRef') {
                $node['attrs']['citationId'] = $available[$node['attrs']['citationId']];
            }
            if (isset($node['content'])) {
                foreach ($node['content'] as &$child) {
                    $rewrite($child);
                }
                unset($child);
            }
        };
        $rewrite($content);

        return $content;
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
