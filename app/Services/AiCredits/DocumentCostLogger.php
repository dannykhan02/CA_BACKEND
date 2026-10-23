<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One log line per published document: what it was, what it was quoted and charged, and what the provider
 * actually cost. For checking margins against real usage. It reads and logs; it never writes, and it can never
 * affect billing or publication.
 *
 * The cost is the sum of the per-call estimates in document_ai_runs (tokens x configured price), so it is
 * an estimate of the provider invoice, not the invoice. Metadata only: no names, text, prompts or quotes.
 */
class DocumentCostLogger
{
    public function log(Document $document): void
    {
        try {
            $runs = DocumentAiRun::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
                ->whereNotIn('purpose', ['document_comparison', 'document_qa']);
            $quotes = OperationQuote::where('resource_id', $document->id)->whereIn('kind', ['document', 'ocr', 'reanalysis']);
            $documentQuote = (clone $quotes)->where('kind', 'document')->orderByDesc('created_at')->first();
            $settled = DB::table('billing_operations')->where('resource_id', $document->id)->where('status', 'completed')
                ->whereIn('kind', ['document', 'ocr', 'reanalysis'])->whereNotNull('amount_reserved');
            $quotedAi = $documentQuote !== null;
            $quote = app(QuoteService::class);

            Log::info('Document cost summary', [
                'document_id' => $document->id,
                'workspace_id' => $document->workspace_id,
                'file_type' => $document->type,
                'pages' => (int) $document->pages,
                'estimated_tokens' => (int) ($document->ai_pipeline['tokens'] ?? $quote->estimateTokens($document)),
                'token_count_method' => $document->ai_pipeline['count_method'] ?? 'estimate',
                'route' => $document->ai_pipeline['route'] ?? null,
                'ai_credits_enabled' => QuoteService::enabled(),
                'band' => $documentQuote?->band,
                'quoted_credits' => $quotedAi ? (int) $documentQuote->credits : null,
                'settled_credits' => $quotedAi ? (int) (clone $settled)->sum('amount_settled') : null,
                'provider_cost_usd' => round((float) (clone $runs)->sum('estimated_cost_usd'), 6),
                'provider_runs' => (clone $runs)->count(),
                'runs_with_unknown_cost' => (clone $runs)->whereNull('estimated_cost_usd')->count(),
                'input_tokens' => (int) (clone $runs)->sum('input_tokens'),
                'output_tokens' => (int) (clone $runs)->sum('output_tokens'),
                'failed_chunks' => (int) ($document->ai_pipeline['coverage']['failed_chunks'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Document cost summary unavailable', ['document_id' => $document->id, 'reason' => get_class($e)]);
        }
    }
}
