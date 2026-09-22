<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentReprocessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentReprocessController extends Controller
{
    public function store(Request $request, Document $document, DocumentReprocessor $reprocessor): JsonResponse
    {
        $this->authorize('reprocess', $document);
        $request->validate(['intelligence_only' => 'sometimes|boolean']);
        $document = $reprocessor->reprocess($document, $request->user(), $request->boolean('intelligence_only'));

        return response()->json([
            'message' => 'Reprocessing started.',
            'data' => new DocumentResource($document),
        ], 202);
    }
}
