<?php

namespace App\Services\AiCredits;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use Illuminate\Support\Facades\DB;

/**
 * Provider spend attributed to one quote, and the hard internal ceiling it implies. Reuses
 * document_ai_runs (the existing usage record) rather than a second accounting store.
 */
class OperationSpend
{
    /** Known run cost plus a conservative amount for every run whose usage is unknown (timeouts). */
    public function spentUsd(string $quoteId): float
    {
        $runs = DocumentAiRun::where('operation_quote_id', $quoteId);
        $known = (float) (clone $runs)->whereNotNull('estimated_cost_usd')->sum('estimated_cost_usd');
        $unknown = (clone $runs)->whereNull('estimated_cost_usd')->count();

        return round($known + $unknown * (float) config('ai_credits.unknown_usage_cost_usd'), 6);
    }

    /** The quote whose cap governs paid calls for this document/purpose, if AI-credit accounting applies. */
    public function activeQuote(?Document $document, ?string $purpose): ?OperationQuote
    {
        if (! QuoteService::enabled() || ! $document) {
            return null;
        }
        $kind = $purpose === 'ocr' ? ['ocr'] : ['reanalysis', 'document'];
        $quotes = OperationQuote::where('resource_id', $document->id)->whereIn('kind', $kind)->whereIn('status', ['reserved', 'settled'])->get();

        // An open re-analysis governs; otherwise an open analysis; otherwise the settled one (bounds optional retries).
        return $quotes->sortBy(fn ($q) => [$q->status === 'reserved' ? 0 : 1, $q->kind === 'reanalysis' ? 0 : 1, -$q->created_at->getTimestamp()])->first();
    }

    public function remainingUsd(OperationQuote $quote): float
    {
        return round($quote->provider_cost_cap_usd - $this->spentUsd($quote->id), 6);
    }

    /** Refuses the next paid call once the operation's cap is spent. A call in flight can overshoot by at most one response. */
    public function assertWithinCap(?OperationQuote $quote): void
    {
        if ($quote && $this->remainingUsd($quote) <= 0) {
            throw new AiProcessingException('budget_exceeded');
        }
    }

    /**
     * The provider-boundary rule. In AI-credit mode a paid call needs a reserved (or already settled) quote and
     * must fit inside its cap, whichever path made the call. Comparison and Q&A pass their own quote explicitly.
     */
    public function assertCallAllowed(?Document $document, ?string $purpose, ?string $explicitQuoteId = null): void
    {
        if (! QuoteService::enabled()) {
            return;
        }
        if ($explicitQuoteId !== null) {
            $quote = OperationQuote::find($explicitQuoteId);
            if (! $quote || ! in_array($quote->status, ['reserved', 'settled'], true)) {
                throw new AiProcessingException('credits_not_reserved');
            }
            $this->assertWithinCap($quote);

            return;
        }
        if (! $document || in_array($purpose, ['document_comparison', 'document_qa'], true)
            || app(CreditAccountant::class)->mode($document->workspace_id) === 'legacy') {
            return;
        }
        $quote = $this->activeQuote($document, $purpose);
        if ($quote) {
            $this->assertWithinCap($quote);

            return;
        }
        // Paid before the rollout, or reserved under the legacy 1-unit rules: unchanged.
        if (Document::whereKey($document->id)->value('credit_accounted_at')
            || DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->whereNull('amount_reserved')->exists()) {
            return;
        }
        throw new AiProcessingException('credits_not_reserved');
    }
}
