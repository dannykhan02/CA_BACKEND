<?php

namespace App\Services\AiCredits;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\SubscriptionUsagePeriod;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\CreditLedger;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Variable-price reservation, settlement and release. Every mutation runs under the workspace row lock,
 * is keyed by the quote id (ledger references are unique), and never lets a balance go negative.
 *
 * Funding: an active subscription's monthly AI credits pay first. Saved credits (plus not-yet-converted
 * legacy document units) pay only when there is no subscription period, or when
 * ai_credits.saved_fallback_during_subscription allows it. One operation is funded by ONE bucket.
 * Reserving does not change a balance; availability subtracts open reservations and Ready/settle debits.
 */
class CreditAccountant
{
    public const MONTHLY = 'subscription_ai_credit';

    public const SAVED = 'saved_ai_credit';

    /** 'credits' = AI-credit accounting applies; 'legacy' = the 1-document-unit rules still govern this workspace. */
    public function mode(string $workspaceId): string
    {
        if (! QuoteService::enabled()) {
            return 'legacy';
        }
        $ent = app(EntitlementService::class);
        $sub = $ent->subscription($workspaceId);
        $period = $ent->paid($sub) ? $ent->period($sub) : null;

        // A period created before rollout keeps its purchase-time document/comparison contract.
        return $period && $period->ai_credits_allowed === null ? 'legacy' : 'credits';
    }

    public function balances(string $workspaceId): array
    {
        $ent = app(EntitlementService::class);
        $sub = $ent->subscription($workspaceId);
        $period = $ent->paid($sub) ? $ent->period($sub) : null;
        $credits = WorkspaceCredit::where('workspace_id', $workspaceId)->first();
        $held = fn () => DB::table('billing_operations')->where('workspace_id', $workspaceId)->where('status', 'reserved')->whereNotNull('amount_reserved');
        $rate = max(1, (int) config('ai_credits.legacy_saved_credits_per_document'));
        $saved = (int) ($credits?->ai_credits_remaining ?? 0) + (int) ($credits?->documents_remaining ?? 0) * $rate;
        $savedHeld = (int) $held()->whereNull('usage_period_id')->sum('amount_reserved');
        $monthly = null;
        if ($period && $period->ai_credits_allowed !== null) {
            $periodHeld = (int) $held()->where('usage_period_id', $period->id)->sum('amount_reserved');
            $monthly = ['period_id' => $period->id, 'allowed' => (int) $period->ai_credits_allowed, 'used' => (int) $period->ai_credits_used,
                'reserved' => $periodHeld, 'remaining' => max(0, $period->ai_credits_allowed - $period->ai_credits_used - $periodHeld),
                'period_end' => $period->period_end];
        }

        return ['monthly' => $monthly, 'saved' => $saved, 'saved_reserved' => $savedHeld, 'saved_available' => max(0, $saved - $savedHeld),
            'legacy_period' => $period && $period->ai_credits_allowed === null, 'paid' => $ent->paid($sub)];
    }

    /** Credits usable right now for a new operation (the bucket that would fund it). */
    public function available(string $workspaceId): int
    {
        $b = $this->balances($workspaceId);
        if ($b['monthly'] !== null) {
            return config('ai_credits.saved_fallback_during_subscription') ? max($b['monthly']['remaining'], $b['saved_available']) : $b['monthly']['remaining'];
        }

        return $b['saved_available'];
    }

    /** @return array{0:string,1:?int} bucket and usage period id; aborts 402 when nothing can pay. */
    public function fund(string $workspaceId, int $credits): array
    {
        $b = $this->balances($workspaceId);
        if ($b['monthly'] !== null && $b['monthly']['remaining'] >= $credits) {
            return [self::MONTHLY, $b['monthly']['period_id']];
        }
        if ($b['monthly'] === null || config('ai_credits.saved_fallback_during_subscription')) {
            if ($b['saved_available'] >= $credits) {
                return [self::SAVED, null];
            }
        }
        abort(402, sprintf('Insufficient credits: %d available, %d required.', $this->available($workspaceId), $credits));
    }

