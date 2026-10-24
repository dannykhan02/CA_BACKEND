<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AiCredits\AiCreditAdmission;
use Illuminate\Http\JsonResponse;

class DocumentCreditDeclineController extends Controller
{
    /** The customer declines the quoted credits: holds are released, nothing is charged, the document ends. */
    public function store(Document $document, AiCreditAdmission $admission): JsonResponse
    {
        $this->authorize('reprocess', $document);
        abort_unless($admission->declineDocument($document), 409, 'This document is not waiting for a credit confirmation.');

        return response()->json(['message' => 'Analysis declined. No credits were used.', 'data' => ['documentId' => $document->id]]);
    }
}
