<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\Document;
use App\Services\AiCredits\AiCreditAdmission;
use App\Support\QueueTopology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCreditConfirmationController extends Controller
{
    /** The customer accepts the quoted credits for a large analysis. Credits are reserved when the job resumes. */
    public function store(Request $request, Document $document, AiCreditAdmission $admission): JsonResponse
    {
        $this->authorize('reprocess', $document);
        abort_unless($document->ai_pipeline['awaiting_credit_confirmation'] ?? false, 409, 'This document is not waiting for a credit confirmation.');
        $admission->confirmDocument($document);
        ResumeAfterCreditConfirmationJob::dispatch($document->id)->onQueue(QueueTopology::for(ResumeAfterCreditConfirmationJob::class));

        return response()->json(['message' => 'Analysis confirmed.', 'data' => ['credits' => $document->ai_pipeline['credit_quote']['credits'] ?? null]], 202);
    }
}
