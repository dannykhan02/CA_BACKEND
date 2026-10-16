<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\SourceDocxWindowReader;
use App\Services\Intelligence\SourceReferenceResolver;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** A revision-pinned, authorized read of one Word source and its neighbors. */
class DocumentSourceDocxWindowController extends Controller
{
    public function show(Request $request, Document $document, string $sourceId,
        SourceReferenceResolver $resolver, SourceDocxWindowReader $reader): JsonResponse
    {
        $this->authorize('view', $document);
        $expected = $request->query('expected_revision_id');
        if (! is_string($expected) || ! Str::isUuid($expected)) {
            return $this->refuse('INVALID_SOURCE_REVISION', 422);
        }

        return DB::transaction(function () use ($document, $sourceId, $expected, $resolver, $reader): JsonResponse {
            $current = Document::whereKey($document->id)->sharedLock()->firstOrFail();
            $this->authorize('view', $current);
            if ($current->published_intelligence_revision_id === null) {
                return $this->refuse('SOURCE_NOT_READY', 409);
            }
            if ($current->published_intelligence_revision_id !== $expected) {
                return $this->refuse('STALE_SOURCE_REFERENCE', 409);
            }
            if ($current->type !== 'DOCX') {
                return $this->refuse('SOURCE_UNAVAILABLE', 409);
            }
            try {
                $view = app(DocumentIntelligenceService::class)->publishedView($current);
            } catch (LogicException) {
                return $this->refuse('SOURCE_UNAVAILABLE', 409);
            }
            $revision = DocumentIntelligenceRevision::whereKey($expected)
                ->where('document_id', $current->id)->where('workspace_id', $current->workspace_id)
                ->where('state', 'published')->first();
            if ($revision === null) {
                return $this->refuse('SOURCE_UNAVAILABLE', 409);
            }
            $source = $resolver->resolve($view, $sourceId);
            if ($source === null) {
                abort(404);
            }
            $refusal = match ($source['state']) {
                'STALE_REFERENCE' => ['STALE_SOURCE_REFERENCE', 409],
                'NOT_READY' => ['SOURCE_NOT_READY', 409],
                'SOURCE_TOO_COMPLEX' => ['SOURCE_TOO_COMPLEX', 422],
                'SOURCE_AMBIGUOUS' => ['SOURCE_AMBIGUOUS', 409],
                'SOURCE_UNAVAILABLE' => ['SOURCE_UNAVAILABLE', 409],
                default => null,
            };
            if ($refusal !== null) {
                return $this->refuse(...$refusal);
            }
            if ($source['intelligenceRevisionId'] !== $expected) {
                return $this->refuse('STALE_SOURCE_REFERENCE', 409);
            }
            try {
                return response()->json(['data' => $reader->read($current, $view, $revision, $source)]);
            } catch (DomainException $failure) {
                $code = $failure->getMessage();
                if (! in_array($code, ['SOURCE_UNAVAILABLE', 'SOURCE_AMBIGUOUS', 'SOURCE_TOO_COMPLEX'], true)) {
                    $code = 'SOURCE_UNAVAILABLE';
                }

                return $this->refuse($code, $code === 'SOURCE_TOO_COMPLEX' ? 422 : 409);
            }
        });
    }

    private function refuse(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
