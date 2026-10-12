<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\SourceReferenceResolver;
use Illuminate\Http\JsonResponse;

class DocumentSourceReferenceController extends Controller
{
    public function show(Document $document, string $sourceId, SourceReferenceResolver $resolver): JsonResponse
    {
        $this->authorize('view', $document);
        $document = app(DocumentIntelligenceService::class)->publishedView($document);
        $source = $resolver->resolve($document, $sourceId);
        if ($source === null) {
            abort(404);
        }
        if ($source['state'] === 'STALE_REFERENCE') {
            return response()->json(['error' => ['code' => 'STALE_SOURCE_REFERENCE']], 409);
        }
        if ($source['state'] === 'NOT_READY') {
            return response()->json(['error' => ['code' => 'SOURCE_NOT_READY']], 409);
        }
        if ($source['state'] === 'SOURCE_TOO_COMPLEX') {
            return response()->json(['error' => ['code' => 'SOURCE_TOO_COMPLEX']], 422);
        }
        if ($source['state'] === 'SOURCE_UNAVAILABLE') {
            return response()->json(['error' => ['code' => 'SOURCE_UNAVAILABLE']], 409);
        }
        if ($source['state'] === 'SOURCE_AMBIGUOUS') {
            return response()->json(['error' => ['code' => 'SOURCE_AMBIGUOUS']], 409);
        }

        return response()->json(['data' => $source]);
    }
}
