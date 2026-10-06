<?php

namespace App\Services;

use App\Models\CreditPurchase;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\TrialGrant;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public function subscription(string $workspace): ?Subscription
    {
        $sub = Subscription::where('workspace_id', $workspace)->first();
        if ($sub && in_array($sub->status, ['active', 'non_renewing', 'past_due'], true) && $sub->current_period_end
            && $sub->current_period_end->addDays($sub->status === 'past_due' ? config('billing.grace_days') : 0)->isPast()) {
            Subscription::whereKey($sub->id)->where('current_period_end', $sub->current_period_end)->where('status', $sub->status)->update(['status' => $sub->cancel_at_period_end ? 'cancelled' : 'expired', 'ended_at' => $sub->current_period_end]);
            $sub->refresh();
        }

        if (! $sub || $sub->grandfathered || (in_array($sub->status, ['expired', 'cancelled'], true) && ! $sub->auto_renews)) {
            $sub = $this->grandfather($workspace, $sub);
        }

        return $sub;
    }

    private function grandfather(string $workspace, ?Subscription $existing): ?Subscription
    {
        $purchase = CreditPurchase::where('workspace_id', $workspace)->whereNull('plan_key')->where('status', 'completed')
            ->where('documents_purchased', '>', 0)->where('amount_kobo_or_cents', '>', 0)
            ->where('paystack_response->data->domain', 'live')->where('paystack_response->data->status', 'success')->oldest('id')->first();
        if (! $purchase) {
            return $existing;
        }

        return DB::transaction(function () use ($workspace, $purchase) {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $sub = Subscription::where('workspace_id', $workspace)->first();
            if ($sub && ! $sub->grandfathered && ($this->paid($sub) || $sub->auto_renews)) {
                return $sub;
            }
            $anchor = $sub?->grandfathered ? $sub->current_period_start : CarbonImmutable::now();
            $offset = (int) floor($anchor->diffInMonths(now()));
            $start = $anchor->addMonthsNoOverflow($offset);
            while ($start->isFuture()) {
                $start = $anchor->addMonthsNoOverflow(--$offset);
            }
            $end = $anchor->addMonthsNoOverflow($offset + 1);
            $sub = Subscription::updateOrCreate(['workspace_id' => $workspace], [
                'user_id' => $purchase->user_id, 'plan_key' => 'starter', 'billing_interval' => 'monthly', 'provider' => 'legacy',
                'grandfathered' => true, 'auto_renews' => false, 'cancel_at_period_end' => false, 'status' => 'active',
                'current_period_start' => $anchor, 'current_period_end' => $end, 'next_payment_date' => null,
                'provider_subscription_code' => null, 'provider_plan_code' => null, 'cancelled_at' => null, 'ended_at' => null,
                'metadata' => ['legacy_purchase_id' => $purchase->id],
            ]);
            $plan = config('billing.plans.starter');
            $sub->periods()->firstOrCreate(['period_start' => $start], ['period_end' => $end,
                'documents_allowed' => $plan['documents'], 'comparisons_allowed' => $plan['comparisons'], 'storage_bytes' => $plan['storage_bytes']]);

            return $sub;
        }, 3);
    }

    public function paid(?Subscription $sub): bool
    {
        return $sub && in_array($sub->status, ['active', 'non_renewing', 'past_due'], true)
            && $sub->current_period_start?->lessThanOrEqualTo(now())
            && $sub->current_period_end?->addDays($sub->status === 'past_due' ? config('billing.grace_days') : 0)->isFuture();
    }

    public function period(Subscription $sub): ?SubscriptionUsagePeriod
    {
        $period = $sub->periods()->where('period_start', '<=', now())->where('period_end', '>', now())->first();

        // Grace uses the last allowance; it never creates free renewed capacity.
        return $period ?? ($sub->status === 'past_due' && $this->paid($sub) ? $sub->periods()->latest('period_start')->first() : null);
    }

    public function legacy(string $workspace): bool
    {
        return config('billing.preserve_unknown_legacy_access') && CreditPurchase::where('workspace_id', $workspace)->whereNull('plan_key')->where('status', 'completed')->whereNull('paystack_response->data->domain')->exists();
    }

    private function releaseFailures(string $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $kinds = ['document', 'ocr', 'reanalysis'];
            $stale = DB::table('billing_operations')->where('workspace_id', $workspace)->where('status', 'reserved')->whereIn('kind', $kinds)
                ->where(function ($q) {
                    $q->where('updated_at', '<=', now()->subHours(config('billing.pipeline_hours')))->orWhereNotIn('resource_id', Document::select('id'))->orWhereIn('resource_id', Document::whereIn('status', ['Failed', 'Needs Review'])->select('id'));
                })
                ->get();
            foreach ($stale as $operation) {
                if ($operation->amount_reserved !== null) {
                    // Variable-price operation: Needs Review settles only when product policy says a partial result is paid.
                    $status = Document::whereKey($operation->resource_id)->value('status');
                    if ($status === 'Needs Review' && config('ai_credits.needs_review_policy') === 'settle') {
                        app(CreditAccountant::class)->settle($operation->kind, $operation->resource_id);
                    } else {
                        app(CreditAccountant::class)->release($operation->kind, $operation->resource_id, $status === null ? 'document_removed' : ($status === 'Failed' ? 'document_failed' : ($status === 'Needs Review' ? 'needs_review' : 'reservation_expired')));
                    }

                    continue;
                }
                $changed = DB::table('billing_operations')->where('id', $operation->id)->where('status', 'reserved')
                    ->where('updated_at', $operation->updated_at)->update(['status' => 'released', 'updated_at' => now()]);
                if ($changed) {
                    app(CreditLedger::class)->record($workspace, 'document_release:'.$operation->resource_id.':'.$operation->attempt_number,
                        $operation->usage_period_id ? 'subscription_document' : 'saved_document', 'release', 1,
                        'document_not_completed', 'document', $operation->resource_id);
                }
            }
        }, 3);
    }

    public function summary(string $workspace): array
    {
        $sub = $this->subscription($workspace);
        $period = $this->paid($sub) ? $this->period($sub) : null;
        $credits = WorkspaceCredit::where('workspace_id', $workspace)->first();
        $this->releaseFailures($workspace);
        $held = DB::table('billing_operations')->where('workspace_id', $workspace)->where('kind', 'document')->where('status', 'reserved')->whereNull('amount_reserved');
        $reserved = $period ? (clone $held)->where('usage_period_id', $period->id)->count() : (clone $held)->whereNull('usage_period_id')->count();

        return [
            'subscription' => $sub ? $sub->only(['id', 'user_id', 'plan_key', 'billing_interval', 'status', 'auto_renews', 'grandfathered', 'cancel_at_period_end', 'current_period_start', 'current_period_end', 'next_payment_date', 'cancelled_at']) : null,
            'usage' => $period?->only(['period_start', 'period_end', 'documents_allowed', 'documents_used', 'comparisons_allowed', 'comparisons_used']),
            'documents_remaining' => max(0, ($period ? $period->documents_allowed - $period->documents_used : ($credits?->documents_remaining ?? 0)) - $reserved),
            'documents_reserved' => $reserved,
            'documents_purchased_total' => $credits?->documents_purchased_total ?? 0,
            'saved_credits' => $credits?->documents_remaining ?? 0,
            'free_initial_credits' => config('billing.free_initial_credits'),
            'original_free_allowance' => TrialGrant::where('workspace_id', $workspace)->value('initial_credits'),
            'storage_used_bytes' => (int) Document::where('workspace_id', $workspace)->sum('size_kb') * 1024,
            'storage_allowed_bytes' => $period?->storage_bytes ?? ($this->legacy($workspace) ? null : config('billing.free_storage_bytes')),
            'paid_access' => $this->paid($sub),
            'ai_credits' => $this->aiCreditSummary($workspace),
        ];
    }

    /** Additive API block. documents_remaining and friends keep their legacy meaning. */
    public function aiCreditSummary(string $workspace): array
    {
        $quotes = app(QuoteService::class);
        if (! QuoteService::enabled()) {
            return ['enabled' => false];
        }
        $accountant = app(CreditAccountant::class);
        $b = $accountant->balances($workspace);
        $m = $b['monthly'];

        return ['enabled' => true, 'mode' => $accountant->mode($workspace), 'monthlyCredits' => $m['allowed'] ?? null, 'monthlyCreditsUsed' => $m['used'] ?? null,
            'monthlyCreditsReserved' => $m['reserved'] ?? null, 'monthlyCreditsRemaining' => $m['remaining'] ?? null, 'monthlyCreditsResetAt' => $m['period_end'] ?? null,
            'savedCredits' => $b['saved'], 'savedCreditsReserved' => $b['saved_reserved'], 'savedCreditsAvailable' => $b['saved_available'],
            'availableCredits' => $accountant->available($workspace), 'minimumRequiredCredits' => $quotes->minimumDocumentCredits(),
            // Large analyses wait here until the customer accepts the quoted credits.
            'pendingConfirmations' => Document::where('workspace_id', $workspace)->where('status', 'Processing')->where('ai_pipeline->awaiting_credit_confirmation', true)
                ->get(['id', 'ai_pipeline'])->map(fn ($d) => ['documentId' => $d->id, 'credits' => $d->ai_pipeline['credit_quote']['credits'] ?? null, 'band' => $d->ai_pipeline['credit_quote']['band'] ?? null])->all()];
    }

    public function assertAiAccess(string $workspace): void
    {
        $state = $this->summary($workspace);
        if ($state['ai_credits']['enabled'] && $state['ai_credits']['mode'] === 'credits') {
            abort_unless($state['paid_access'] || $state['ai_credits']['availableCredits'] > 0 || $this->legacy($workspace), 402, 'Subscribe or renew to use AI. Your existing work remains available.');

            return;
        }
        abort_unless($state['paid_access'] || $state['documents_remaining'] > 0 || $this->legacy($workspace), 402, 'Subscribe or renew to use AI. Your existing work remains available.');
    }

    public function assertProcessing(string $workspace, int $bytes = 0): void
    {
        $state = $this->summary($workspace);
        if ($state['ai_credits']['enabled'] && $state['ai_credits']['mode'] === 'credits') {
            $need = $state['ai_credits']['minimumRequiredCredits'];
            abort_if($state['ai_credits']['availableCredits'] < $need, 402, sprintf('Insufficient credits: %d available, %d required.', $state['ai_credits']['availableCredits'], $need));
        } else {
            abort_if($state['documents_remaining'] <= 0, 402, 'No processing credits remain. Subscribe or renew to process new documents. Your existing work remains available.');
        }
        abort_if($bytes > 0 && $state['storage_allowed_bytes'] !== null && $state['storage_allowed_bytes'] < $state['storage_used_bytes'] + $bytes, 402, 'Your storage allowance would be exceeded.');
    }

    /** Reserve under a workspace lock; Ready remains the only document debit. */
    public function reserveDocument(Document $document, bool $newRequest = false): void
    {
        DB::transaction(function () use ($document, $newRequest) {
            Workspace::whereKey($document->workspace_id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
            if (app(CreditAccountant::class)->mode($document->workspace_id) === 'credits' && ! $document->credit_accounted_at) {
                // The price depends on the extracted text, so credits are reserved at admission (AiCreditAdmission),
                // before the first paid call. Here only keep a live reservation alive and require some balance.
                if ($operation && $operation->amount_reserved !== null && $operation->status === 'reserved') {
                    DB::table('billing_operations')->where('id', $operation->id)->update(['updated_at' => now()]);
                } elseif (! $operation || $operation->status !== 'completed') {
                    $this->assertProcessing($document->workspace_id);
                }

                return;
            }
            if ($operation && $operation->status !== 'released' && ! $newRequest && CarbonImmutable::parse($operation->updated_at)->addHours(config('billing.pipeline_hours'))->isFuture()) {
                // A reserved/settled operation may finish its original pipeline. A new API retry must recheck current access.
                return;
            }
            $this->assertProcessing($document->workspace_id);
            if ($document->credit_accounted_at) {
                if ($operation) {
                    DB::table('billing_operations')->where('id', $operation->id)->update(['updated_at' => now()]);
                }

                return;
            } // Original once-per-document rule for retries.
            $sub = $this->subscription($document->workspace_id);
            $period = $this->paid($sub) ? $this->period($sub) : null;
            $attempt = $operation ? $operation->attempt_number + ($operation->status === 'released' ? 1 : 0) : 1;
            DB::table('billing_operations')->updateOrInsert(['kind' => 'document', 'resource_id' => $document->id], [
                'workspace_id' => $document->workspace_id, 'usage_period_id' => $period?->id, 'status' => 'reserved',
                'attempt_number' => $attempt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CreditLedger::class)->record($document->workspace_id, 'document_reserve:'.$document->id.':'.$attempt,
                $period ? 'subscription_document' : 'saved_document', 'reserve', 1, 'document_started', 'document', $document->id, $document->uploaded_by);
        }, 3);
    }

    /** Returns true if a subscription allowance paid, false for the existing balance. */
    public function settleDocument(Document $document): bool
    {
        Workspace::whereKey($document->workspace_id)->lockForUpdate()->firstOrFail();
        $op = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->lockForUpdate()->first();
        if (! $op) {
            return false;
        } // Compatibility for previously queued/legacy documents.
        if ($op->status !== 'completed') {
            if ($op->usage_period_id) {
                SubscriptionUsagePeriod::whereKey($op->usage_period_id)->increment('documents_used');
                app(CreditLedger::class)->record($document->workspace_id, 'document_usage:'.$document->id,
                    'subscription_document', 'debit', 1, 'document_completed', 'document', $document->id, $document->uploaded_by);
            }
            DB::table('billing_operations')->where('id', $op->id)->update(['status' => 'completed', 'updated_at' => now()]);
        }

        return $op->usage_period_id !== null;
    }

    public function reserveComparison(DocumentComparison $comparison, bool $newRequest = false): void
    {
        if (! isset($comparison->metadata['ai_context'])) {
            return;
        }
        DB::transaction(function () use ($comparison, $newRequest) {
            Workspace::whereKey($comparison->workspace_id)->lockForUpdate()->firstOrFail();
            $accountant = app(CreditAccountant::class);
            if ($accountant->mode($comparison->workspace_id) === 'credits' && ! (! $this->paid($this->subscription($comparison->workspace_id)) && $this->legacy($comparison->workspace_id))) {
                $accountant->reserve(app(QuoteService::class)->quoteComparison($comparison->workspace_id, $comparison->id, $newRequest), $comparison->created_by);

                return;
            }
            $operation = DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparison->id)->first();
            if ($operation && ! $newRequest && CarbonImmutable::parse($operation->updated_at)->addHours(config('billing.pipeline_hours'))->isFuture()) {
                return;
            }
            $sub = $this->subscription($comparison->workspace_id);
            $period = $this->paid($sub) ? $this->period($sub) : null;
            if ($operation && $period && $operation->usage_period_id === $period->id && $operation->status !== 'released') {
                DB::table('billing_operations')->where('id', $operation->id)->update(['updated_at' => now()]);

                return;
            }
            if (! $period && $this->legacy($comparison->workspace_id)) {
                return;
            }
            abort_unless($period && $period->comparisons_used < $period->comparisons_allowed, 402, 'Subscribe or renew for an available AI comparison allowance.');
            $period->increment('comparisons_used');
            $attempt = $operation ? $operation->attempt_number + 1 : 1;
            DB::table('billing_operations')->updateOrInsert(['kind' => 'comparison', 'resource_id' => $comparison->id], ['workspace_id' => $comparison->workspace_id, 'usage_period_id' => $period->id,
                'kind' => 'comparison', 'resource_id' => $comparison->id, 'status' => 'reserved', 'attempt_number' => $attempt,
                'created_at' => now(), 'updated_at' => now()]);
            app(CreditLedger::class)->record($comparison->workspace_id, 'comparison_reserve:'.$comparison->id.':'.$attempt,
                'subscription_comparison', 'reserve', 1, 'comparison_started', 'comparison', $comparison->id, $comparison->created_by);
        }, 3);
    }

    public function settleComparison(DocumentComparison $comparison): void
    {
        DB::transaction(function () use ($comparison) {
            Workspace::whereKey($comparison->workspace_id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparison->id)->lockForUpdate()->first();
            abort_if($operation && $operation->status === 'released', 409, 'This comparison reservation has been released. Retry the comparison.');
            if ($operation && $operation->amount_reserved !== null) {
                app(CreditAccountant::class)->settle('comparison', $comparison->id, $comparison->created_by);

                return;
            }
            $changed = DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparison->id)
                ->where('status', 'reserved')->update(['status' => 'completed', 'updated_at' => now()]);
            if ($changed) {
                app(CreditLedger::class)->record($comparison->workspace_id, 'comparison_usage:'.$comparison->id,
                    'subscription_comparison', 'debit', 1, 'comparison_completed', 'comparison', $comparison->id, $comparison->created_by);
            }
        }, 3);
    }

    public function releaseComparison(DocumentComparison $comparison): void
    {
        DB::transaction(function () use ($comparison) {
            Workspace::whereKey($comparison->workspace_id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparison->id)->lockForUpdate()->first();
            if (! $operation || $operation->status !== 'reserved') {
                return;
            }
            if ($operation->amount_reserved !== null) {
                app(CreditAccountant::class)->release('comparison', $comparison->id, 'comparison_failed', $comparison->created_by);

                return;
            }
            if ($operation->usage_period_id) {
                SubscriptionUsagePeriod::whereKey($operation->usage_period_id)->where('comparisons_used', '>', 0)->decrement('comparisons_used');
            }
            DB::table('billing_operations')->where('id', $operation->id)->update(['status' => 'released', 'updated_at' => now()]);
            app(CreditLedger::class)->record($comparison->workspace_id, 'comparison_release:'.$comparison->id.':'.$operation->attempt_number,
                'subscription_comparison', 'release', 1, 'comparison_failed', 'comparison', $comparison->id, $comparison->created_by);
        }, 3);
    }
}
