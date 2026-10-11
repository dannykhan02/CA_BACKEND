<?php

namespace App\Services\AiCredits;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Workspace;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * Provider spend attributed to one quote, and the hard internal ceiling it implies. Reuses
 * document_ai_runs (the existing usage record) rather than a second accounting store.
 */
class OperationSpend
{
    /** Internal cost scope only; never creates a customer quote or billing operation. */
    public function internalScope(?Document $document, ?string $purpose, ?string $workspaceId, ?string $resourceId): array
    {
        if ($purpose === 'document_qa') {
            if (! $workspaceId) {
                throw new AiProcessingException('provider_scope_unavailable');
            }
            $day = now()->toDateString();
            $cap = config('ai_credits.qa.max_cost_usd_per_workspace_per_day');
            $key = "qa:{$workspaceId}:{$day}";
            $historical = DocumentAiRun::where('workspace_id', $workspaceId)->where('provider', 'anthropic')
                ->where('purpose', 'document_qa')->where('created_at', '>=', now()->startOfDay())
                ->whereNull('operation_quote_id')->whereNull('provider_attempt_id');
        } elseif ($purpose === 'document_comparison') {
            if (! $document || ! $resourceId) {
                throw new AiProcessingException('provider_scope_unavailable');
            }
            $workspaceId = $document->workspace_id;
            $cap = config('document_intelligence.budget_max_usd');
            $key = "comparison:{$resourceId}";
            $historical = DocumentAiRun::where('workspace_id', $workspaceId)->where('provider', 'anthropic')
                ->where('purpose', 'document_comparison')->where('comparison_id', $resourceId)
                ->whereNull('operation_quote_id')->whereNull('provider_attempt_id');
        } else {
            if (! $document) {
                throw new AiProcessingException('provider_scope_unavailable');
            }
            $workspaceId = $document->workspace_id;
            $cap = config('document_intelligence.budget_max_usd');
            $key = "document:{$document->id}";
            $historical = DocumentAiRun::where('workspace_id', $workspaceId)->where('document_id', $document->id)
                ->where('provider', 'anthropic')->whereNotIn('purpose', ['document_comparison', 'document_qa'])
                ->whereNull('operation_quote_id')->whereNull('provider_attempt_id');
        }
        if (! is_numeric($cap) || ! is_finite((float) $cap) || (float) $cap <= 0) {
            throw new AiProcessingException('invalid_provider_config');
        }
        $known = (float) (clone $historical)->whereNotNull('estimated_cost_usd')->sum('estimated_cost_usd');
        $unknown = (clone $historical)->whereNull('estimated_cost_usd')->count();

        return ['workspace_id' => $workspaceId, 'key' => $key, 'cap_microusd' => (int) floor((float) $cap * 1000000),
            'historical_microusd' => (int) ceil(($known + $unknown * (float) config('ai_credits.unknown_usage_cost_usd')) * 1000000)];
    }

