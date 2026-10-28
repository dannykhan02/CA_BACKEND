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

    public const TRIAL = 'trial_ai_credit';

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
        $savedHeld = (int) $held()->whereNull('usage_period_id')->where(fn ($q) => $q->whereNull('funding_bucket')->orWhere('funding_bucket', '!=', self::TRIAL))->sum('amount_reserved');
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

    /** Whether saved credits may fund an operation now: always without a subscription period, otherwise only with the fallback on. */
    public function savedUsable(string $workspaceId): bool
    {
        return $this->balances($workspaceId)['monthly'] === null || (bool) config('ai_credits.saved_fallback_during_subscription');
    }

    /**
     * Credits usable right now for a new operation: what the single bucket that would fund it can give. Buckets are never added
     * together, because a hold is never split. Saved credits count only where holdPlan() may use them.
     */
    public function available(string $workspaceId): int
    {
        $b = $this->balances($workspaceId);
        $pockets = [$b['monthly']['remaining'] ?? 0, $this->trialAvailable($workspaceId)];
        if ($b['monthly'] === null || config('ai_credits.saved_fallback_during_subscription')) {
            $pockets[] = $b['saved_available'];
        }

        return max($pockets);
    }

    /**
     * Credits a hold must cover for the operation to start. The whole quote, except in settlement mode 'actual' with
     * ai_credits.admission_min_ratio below 1: then ceil(quote x ratio), at least 1 (integer math on ten-thousandths).
     */
    public function requiredToStart(int $credits, ?string $kind = null): int
    {
        // Only operations that settle at actual cost may start on a partial hold. A comparison or a metered question is a fixed
        // price: held short it would also be CHARGED short (settle() charges the hold), so it needs the whole price.
        if (ActualCostSettlement::mode() !== 'actual' || ($kind !== null && ! in_array($kind, ActualCostSettlement::KINDS, true))) {
            return $credits;
        }
        // Defence in depth (the config already rejects these): zero, negative or non-numeric means the whole quote, above 1 is 1.
        $configured = (float) config('ai_credits.admission_min_ratio');
        $ratio = (int) round(min(1.0, $configured > 0.0 ? $configured : 1.0) * 10000);

        return max(1, min($credits, intdiv($credits * $ratio + 9999, 10000)));
    }

    /** @return array{remaining:int,expires_at:?\Carbon\Carbon,expired:bool,held:int,available:int} the trial pocket; expired credits count as 0 (checked on read) */
    public function trialBalance(string $workspaceId): array
    {
        $row = WorkspaceCredit::where('workspace_id', $workspaceId)->first();
        $remaining = (int) ($row?->trial_credits_remaining ?? 0);
        $expiresAt = $row?->trial_credits_expires_at ? \Illuminate\Support\Carbon::parse($row->trial_credits_expires_at) : null;
        $expired = $expiresAt !== null && ! $expiresAt->isFuture();
        $held = (int) DB::table('billing_operations')->where('workspace_id', $workspaceId)->where('status', 'reserved')
            ->where('funding_bucket', self::TRIAL)->whereNotNull('amount_reserved')->sum('amount_reserved');

        return ['remaining' => $remaining, 'expires_at' => $expiresAt, 'expired' => $expired, 'held' => $held,
            'available' => $expired ? 0 : max(0, $remaining - $held)];
    }

    /** Trial credits usable now (0 once expired, and net of open trial holds). */
    public function trialAvailable(string $workspaceId): int
    {
        return $this->trialBalance($workspaceId)['available'];
    }

    /** Grants a trial into the expiring trial pocket. Idempotent on the reference. Caller holds the workspace transaction. */
    public function grantTrial(string $workspaceId, int $credits, int $days, string $reference, ?string $userId = null): void
    {
        $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
        if (DB::table('credit_ledger')->where('reference', $reference)->exists()) {
            return;
        }
        $row->forceFill(['trial_credits_remaining' => (int) $row->trial_credits_remaining + $credits,
            'trial_credits_expires_at' => now()->addDays($days)])->save();
        app(CreditLedger::class)->record($workspaceId, $reference, self::TRIAL, 'credit', $credits, 'trial_grant', 'user', $userId, $userId,
            (int) $row->trial_credits_remaining);
    }

    /**
     * When the first paid subscription starts, whatever is left of an unexpired trial becomes saved credits, once
     * (reference trial_carryover:<workspace>). An expired trial carries nothing. Credits held by an open document stay in the pocket.
     * Caller holds the workspace transaction. Returns the credits moved.
     */
    public function carryOverTrial(string $workspaceId, ?string $userId = null): int
    {
        $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->first();
        if (! $row || DB::table('credit_ledger')->where('reference', 'trial_carryover:'.$workspaceId)->exists()) {
            return 0;
        }
        $move = $this->trialAvailable($workspaceId);
        if ($move < 1) {
            return 0;
        }
        $row->forceFill(['trial_credits_remaining' => (int) $row->trial_credits_remaining - $move,
            'ai_credits_remaining' => (int) $row->ai_credits_remaining + $move])->save();
        $ledger = app(CreditLedger::class);
        $ledger->record($workspaceId, 'trial_carryover_out:'.$workspaceId, self::TRIAL, 'debit', $move, 'trial_carried_over', 'workspace', $workspaceId, $userId,
            (int) $row->trial_credits_remaining);
        $rate = max(1, (int) config('ai_credits.legacy_saved_credits_per_document'));
        $ledger->record($workspaceId, 'trial_carryover:'.$workspaceId, self::SAVED, 'credit', $move, 'trial_carried_over', 'workspace', $workspaceId, $userId,
            (int) $row->ai_credits_remaining + (int) $row->documents_remaining * $rate);

        return $move;
    }

    /**
     * Writes off an expired trial's unused credits (the part held by an open document stays until that settles). One ledger row per expiry that moves credits; a rerun with nothing left does nothing. Returns the credits expired.
     */
    public function expireTrial(string $workspaceId): int
    {
        return DB::transaction(function () use ($workspaceId) {
            Workspace::whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->first();
            $balance = $this->trialBalance($workspaceId);
            $expire = $balance['remaining'] - $balance['held'];
            if (! $row || ! $balance['expired'] || $expire < 1) {
                return 0;
            }
            $row->forceFill(['trial_credits_remaining' => $balance['held']])->save();
            // A hold left in the pocket expires on a later run with the same expiry date, so the reference carries a sequence number:
            // a repeated reference would be ignored by the ledger and the balance would move without a row.
            $sequence = DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('reason', 'trial_expired')->count() + 1;
            app(CreditLedger::class)->record($workspaceId, 'trial_expire:'.$workspaceId.':'.$balance['expires_at']->getTimestamp().':'.$sequence, self::TRIAL, 'debit',
                $expire, 'trial_expired', 'workspace', $workspaceId, null, (int) $row->trial_credits_remaining);

            return $expire;
        });
    }

    /** Debits the trial pocket. It cannot go negative: a trial that expired mid-document pays only what is left (logged). */
    private function debitTrial(string $workspaceId, int $amount, string $quoteId): int
    {
        $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
        $take = min($amount, (int) $row->trial_credits_remaining);
        if ($take < $amount) {
            Log::warning('AI credit settlement exceeded the trial balance.', ['workspace_id' => $workspaceId, 'quote_id' => $quoteId]);
        }
        $row->forceFill(['trial_credits_remaining' => (int) $row->trial_credits_remaining - $take])->save();

        return (int) $row->trial_credits_remaining;
    }

    /**
     * The one bucket that funds a hold, and how much it holds. In order: monthly (full quote, then at least the required
     * minimum: all it has), trial, then saved credits where allowed. A hold is never split across buckets and never exceeds
     * what the bucket has available. Null when nothing can pay.
     *
     * @return ?array{bucket:string,period_id:?int,hold:int}
     */
    public function holdPlan(string $workspaceId, int $credits, ?string $kind = null): ?array
    {
        $b = $this->balances($workspaceId);
        $need = $this->requiredToStart($credits, $kind);
        $savedAllowed = $b['monthly'] === null || config('ai_credits.saved_fallback_during_subscription');
        $pockets = [];
        if ($b['monthly'] !== null) {
            $pockets[] = [self::MONTHLY, $b['monthly']['period_id'], $b['monthly']['remaining']];
        }
        $pockets[] = [self::TRIAL, null, $this->trialAvailable($workspaceId)];
        if ($savedAllowed) {
            $pockets[] = [self::SAVED, null, $b['saved_available']];
        }
        foreach ($pockets as [$bucket, $periodId, $available]) {
            if ($available >= $credits) {
                return ['bucket' => $bucket, 'period_id' => $periodId, 'hold' => $credits];
            }
            if ($need < $credits && $available >= $need) {
                return ['bucket' => $bucket, 'period_id' => $periodId, 'hold' => $available];
            }
        }

        return null;
    }

    /** Why a hold of this quote could not start: what each bucket has, as it would be used (see savedUsable). Stored as ai_pipeline.credit_block. */
    public function shortfall(string $workspaceId, int $quoted): array
    {
        $b = $this->balances($workspaceId);

        return ['code' => 'insufficient_credits', 'quoted_credits' => $quoted, 'required_to_start' => $this->requiredToStart($quoted),
            'monthly_available' => (int) ($b['monthly']['remaining'] ?? 0), 'trial_available' => $this->trialAvailable($workspaceId),
            'saved_available' => $this->savedUsable($workspaceId) ? (int) $b['saved_available'] : 0, 'at' => now()->toIso8601String()];
    }

    /** @return array{0:string,1:?int,2:int} bucket, usage period id and held credits; aborts 402 when nothing can pay. */
    public function fund(string $workspaceId, int $credits, ?string $kind = null): array
    {
        $plan = $this->holdPlan($workspaceId, $credits, $kind);
        if ($plan === null) {
            abort(402, sprintf('Insufficient credits: %d available, %d required.', $this->available($workspaceId), $this->requiredToStart($credits, $kind)));
        }

        return [$plan['bucket'], $plan['period_id'], $plan['hold']];
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
            [$bucket, $periodId, $hold] = $this->fund($quote->workspace_id, $quote->credits, $quote->kind);
            $attempt = $op ? $op->attempt_number + 1 : 1;
            DB::table('billing_operations')->updateOrInsert(['kind' => $quote->kind, 'resource_id' => $quote->resource_id], [
                'workspace_id' => $quote->workspace_id, 'usage_period_id' => $periodId, 'status' => 'reserved', 'attempt_number' => $attempt,
                'quote_id' => $quote->id, 'amount_reserved' => $hold, 'amount_settled' => null, 'funding_bucket' => $bucket,
                'quote_version' => $quote->quote_version, 'release_reason' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $quote->update(['status' => 'reserved', 'held_credits' => $hold, 'funding_bucket' => $bucket, 'accepted_at' => $quote->accepted_at ?? now(), 'reserved_at' => now()]);
            app(CreditLedger::class)->record($quote->workspace_id, 'ai_reserve:'.$quote->id, $bucket, 'reserve', $hold,
                $quote->kind.'_started', $quote->kind, $quote->resource_id, $userId);
            $this->log('reserved', $quote->refresh(), ['reserved_credits' => $hold]);
        }, 3);
    }

    /** The quoted amount, or in ai_credits.settlement_mode 'actual' the recorded cost (ActualCostSettlement), once. Returns true when this call performed the debit. */
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
            $quote = OperationQuote::whereKey($op->quote_id)->lockForUpdate()->firstOrFail();
            $reserved = (int) $op->amount_reserved;
            $plan = app(ActualCostSettlement::class)->plan($quote, $reserved);
            if ($plan['basis'] === 'quoted_fallback') {
                Log::warning('AI credit settlement fell back to the quoted amount.', ['document_id' => $resourceId, 'quote_id' => $quote->id,
                    'kind' => $kind, 'reason' => $plan['reason']]);
            }
            // The hold never moved a balance, so charging less than it IS the release: only the charged amount is debited.
            $amount = $plan['charged'];
            $balance = null;
            if ($op->funding_bucket === self::MONTHLY) {
                SubscriptionUsagePeriod::whereKey($op->usage_period_id)->increment('ai_credits_used', $amount);
                $period = SubscriptionUsagePeriod::find($op->usage_period_id);
                $balance = max(0, $period->ai_credits_allowed - $period->ai_credits_used);
            } elseif ($op->funding_bucket === self::TRIAL) {
                $balance = $this->debitTrial($op->workspace_id, $amount, $op->quote_id);
            } else {
                $balance = $this->debitSaved($op->workspace_id, $amount, $op->quote_id);
            }
            DB::table('billing_operations')->where('id', $op->id)->update(['status' => 'completed', 'amount_settled' => $amount, 'updated_at' => now()]);
            OperationQuote::whereKey($op->quote_id)->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now(),
                'charged_credits' => $amount, 'released_credits' => $plan['released'], 'actual_cost_usd' => $plan['actual_cost_usd'],
                'settlement_basis' => $plan['basis'], 'settlement_reason' => $plan['reason'], 'absorbed_credits' => $plan['absorbed'] > 0 ? $plan['absorbed'] : null]);
            if ($plan['absorbed'] > 0) {
                Log::warning('AI credit settlement absorbed credits', ['document_id' => $resourceId, 'quote_id' => $quote->id, 'kind' => $kind,
                    'hold' => $reserved, 'formula_result' => $plan['charged'] + $plan['absorbed']]);
                // Evidence only ('info' moves no balance and is outside the reserve/debit/release reconciliation): what we absorbed.
                app(CreditLedger::class)->record($op->workspace_id, 'ai_absorbed:'.$op->quote_id, $op->funding_bucket, 'info', $plan['absorbed'],
                    $kind.'_cost_above_hold', $kind, $resourceId, $userId);
            }
            app(CreditLedger::class)->record($op->workspace_id, 'ai_debit:'.$op->quote_id, $op->funding_bucket, 'debit', $amount,
                $kind.'_completed', $kind, $resourceId, $userId, $balance);
            if ($plan['released'] > 0) {
                app(CreditLedger::class)->record($op->workspace_id, 'ai_release_unused:'.$op->quote_id, $op->funding_bucket, 'release',
                    $plan['released'], $kind.'_settled_below_quote', $kind, $resourceId, $userId);
            }
            $this->log('settled', OperationQuote::findOrFail($op->quote_id), ['settled_credits' => $amount, 'released_credits' => $plan['released'],
                'settlement_basis' => $plan['basis']]);

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
