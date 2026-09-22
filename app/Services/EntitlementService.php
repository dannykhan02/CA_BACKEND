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
        DB::table('billing_operations')->where('workspace_id', $workspace)->where('status', 'reserved')->where('kind', 'document')
            ->where(function ($q) {
                $q->where('updated_at', '<=', now()->subHours(config('billing.pipeline_hours')))->orWhereNotIn('resource_id', Document::select('id'))->orWhereIn('resource_id', Document::whereIn('status', ['Failed', 'Needs Review'])->select('id'));
            })
            ->update(['status' => 'released', 'updated_at' => now()]);
    }

    public function summary(string $workspace): array
    {
        $sub = $this->subscription($workspace);
        $period = $this->paid($sub) ? $this->period($sub) : null;
        $credits = WorkspaceCredit::where('workspace_id', $workspace)->first();
        $this->releaseFailures($workspace);
        $held = DB::table('billing_operations')->where('workspace_id', $workspace)->where('kind', 'document')->where('status', 'reserved');
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
        ];
    }

    public function assertAiAccess(string $workspace): void
    {
        $state = $this->summary($workspace);
        abort_unless($state['paid_access'] || $state['documents_remaining'] > 0 || $this->legacy($workspace), 402, 'Subscribe or renew to use AI. Your existing work remains available.');
    }

    public function assertProcessing(string $workspace, int $bytes = 0): void
    {
        $state = $this->summary($workspace);
        abort_if($state['documents_remaining'] <= 0, 402, 'No processing credits remain. Subscribe or renew to process new documents. Your existing work remains available.');
        abort_if($bytes > 0 && $state['storage_allowed_bytes'] !== null && $state['storage_allowed_bytes'] < $state['storage_used_bytes'] + $bytes, 402, 'Your storage allowance would be exceeded.');
    }

    /** Reserve under a workspace lock; Ready remains the only document debit. */
    public function reserveDocument(Document $document, bool $newRequest = false): void
    {
        DB::transaction(function () use ($document, $newRequest) {
            Workspace::whereKey($document->workspace_id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
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
            DB::table('billing_operations')->updateOrInsert(['kind' => 'document', 'resource_id' => $document->id], [
                'workspace_id' => $document->workspace_id, 'usage_period_id' => $period?->id, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ]);
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
            $operation = DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $comparison->id)->first();
            if ($operation && ! $newRequest && CarbonImmutable::parse($operation->updated_at)->addHours(config('billing.pipeline_hours'))->isFuture()) {
                return;
            }
            $sub = $this->subscription($comparison->workspace_id);
            $period = $this->paid($sub) ? $this->period($sub) : null;
            if ($operation && $period && $operation->usage_period_id === $period->id) {
                DB::table('billing_operations')->where('id', $operation->id)->update(['updated_at' => now()]);

                return;
            }
            if (! $period && $this->legacy($comparison->workspace_id)) {
                return;
            }
            abort_unless($period && $period->comparisons_used < $period->comparisons_allowed, 402, 'Subscribe or renew for an available AI comparison allowance.');
            $period->increment('comparisons_used');
            DB::table('billing_operations')->updateOrInsert(['kind' => 'comparison', 'resource_id' => $comparison->id], ['workspace_id' => $comparison->workspace_id, 'usage_period_id' => $period->id,
                'kind' => 'comparison', 'resource_id' => $comparison->id, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
