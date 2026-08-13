<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\DocumentContextRetriever;
use App\Services\AnthropicClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentQaController extends Controller
{
    public function ask(Request $request, DocumentContextRetriever $retriever, AnthropicClient $client): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $user = $request->user();

        // Authorization lives inside the retriever (Day 9 Batch 2) — the
        // controller never touches document_embeddings/documents directly,
        // so there is no post-filter step here to accidentally skip.
        $context = $retriever->retrieve($validated['question'], $user, $validated['top_k'] ?? 5);

        if ($context->isEmpty()) {
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
            $result = $client->answerDocumentQuestion(
                $validated['question'],
                $context->toPromptContext(),
                $context->documentIds(),
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to answer this question right now.',
            ], 500);
        }

        app(\App\Services\AuditLogger::class)->log(
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
