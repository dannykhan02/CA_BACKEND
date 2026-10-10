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
        $runs = DocumentAiRun::where('operation_quote_id', $quoteId)->whereNull('provider_attempt_id');
        $known = (float) (clone $runs)->whereNotNull('estimated_cost_usd')->sum('estimated_cost_usd');
        $unknown = (clone $runs)->whereNull('estimated_cost_usd')->count();
        $attempts = (int) DB::table('provider_call_attempts')->where('operation_quote_id', $quoteId)
            ->whereIn('status', ['reserved', 'dispatched', 'settled', 'uncertain'])
            ->sum(DB::raw('COALESCE(settled_microusd, reserved_microusd)'));

        return round($known + $unknown * (float) config('ai_credits.unknown_usage_cost_usd') + $attempts / 1000000, 6);
    }

    /** Quote row serializes competing workers. Amounts are integer micro-USD to avoid float comparisons in admission. */
    public function reserveAttempt(string $quoteId, string $attemptId, string $purpose, float $estimateUsd): void
    {
        $micros = (int) ceil($estimateUsd * 1000000);
        if ($micros < 1) {
            throw new AiProcessingException('invalid_provider_config');
        }
        DB::transaction(function () use ($quoteId, $attemptId, $purpose, $micros) {
            $quote = OperationQuote::whereKey($quoteId)->lockForUpdate()->first();
            if (! $quote || ! in_array($quote->status, ['reserved', 'settled'], true)) {
                throw new AiProcessingException('credits_not_reserved');
            }
            if (DB::table('provider_call_attempts')->where('id', $attemptId)->exists()) {
                throw new AiProcessingException('duplicate_provider_attempt');
            }
            $cap = (int) floor($quote->provider_cost_cap_usd * 1000000);
            $spent = (int) round($this->spentUsd($quoteId) * 1000000);
            if ($spent + $micros > $cap) {
                throw new AiProcessingException('budget_exceeded');
            }
            DB::table('provider_call_attempts')->insert(['id' => $attemptId, 'operation_quote_id' => $quoteId,
                'purpose' => $purpose, 'status' => 'reserved', 'reserved_microusd' => $micros,
                'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    /** A reservation may authorize one dispatch only. A crash after this point is an uncertain outcome. */
    public function markDispatched(string $attemptId): void
    {
        DB::transaction(function () use ($attemptId) {
            $probe = DB::table('provider_call_attempts')->where('id', $attemptId)->first();
            if (! $probe) {
                throw new AiProcessingException('duplicate_provider_attempt');
            }
            $quote = OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->first();
            if (! $quote || ! in_array($quote->status, ['reserved', 'settled'], true)) {
                throw new AiProcessingException('credits_not_reserved');
            }
            if (DB::table('provider_call_attempts')->where('id', $attemptId)->where('status', 'reserved')
                ->update(['status' => 'dispatched', 'updated_at' => now()]) !== 1) {
                throw new AiProcessingException('duplicate_provider_attempt');
            }
        }, 3);
    }

    /** Idempotent finalization. A dispatched attempt without a reliable usage count retains its full hold. */
    public function finishAttempt(string $attemptId, ?float $actualUsd): void
    {
        if ($actualUsd !== null && (! is_finite($actualUsd) || $actualUsd < 0)) {
            $actualUsd = null;
        }
        DB::transaction(function () use ($attemptId, $actualUsd) {
            $probe = DB::table('provider_call_attempts')->where('id', $attemptId)->first();
            if (! $probe) {
                return;
            }
            OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('provider_call_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt->status !== 'dispatched') {
                return;
            }
            $actual = $actualUsd === null ? null : max(0, (int) ceil($actualUsd * 1000000));
            DB::table('provider_call_attempts')->where('id', $attemptId)->update([
                'status' => $actual === null ? 'uncertain' : 'settled',
                'settled_microusd' => $actual, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Only an attempt that never reached the HTTP dispatch point can be released as free. */
    public function releaseAttempt(string $attemptId): void
    {
        DB::transaction(function () use ($attemptId) {
            $probe = DB::table('provider_call_attempts')->where('id', $attemptId)->first();
            if (! $probe) {
                return;
            }
            OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->firstOrFail();
            DB::table('provider_call_attempts')->where('id', $attemptId)->where('status', 'reserved')
                ->update(['status' => 'released', 'settled_microusd' => 0, 'updated_at' => now()]);
        }, 3);
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