    /** A workspace row serializes all unquoted holds; the attempt table remains the sole cost ledger. */
    public function reserveInternalAttempt(array $scope, string $attemptId, string $purpose, float $maximumUsd): void
    {
        $micros = (int) ceil($maximumUsd * 1000000);
        if ($micros < 1 || $scope['cap_microusd'] < 1) {
            throw new AiProcessingException('invalid_provider_config');
        }
        DB::transaction(function () use ($scope, $attemptId, $purpose, $micros) {
            Workspace::whereKey($scope['workspace_id'])->lockForUpdate()->firstOrFail();
            if (DB::table('provider_call_attempts')->where('id', $attemptId)->exists()) {
                throw new AiProcessingException('duplicate_provider_attempt');
            }
            $attempts = DB::table('provider_call_attempts')->where('workspace_id', $scope['workspace_id'])
                ->where('scope_key', $scope['key']);
            $firstCap = (clone $attempts)->oldest('created_at')->value('scope_cap_microusd');
            $cap = min($scope['cap_microusd'], $firstCap === null ? $scope['cap_microusd'] : (int) $firstCap);
            $held = (int) (clone $attempts)->whereIn('status', ['reserved', 'dispatched', 'settled', 'uncertain'])
                ->sum(DB::raw('COALESCE(settled_microusd, reserved_microusd)'));
            if ($cap < $scope['historical_microusd'] + $held + $micros) {
                throw new AiProcessingException('budget_exceeded');
            }
            DB::table('provider_call_attempts')->insert(['id' => $attemptId, 'operation_quote_id' => null,
                'workspace_id' => $scope['workspace_id'], 'scope_key' => $scope['key'], 'scope_cap_microusd' => $cap,
                'purpose' => $purpose, 'status' => 'reserved', 'reserved_microusd' => $micros,
                'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

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
            if ($probe->operation_quote_id) {
                $quote = OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->first();
                if (! $quote || ! in_array($quote->status, ['reserved', 'settled'], true)) {
                    throw new AiProcessingException('credits_not_reserved');
                }
            } else {
                Workspace::whereKey($probe->workspace_id)->lockForUpdate()->firstOrFail();
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
            $probe->operation_quote_id
                ? OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->firstOrFail()
                : Workspace::whereKey($probe->workspace_id)->lockForUpdate()->firstOrFail();
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
            $probe->operation_quote_id
                ? OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->firstOrFail()
                : Workspace::whereKey($probe->workspace_id)->lockForUpdate()->firstOrFail();
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
        $quotes = OperationQuote::where('workspace_id', $document->workspace_id)->where('resource_id', $document->id)
            ->whereIn('kind', $kind)->whereIn('status', ['reserved', 'settled'])->get();

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
    public function assertCallAllowed(?Document $document, ?string $purpose, ?string $explicitQuoteId = null,
        ?string $operationResourceId = null, ?string $workspaceId = null): ?OperationQuote
    {
        if (! QuoteService::enabled()) {
            return null;
        }
        if ($explicitQuoteId !== null) {
            $quote = OperationQuote::find($explicitQuoteId);
            if (! $quote || ! in_array($quote->status, ['reserved', 'settled'], true)) {
                throw new AiProcessingException('credits_not_reserved');
            }
            $expectedKinds = match ($purpose) {
                'ocr' => ['ocr'],
                'document_comparison' => ['comparison'],
                'document_qa' => ['qa'],
                default => ['document', 'reanalysis'],
            };
            $expectedWorkspace = $document?->workspace_id ?? $workspaceId;
            $expectedResource = in_array($purpose, ['document_comparison', 'document_qa'], true)
                ? $operationResourceId : $document?->id;
            if (! in_array($quote->kind, $expectedKinds, true) || ! $expectedWorkspace || ! $expectedResource
                || $quote->workspace_id !== $expectedWorkspace || $quote->resource_id !== $expectedResource) {
                throw new AiProcessingException('quote_scope_mismatch');
            }
            $this->assertWithinCap($quote);

            return $quote;
        }
        if ($purpose === 'document_comparison') {
            if ($document && app(CreditAccountant::class)->mode($document->workspace_id) === 'credits') {
                $legacyOperation = $operationResourceId && DB::table('billing_operations')
                    ->where('kind', 'comparison')->where('resource_id', $operationResourceId)
                    ->where('workspace_id', $document->workspace_id)->where('status', 'reserved')
                    ->whereNull('amount_reserved')->exists();
                $entitlements = app(EntitlementService::class);
                $preservedLegacyAccess = $entitlements->legacy($document->workspace_id)
                    && ! $entitlements->paid($entitlements->subscription($document->workspace_id));
                if (! $operationResourceId || (! $legacyOperation && ! $preservedLegacyAccess)) {
                    throw new AiProcessingException('credits_not_reserved');
                }
            }

            return null;
        }
        if ($purpose === 'document_qa') {
            if ((int) config('ai_credits.qa.credits') > 0
                && (! $workspaceId || app(CreditAccountant::class)->mode($workspaceId) === 'credits')) {
                throw new AiProcessingException('credits_not_reserved');
            }

            return null;
        }
        if (! $document || app(CreditAccountant::class)->mode($document->workspace_id) === 'legacy') {
            return null;
        }
        $quote = $this->activeQuote($document, $purpose);
        if ($quote) {
            $this->assertWithinCap($quote);

            return $quote;
        }
        // Paid before the rollout, or reserved under the legacy 1-unit rules: unchanged.
        if (Document::whereKey($document->id)->value('credit_accounted_at')
            || DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->whereNull('amount_reserved')->exists()) {
            return null;
        }
        throw new AiProcessingException('credits_not_reserved');
    }
}
