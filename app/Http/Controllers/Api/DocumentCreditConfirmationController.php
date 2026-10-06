<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\Document;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
use App\Support\QueueTopology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCreditConfirmationController extends Controller
{
    /** The customer accepts the quoted credits for a large analysis. Credits are reserved when the job resumes. */
    public function store(Request $request, Document $document, AiCreditAdmission $admission, CreditAccountant $accountant): JsonResponse
    {
        $this->authorize('reprocess', $document);
        abort_unless(QuoteService::enabled(), 409, 'AI credits are not enabled.');
        abort_unless($document->ai_pipeline['awaiting_credit_confirmation'] ?? false, 409, 'This document is not waiting for a credit confirmation.');
        $credits = $document->ai_pipeline['credit_quote']['credits'] ?? null;
        // The balance can change between the quote and the click: say so now instead of failing the document later.
        if (! ($document->ai_pipeline['credit_quote_confirmed'] ?? false) && $credits !== null) {
            $available = $accountant->available($document->workspace_id);
            abort_if($available < $credits, 402, sprintf('Insufficient credits: %d available, %d required.', $available, $credits));
        }
        // Only the request that records the confirmation resumes the pipeline; a double click is a no-op.
        if ($admission->confirmDocument($document)) {
            ResumeAfterCreditConfirmationJob::dispatch($document->id)->onQueue(QueueTopology::for(ResumeAfterCreditConfirmationJob::class));
        }

        return response()->json(['message' => 'Analysis confirmed.', 'data' => ['credits' => $credits]], 202);
    }
}
