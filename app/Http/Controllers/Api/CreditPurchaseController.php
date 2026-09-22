<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaystackInitializationException;
use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\PaystackClient;
use App\Services\SubscriptionService;
use App\Services\WorkspaceCreditService;
use App\Support\SafeExceptionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreditPurchaseController extends Controller
{
    public function verify(Request $request, string $reference, PaystackClient $client): JsonResponse
    {
        $purchase = CreditPurchase::where('paystack_reference', $reference)->firstOrFail();
        $user = $request->user();
        // Legacy purchases have no purchaser identity; allow a member of their
        // workspace to reconcile them, without exposing another tenant's data.
        abort_unless($purchase->workspace_id === $user->current_workspace_id
            && $purchase->workspace->members()->where('user_id', $user->id)->exists()
            && ($purchase->user_id === null || $purchase->user_id === $user->id), 403);

        if ($purchase->status !== 'pending') {
            return $this->purchaseStatus($purchase);
        }
        if (! config('services.paystack.secret_key')) {
            return $this->error('Payments are temporarily unavailable.', [], 503);
        }

        // Verify with Paystack outside the transaction; never hold database
        // locks during an external request or trust payment status from a URL.
        try {
            $payload = $client->verify($purchase->paystack_reference);
        } catch (\Throwable $error) {
            Log::error('Paystack verification unavailable.', SafeExceptionContext::for($error, [
                'purchase_id' => $purchase->id,
                'reference' => $purchase->paystack_reference,
                'user_id' => $user->id,
                'workspace_id' => $purchase->workspace_id,
            ]));

            return $this->error('We could not verify your payment yet. Please try again shortly.', [], 502);
        }

        return DB::transaction(function () use ($purchase, $payload) {
            $purchase = CreditPurchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== 'pending') {
                return $this->purchaseStatus($purchase);
            }
            $data = $payload['data'];
            if (($data['reference'] ?? null) !== $purchase->paystack_reference
                || ($data['amount'] ?? null) !== $purchase->amount_kobo_or_cents
                || ($data['currency'] ?? null) !== $purchase->currency) {
                Log::warning('Paystack verification does not match purchase.', ['reference' => $purchase->paystack_reference]);

                return $this->error('Charge does not match purchase.', [], 422);
            }
            if (isset($data['id']) && CreditPurchase::where('provider_transaction_id', (string) $data['id'])->whereKeyNot($purchase->id)->exists()) {
                return $this->error('Charge already recorded.', [], 422);
            }
            $purchase->paystack_response = $payload;
            if (($data['status'] ?? null) === 'success') {
                app(WorkspaceCreditService::class)->completePurchase($purchase);
            } else {
                // Keep non-successful verifications pending: a later genuine
                // success webhook must still be able to complete the purchase.
                $purchase->save();
            }

            return $this->purchaseStatus($purchase);
        }, 3);
    }

    private function purchaseStatus(CreditPurchase $purchase): JsonResponse
    {
        $credits = app(EntitlementService::class)->summary($purchase->workspace_id);

        return $this->success('Payment status retrieved.', [
            'reference' => $purchase->paystack_reference,
            'status' => $purchase->status,
            'documents_remaining' => $credits['documents_remaining'],
            'documents_purchased_total' => $credits['documents_purchased_total'],
        ]);
    }

    public function store(Request $request, PaystackClient $client): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('billing.plans')))],
            'interval' => ['required', Rule::in(['monthly', 'annual'])],
            'renewal' => ['required', Rule::in(['automatic', 'manual'])],
            'amount' => ['prohibited'], 'price' => ['prohibited'], 'plan_code' => ['prohibited'],
        ]);
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace, 409, 'Select a workspace before subscribing.');
        abort_unless($workspace->members()->where('user_id', $request->user()->id)->exists(), 403);
        if (! config('services.paystack.secret_key')) {
            return $this->error('Payments are temporarily unavailable.', [], 503);
        }
        $plan = config('billing.plans.'.$validated['plan']);
        $code = $validated['renewal'] === 'automatic' ? $plan['plan_codes'][$validated['interval']] : null;
        abort_if($validated['renewal'] === 'automatic' && ! $code, 503, 'Automatic renewal is not configured for this plan. Choose manual renewal.');
        $purchase = DB::transaction(function () use ($request, $workspace, $validated, $plan, $code) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $entitlements = app(EntitlementService::class);
            $sub = $entitlements->subscription($workspace->id);
            abort_if($sub && $sub->user_id !== $request->user()->id, 403, 'Only the billing owner can renew this subscription.');
            abort_if($sub && ($sub->auto_renews || ($sub->metadata['renewal_requested'] ?? null) === 'automatic') && ! $sub->cancel_at_period_end, 409, 'Manage or cancel the existing automatic subscription before starting another checkout.');
            abort_if($sub?->grandfathered && $validated['plan'] === 'starter', 409, 'You already have ongoing Starter access at no charge.');
            abort_if(! $sub?->grandfathered && $entitlements->paid($sub) && ($sub->plan_key !== $validated['plan'] || $sub->billing_interval !== $validated['interval'] || $validated['renewal'] !== 'manual'), 409, 'Plan changes are available after the current paid period ends.');
            abort_if($workspace->purchases()->whereNotNull('plan_key')->where('status', 'pending')->where('created_at', '>', now()->subDay())->exists(), 409, 'A checkout is pending. Verify that payment before starting another.');

            return $workspace->purchases()->create([
                'user_id' => $request->user()->id, 'paystack_reference' => 'credits-'.Str::uuid(),
                'documents_purchased' => 0, 'amount_kobo_or_cents' => $plan['prices'][$validated['interval']],
                'currency' => config('billing.currency'), 'status' => 'pending',
                'plan_key' => $validated['plan'], 'billing_interval' => $validated['interval'],
                'renewal_type' => $validated['renewal'], 'provider_plan_code' => $code,
                'billing_metadata' => Arr::only($plan, ['documents', 'comparisons', 'storage_bytes']),
            ]);
        }, 3);

        try {
            $transaction = $client->initialize($purchase, $request->user()->email);
        } catch (PaystackInitializationException $exception) {
            if ($exception->definitivelyRejected) {
                // A webhook may have completed the row while initialize ran.
                CreditPurchase::whereKey($purchase->id)->where('status', 'pending')->update(['status' => 'failed']);
            }
            // Ambiguous network/server failures stay pending for reconciliation
            // and a possible later success webhook.
            Log::error('Paystack initialization failed.', SafeExceptionContext::for($exception, [
                'purchase_id' => $purchase->id,
                'reference' => $purchase->paystack_reference,
                'user_id' => $purchase->user_id,
                'workspace_id' => $purchase->workspace_id,
                'definitively_rejected' => $exception->definitivelyRejected,
                'failure_reason' => $exception->failureReason,
                'upstream_status' => $exception->upstreamStatus,
                'upstream_message' => $exception->upstreamMessage,
            ]));

            return $this->error($exception->getMessage(), [], 502);
        }

        return $this->success('Payment initialized.', $transaction, 201);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.paystack.secret_key');
        if (! is_string($secret) || $secret === '') {
            return $this->error('Payments are temporarily unavailable.', [], 503);
        }

        $raw = $request->getContent();
        $signature = $request->header('x-paystack-signature');
        if (! is_string($signature) || ! hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) {
            return $this->error('Invalid Paystack signature.', [], 401);
        }

        // Parse only after authenticating the exact bytes delivered by Paystack.
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Log::error('Paystack webhook JSON parsing failed.', SafeExceptionContext::for($exception, [
                'operation' => 'paystack.webhook.parse',
            ]));

            return $this->error('Invalid webhook payload.', [], 400);
        }
        if (! is_array($payload)) {
            return $this->error('Invalid webhook payload.', [], 400);
        }
        $events = ['charge.success', 'subscription.create', 'invoice.create', 'invoice.update', 'invoice.payment_failed', 'subscription.not_renew', 'subscription.disable'];
        if (! in_array($payload['event'] ?? null, $events, true)) {
            return $this->success('Webhook received.');
        }
        if (! is_array($payload['data'] ?? null)) {
            return $this->error('Invalid webhook payload.', [], 422);
        }
        app(SubscriptionService::class)->receive($payload);

        return $this->success('Webhook received.');
    }
}
