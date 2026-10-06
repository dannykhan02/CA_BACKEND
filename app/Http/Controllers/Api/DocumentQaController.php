<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProviderBusyException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AI\DocumentContextRetriever;
use App\Services\AiCredits\QaGuard;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\SafeExceptionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentQaController extends Controller
{
    public function ask(Request $request, DocumentContextRetriever $retriever, AnthropicClient $client): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $user = $request->user();
        app(EntitlementService::class)->assertAiAccess($user->current_workspace_id);
        $quote = app(QaGuard::class)->admit($user);

        // Authorization lives inside the retriever (Day 9 Batch 2) — the
        // controller never touches document_embeddings/documents directly,
        // so there is no post-filter step here to accidentally skip.
        $context = $retriever->retrieve($validated['question'], $user, $validated['top_k'] ?? 5);

        if ($context->isEmpty()) {
            app(QaGuard::class)->finish($quote, false, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'No answer.',
                'data' => [
                    'answer' => 'Not enough information in the provided documents to answer this question.',
                    'confidence' => 'none',
                    'cited_document_ids' => [],
                ],
            ]);
        }

        try {
            // The first cited document carries the run record, so every Q&A call is attributable
            // to a workspace, user and (when metered) quote.
            // Recorded whether or not credits are on, so Q&A spend is never invisible to the usage report.
            $result = (QuoteService::enabled() ? $client->forOperation($quote?->id, $user->id) : $client)->answerDocumentQuestion(
                $validated['question'],
                $context->toPromptContext(),
                $context->documentIds(),
                Str::isUuid($first = $context->documentIds()[0] ?? null) ? Document::find($first) : null,
            );
            app(QaGuard::class)->finish($quote, true, $user->id);
        } catch (ProviderBusyException $e) {
            app(QaGuard::class)->finish($quote, false, $user->id);

            // Shared AI capacity is full: a short, retryable answer instead of a failure.
            return response()->json([
                'success' => false,
                'message' => 'AI is busy right now. Please try again in a moment.',
            ], 503)->header('Retry-After', (string) $e->retryAfterSeconds);
        } catch (\Throwable $e) {
            app(QaGuard::class)->finish($quote, false, $user->id);
            Log::error('Document Q&A failed.', SafeExceptionContext::for($e, [
                'user_id' => $user->id,
                'workspace_id' => $user->current_workspace_id,
                'document_ids' => $context->documentIds(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Unable to answer this question right now.',
            ], 500);
        }

        app(AuditLogger::class)->log(
            $user,
            'document.qa_asked',
            null,
            ['question' => $validated['question'], 'cited_document_ids' => $result['cited_document_ids']],
            $user->current_workspace_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Answer generated.',
            'data' => [
                'answer' => $result['answer'],
                'confidence' => $result['confidence'],
                'cited_document_ids' => $result['cited_document_ids'],
            ],
        ]);
    }
}
