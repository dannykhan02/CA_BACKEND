<?php

namespace App\Services;

use App\Models\CreditPurchase;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function catalog(): array
    {
        $plans = [];
        foreach (config('billing.plans') as $key => $plan) {
            $codes = $plan['plan_codes'];
            unset($plan['plan_codes']);
            $plans[] = ['key' => $key, ...$plan, 'automatic_available' => array_map(fn ($code) => (bool) $code, $codes)];
        }

        return ['free_initial_credits' => config('billing.free_initial_credits'), 'currency' => config('billing.currency'), 'plans' => $plans];
    }

    /** Called under the financial transaction lock, only after amount/currency verification. */
    public function completePurchase(CreditPurchase $purchase): void
    {
        Workspace::whereKey($purchase->workspace_id)->lockForUpdate()->firstOrFail();
        if ($purchase->status !== 'pending') {
            return;
        }
        $data = $purchase->paystack_response['data'];
        $paid = CarbonImmutable::parse($data['paid_at'] ?? $data['paidAt'] ?? now())->utc();
        $subscription = Subscription::firstOrCreate(['workspace_id' => $purchase->workspace_id], [
            'user_id' => $purchase->user_id, 'plan_key' => $purchase->plan_key, 'billing_interval' => $purchase->billing_interval,
        ]);
        $recurring = $purchase->subscription_id !== null;
        $purchase->forceFill(['subscription_id' => $subscription->id, 'status' => 'completed', 'paid_at' => $paid,
            'provider_transaction_id' => isset($data['id']) ? (string) $data['id'] : null])->save();
        // A late older charge is financial history, not another renewal.
        if ($purchase->renewal_type !== 'manual' && isset($subscription->metadata['last_paid_at']) && $paid->lessThanOrEqualTo(CarbonImmutable::parse($subscription->metadata['last_paid_at']))) {
            return;
        }

        $manual = $purchase->renewal_type === 'manual';
        $wasGrandfathered = $subscription->grandfathered;
        $start = ! $wasGrandfathered && $manual && $subscription->current_period_end?->greaterThan($paid) ? $subscription->current_period_end : $paid;
        $end = $purchase->billing_interval === 'annual' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
        $sameContract = $subscription->plan_key === $purchase->plan_key && $subscription->billing_interval === $purchase->billing_interval;
        $snapshot = $purchase->billing_metadata;
        $subscription->forceFill([
            'user_id' => $purchase->user_id, 'plan_key' => $purchase->plan_key, 'billing_interval' => $purchase->billing_interval,
            'provider_customer_code' => $data['customer']['customer_code'] ?? $subscription->provider_customer_code,
            'provider_plan_code' => $purchase->provider_plan_code,
            'provider_subscription_code' => $recurring && $sameContract && ! $manual ? $subscription->provider_subscription_code : null,
            'auto_renews' => $recurring && $sameContract && ! $manual && $subscription->provider_subscription_code !== null,
            'grandfathered' => false, 'provider' => 'paystack',
            'status' => 'active', 'cancel_at_period_end' => false, 'cancelled_at' => null, 'ended_at' => null,
            'current_period_start' => ! $wasGrandfathered && $manual && $subscription->current_period_end?->isFuture() ? $subscription->current_period_start : $start,
            'current_period_end' => $end,
            'next_payment_date' => ! $manual ? $end : null,
            'metadata' => [...($sameContract ? ($subscription->metadata ?? []) : []), 'last_paid_at' => $paid->toIso8601String(),
                'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency,
                'renewal_requested' => $purchase->renewal_type, 'allowance' => $snapshot],
        ])->save();
        if (! $manual || $wasGrandfathered) {
            $subscription->periods()->where('period_start', '<', $start)->where('period_end', '>', $start)->update(['period_end' => $start]);
        }
        $months = $purchase->billing_interval === 'annual' ? 12 : 1;
        for ($i = 0; $i < $months; $i++) {
            $usage = $subscription->periods()->firstOrCreate(['period_start' => $start->addMonthsNoOverflow($i)], [
                'period_end' => $start->addMonthsNoOverflow($i + 1)->min($end),
                'documents_allowed' => $snapshot['documents'], 'comparisons_allowed' => $snapshot['comparisons'],
                'storage_bytes' => $snapshot['storage_bytes'],
            ]);
            if ($wasGrandfathered && ! $usage->wasRecentlyCreated) {
                $usage->update(['period_end' => $end, 'documents_allowed' => $snapshot['documents'], 'comparisons_allowed' => $snapshot['comparisons'], 'storage_bytes' => $snapshot['storage_bytes']]);
            }
        }
        // subscription.create may arrive before charge.success/verification.
        foreach (DB::table('billing_webhook_events')->whereNull('processed_at')->where('event_type', 'subscription.create')->get() as $event) {
            $payload = json_decode($event->payload, true);
            if ($this->lifecycle($payload['event'], $payload['data'])) {
                DB::table('billing_webhook_events')->where('id', $event->id)->update(['processed_at' => now()]);
            }
        }
    }

    public function receive(array $payload): void
    {
        if ($payload['event'] === 'charge.success') {
            $data = $payload['data'];
            $purchase = CreditPurchase::where('paystack_reference', $data['reference'] ?? '')->first();
            if ($purchase && $purchase->status === 'pending' && (($data['status'] ?? null) !== 'success'
                || ($data['amount'] ?? null) !== $purchase->amount_kobo_or_cents || ($data['currency'] ?? null) !== $purchase->currency)) {
                CreditPurchase::whereKey($purchase->id)->where('status', 'pending')->update(['paystack_response' => $payload]);
                abort(422, 'Charge does not match purchase.');
            }
        }
        // Invoice reconciliation needs network verification, before acquiring workspace/event locks.
        $invoiceCharge = null;
        $invoiceAlreadyRecorded = false;
        if ($payload['event'] === 'invoice.update' && ($payload['data']['paid'] ?? false) && isset($payload['data']['transaction']['reference'])) {
            $data = $payload['data'];
            $sub = Subscription::where('provider_subscription_code', $data['subscription']['subscription_code'] ?? '')->first();
            if ($sub) {
                $reference = $data['transaction']['reference'];
                $invoiceAlreadyRecorded = CreditPurchase::where('paystack_reference', $reference)->where('subscription_id', $sub->id)->where('status', 'completed')->exists();
                if (! $invoiceAlreadyRecorded) {
                    $verified = app(PaystackClient::class)->verify($reference);
                    abort_unless(($verified['data']['reference'] ?? null) === $reference, 422, 'Invoice verification reference mismatch.');
                    $invoiceCharge = ['event' => 'charge.success', 'data' => [...$verified['data'], 'plan' => $sub->provider_plan_code, 'subscription' => ['subscription_code' => $sub->provider_subscription_code], 'customer' => ['customer_code' => $sub->provider_customer_code]]];
                }
            }
        }
        DB::transaction(function () use ($payload, $invoiceCharge, $invoiceAlreadyRecorded) {
            $key = hash('sha256', json_encode($payload));
            DB::table('billing_webhook_events')->insertOrIgnore(['event_key' => $key, 'event_type' => $payload['event'],
                'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
            $event = DB::table('billing_webhook_events')->where('event_key', $key)->lockForUpdate()->first();
            if ($event->processed_at) {
                return;
            }
            $done = $invoiceAlreadyRecorded || ($invoiceCharge !== null ? $this->charge($invoiceCharge) : ($payload['event'] === 'charge.success' ? $this->charge($payload) : $this->lifecycle($payload['event'], $payload['data'])));
            DB::table('billing_webhook_events')->where('id', $event->id)->update(['processed_at' => $done ? now() : null, 'updated_at' => now()]);
        }, 3);
    }

    private function charge(array $payload): bool
    {
        $data = $payload['data'];
        abort_unless(is_string($data['reference'] ?? null) && ($data['status'] ?? null) === 'success', 422, 'Invalid charge.');
        $purchase = CreditPurchase::where('paystack_reference', $data['reference'])->lockForUpdate()->first();
        if (! $purchase) {
            // Never match renewal ownership on an email supplied by a browser.
            $plan = (is_array($data['plan'] ?? null) ? ($data['plan']['plan_code'] ?? null) : ($data['plan'] ?? null)) ?: ($data['plan_object']['plan_code'] ?? null);
            $customer = $data['customer']['customer_code'] ?? null;
            if (! $plan || ! $customer) {
                return false;
            }
            $subscriptionCode = $data['subscription']['subscription_code'] ?? $data['subscription_code'] ?? null;
            $matches = Subscription::where('provider_customer_code', $customer)->where('provider_plan_code', $plan)
                ->whereNotNull('provider_subscription_code')
                ->when($subscriptionCode, fn ($q) => $q->where('provider_subscription_code', $subscriptionCode))->get();
            if ($matches->count() !== 1) {
                return false;
            }
            $sub = $matches->first();
            Workspace::whereKey($sub->workspace_id)->lockForUpdate()->firstOrFail();
            if (($data['amount'] ?? null) !== $sub->metadata['amount'] || ($data['currency'] ?? null) !== $sub->metadata['currency']) {
                abort(422, 'Charge does not match subscription.');
            }
            if (! isset($data['paid_at']) && ! isset($data['paidAt'])) {
                abort(422, 'Renewal requires payment time.');
            }
            $purchase = CreditPurchase::firstOrCreate(['paystack_reference' => $data['reference']], [
                'workspace_id' => $sub->workspace_id, 'user_id' => $sub->user_id, 'subscription_id' => $sub->id,
                'documents_purchased' => 0, 'amount_kobo_or_cents' => $sub->metadata['amount'], 'currency' => $sub->metadata['currency'],
                'status' => 'pending', 'plan_key' => $sub->plan_key, 'billing_interval' => $sub->billing_interval,
                'renewal_type' => 'automatic', 'provider_plan_code' => $sub->provider_plan_code, 'billing_metadata' => $sub->metadata['allowance'],
            ]);
        }
        if ($purchase->status !== 'pending') {
            return true;
        }
        abort_unless(($data['amount'] ?? null) === $purchase->amount_kobo_or_cents && ($data['currency'] ?? null) === $purchase->currency, 422, 'Charge does not match purchase.');
        if (isset($data['id']) && CreditPurchase::where('provider_transaction_id', (string) $data['id'])->whereKeyNot($purchase->id)->exists()) {
            abort(422, 'Charge already recorded.');
        }
        $purchase->paystack_response = $payload;
        app(WorkspaceCreditService::class)->completePurchase($purchase);

        return true;
    }

    /** Provider event strings are translated here, never in authorization code. */
    private function lifecycle(string $event, array $data): bool
    {
        $code = $data['subscription']['subscription_code'] ?? $data['subscription_code'] ?? null;
        $sub = $code ? Subscription::where('provider_subscription_code', $code)->first() : null;
        if (! $sub && $event === 'subscription.create' && $code) {
            $customer = $data['customer']['customer_code'] ?? null;
            $plan = $data['plan']['plan_code'] ?? null;
            if (! $customer || ! $plan) {
                return false;
            }
            $matches = Subscription::where('provider_customer_code', $customer)->where('provider_plan_code', $plan)->whereNull('provider_subscription_code')->get();
            if ($matches->count() !== 1) {
                return false;
            }
            $sub = $matches->first();
        }
        if (! $sub) {
            return false;
        }
        Workspace::whereKey($sub->workspace_id)->lockForUpdate()->firstOrFail();
        $sub->refresh();
        if ($event === 'subscription.create') {
            // Linking an actual provider subscription does not grant a paid period.
            $sub->provider_subscription_code = $code;
            $sub->auto_renews = ! $sub->cancel_at_period_end;
            $sub->metadata = [...($sub->metadata ?? []), 'email_token' => $data['email_token'] ?? null];
            if (isset($data['next_payment_date'])) {
                $next = CarbonImmutable::parse($data['next_payment_date'])->utc();
                $sub->next_payment_date = $next;
                // Paystack bills month-end signups on the 28th in subsequent months.
                if ($next->greaterThan($sub->current_period_start) && $next->lessThan($sub->current_period_end)) {
                    $sub->periods()->where('period_end', $sub->current_period_end)->update(['period_end' => $next]);
                    $sub->current_period_end = $next;
                }
            }
        } elseif (in_array($event, ['subscription.not_renew', 'subscription.disable'], true)) {
            $sub->auto_renews = false;
            $sub->cancel_at_period_end = true;
            $sub->cancelled_at ??= now();
            $sub->status = $sub->current_period_end?->isFuture() ? 'non_renewing' : 'cancelled';
            if ($sub->status === 'cancelled') {
                $sub->ended_at ??= now();
            }
        } elseif ($event === 'invoice.payment_failed' || ($event === 'invoice.update' && ($data['paid'] ?? false) === false && in_array($data['status'] ?? '', ['failed', 'attention'], true))) {
            // Old failures cannot undo a newer successful renewal.
            $period = $data['period_start'] ?? $data['created_at'] ?? null;
            if ($period && isset($sub->metadata['last_paid_at']) && CarbonImmutable::parse($period)->lessThan(CarbonImmutable::parse($sub->metadata['last_paid_at']))) {
                return true;
            }
            $sub->status = 'past_due';
        } elseif ($event === 'invoice.update' && ($data['paid'] ?? false)) {
            // An unlinked invoice remains deferred; receive() verifies it once linked.
            return false;
        }
        $sub->save();

        return true;
    }
}