    public function reserve(OperationQuote $quote, ?string $userId = null): void
    {
        DB::transaction(function () use ($quote, $userId) {
            Workspace::whereKey($quote->workspace_id)->lockForUpdate()->firstOrFail();
            $quote = OperationQuote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $op = DB::table('billing_operations')->where('kind', $quote->kind)->where('resource_id', $quote->resource_id)->lockForUpdate()->first();
            if ($op && $op->quote_id === $quote->id && in_array($op->status, ['reserved', 'completed'], true)) {
                DB::table('billing_operations')->where('id', $op->id)->where('status', 'reserved')->update(['updated_at' => now()]);

                return; // idempotent: a retry or duplicate delivery never reserves twice
            }
            abort_if($quote->status !== 'quoted' && $quote->status !== 'reserved', 409, 'This quote is no longer open.');
            [$bucket, $periodId] = $this->fund($quote->workspace_id, $quote->credits);
            $attempt = $op ? $op->attempt_number + 1 : 1;
            DB::table('billing_operations')->updateOrInsert(['kind' => $quote->kind, 'resource_id' => $quote->resource_id], [
                'workspace_id' => $quote->workspace_id, 'usage_period_id' => $periodId, 'status' => 'reserved', 'attempt_number' => $attempt,
                'quote_id' => $quote->id, 'amount_reserved' => $quote->credits, 'amount_settled' => null, 'funding_bucket' => $bucket,
                'quote_version' => $quote->quote_version, 'release_reason' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $quote->update(['status' => 'reserved', 'funding_bucket' => $bucket, 'accepted_at' => $quote->accepted_at ?? now(), 'reserved_at' => now()]);
            app(CreditLedger::class)->record($quote->workspace_id, 'ai_reserve:'.$quote->id, $bucket, 'reserve', $quote->credits,
                $quote->kind.'_started', $quote->kind, $quote->resource_id, $userId);
            $this->log('reserved', $quote->refresh(), ['reserved_credits' => $quote->credits]);
        }, 3);
    }

    /** Exactly the quoted amount, once. Returns true when this call performed the debit. */
    public function settle(string $kind, string $resourceId, ?string $userId = null): bool
    {
        return DB::transaction(function () use ($kind, $resourceId, $userId) {
            $probe = DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $resourceId)->first();
            if (! $probe) {
                return false;
            }
            Workspace::whereKey($probe->workspace_id)->lockForUpdate()->firstOrFail();
            $op = DB::table('billing_operations')->where('id', $probe->id)->lockForUpdate()->first();
            if ($op->status !== 'reserved' || $op->amount_reserved === null) {
                return false;
            }
            $amount = (int) $op->amount_reserved;
            $balance = null;
            if ($op->funding_bucket === self::MONTHLY) {
                SubscriptionUsagePeriod::whereKey($op->usage_period_id)->increment('ai_credits_used', $amount);
                $period = SubscriptionUsagePeriod::find($op->usage_period_id);
                $balance = max(0, $period->ai_credits_allowed - $period->ai_credits_used);
            } else {
                $balance = $this->debitSaved($op->workspace_id, $amount, $op->quote_id);
            }
            DB::table('billing_operations')->where('id', $op->id)->update(['status' => 'completed', 'amount_settled' => $amount, 'updated_at' => now()]);
            OperationQuote::whereKey($op->quote_id)->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now()]);
            app(CreditLedger::class)->record($op->workspace_id, 'ai_debit:'.$op->quote_id, $op->funding_bucket, 'debit', $amount,
                $kind.'_completed', $kind, $resourceId, $userId, $balance);
            $this->log('settled', OperationQuote::findOrFail($op->quote_id), ['settled_credits' => $amount]);

            return true;
        }, 3);
    }

    public function release(string $kind, string $resourceId, string $reason, ?string $userId = null): bool
    {
        return DB::transaction(function () use ($kind, $resourceId, $reason, $userId) {
            $probe = DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $resourceId)->first();
            if (! $probe) {
                return false;
            }
            Workspace::whereKey($probe->workspace_id)->lockForUpdate()->firstOrFail();
            $op = DB::table('billing_operations')->where('id', $probe->id)->lockForUpdate()->first();
            if ($op->status !== 'reserved' || $op->amount_reserved === null) {
                return false;
            }
            DB::table('billing_operations')->where('id', $op->id)->update(['status' => 'released', 'release_reason' => $reason, 'updated_at' => now()]);
            OperationQuote::whereKey($op->quote_id)->update(['status' => 'released', 'release_reason' => $reason, 'released_at' => now(), 'updated_at' => now()]);
            app(CreditLedger::class)->record($op->workspace_id, 'ai_release:'.$op->quote_id, $op->funding_bucket, 'release', (int) $op->amount_reserved,
                $reason, $kind, $resourceId, $userId);
            $this->log('released', OperationQuote::findOrFail($op->quote_id), ['released_credits' => (int) $op->amount_reserved, 'reason' => $reason]);

            return true;
        }, 3);
    }

    /** Settle every open AI-credit operation a document's Ready result pays for (analysis, OCR, re-analysis). */
    public function settleDocument(string $documentId, ?string $userId = null): bool
    {
        $any = false;
        foreach (['document', 'ocr', 'reanalysis'] as $kind) {
            $any = $this->settle($kind, $documentId, $userId) || $any;
        }

        return $any;
    }

    public function releaseDocument(string $documentId, string $reason): void
    {
        foreach (['document', 'ocr', 'reanalysis'] as $kind) {
            $this->release($kind, $documentId, $reason);
        }
    }

    /** Draws explicit saved AI credits first, then converts legacy document units only as far as needed. */
    private function debitSaved(string $workspaceId, int $amount, string $quoteId): int
    {
        $credits = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
        $rate = max(1, (int) config('ai_credits.legacy_saved_credits_per_document'));
        $ai = (int) $credits->ai_credits_remaining;
        $units = (int) $credits->documents_remaining;
        $converted = 0;
        $convertedUnits = 0;
        if ($ai < $amount) {
            $convertedUnits = min($units, (int) ceil(($amount - $ai) / $rate));
            $converted = $convertedUnits * $rate;
        }
        $pool = $ai + $converted;
        if ($pool < $amount) {
            // Reservation guaranteed availability; this only guards a corrupted balance. Never go negative.
            Log::warning('AI credit settlement exceeded the saved balance.', ['workspace_id' => $workspaceId, 'quote_id' => $quoteId]);
        }
        $credits->forceFill(['ai_credits_remaining' => max(0, $pool - $amount), 'documents_remaining' => $units - $convertedUnits])->save();
        if ($convertedUnits > 0) {
            $ledger = app(CreditLedger::class);
            $ledger->record($workspaceId, 'ai_convert_out:'.$quoteId, 'saved_document', 'debit', $convertedUnits, 'legacy_units_converted', 'quote', $quoteId);
            $ledger->record($workspaceId, 'ai_convert_in:'.$quoteId, self::SAVED, 'credit', $converted, 'legacy_units_converted', 'quote', $quoteId);
        }

        return (int) $credits->ai_credits_remaining + (int) $credits->documents_remaining * $rate;
    }

    /** Metadata only: ids, amounts, bands and cost totals. Never document text, prompts, quotes or provider payloads. */
    private function log(string $event, OperationQuote $quote, array $extra = []): void
    {
        $spend = app(OperationSpend::class);
        $runs = DocumentAiRun::where('operation_quote_id', $quote->id);
        Log::info('AI credit operation '.$event, [
            'operation_key' => $quote->operation_key, 'quote_id' => $quote->id, 'workspace_id' => $quote->workspace_id, 'kind' => $quote->kind,
            'resource_id' => $quote->resource_id, 'band' => $quote->band, 'quoted_credits' => $quote->credits, 'funding_bucket' => $quote->funding_bucket,
            'quote_version' => $quote->quote_version, 'provider_cost_cap_usd' => $quote->provider_cost_cap_usd,
            'provider_cost_usd' => $spend->spentUsd($quote->id), 'actual_known' => ! (clone $runs)->whereNull('estimated_cost_usd')->exists(),
            'status' => $quote->status, 'created_at' => $quote->created_at?->toIso8601String(),
        ] + $extra);
    }

    /** Grants saved AI credits (free trial, referral, future packs). Caller holds the workspace transaction. */
    public function grantSaved(string $workspaceId, int $credits, string $reference, string $reason, ?string $relatedType = null, ?string $relatedId = null, ?string $userId = null): void
    {
        $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
        $row->forceFill(['ai_credits_remaining' => $row->ai_credits_remaining + $credits])->save();
        $rate = max(1, (int) config('ai_credits.legacy_saved_credits_per_document'));
        app(CreditLedger::class)->record($workspaceId, $reference, self::SAVED, 'credit', $credits, $reason, $relatedType, $relatedId, $userId,
            (int) $row->ai_credits_remaining + (int) $row->documents_remaining * $rate);
    }
}
