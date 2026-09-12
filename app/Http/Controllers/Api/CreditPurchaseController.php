<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaystackInitializationException;
use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Services\PaystackClient;
use App\Services\WorkspaceCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            Log::warning('Paystack verification unavailable.', ['reference' => $purchase->paystack_reference]);

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
        $credits = $purchase->workspace->credits()->firstOrFail();

        return $this->success('Payment status retrieved.', [
            'reference' => $purchase->paystack_reference,
            'status' => $purchase->status,
            'documents_remaining' => $credits->documents_remaining,
            'documents_purchased_total' => $credits->documents_purchased_total,
        ]);
    }

    public function store(Request $request, PaystackClient $client): JsonResponse
    {
        $packages = config('credits.packages');
        $validated = $request->validate([
            'package' => ['required', 'string', Rule::in(array_keys($packages))],
        ]);
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace, 409, 'Select a workspace before purchasing credits.');
        abort_unless($workspace->members()->where('user_id', $request->user()->id)->exists(), 403);

        if (! config('services.paystack.secret_key')) {
            return $this->error('Payments are temporarily unavailable.', [], 503);
        }

        $package = $packages[$validated['package']];
        // Persist before the external call so even a very early webhook can
        // resolve this reference. Never take workspace, amount or credits from input.
        $purchase = $workspace->purchases()->create([
            'user_id' => $request->user()->id,
            'paystack_reference' => 'credits-'.Str::uuid(),
            'documents_purchased' => $package['documents'],
            'amount_kobo_or_cents' => $package['amount_kobo_or_cents'],
            'currency' => $package['currency'],
            'status' => 'pending',
        ]);

        try {
            $transaction = $client->initialize($purchase, $request->user()->email);
        } catch (PaystackInitializationException $exception) {
            if ($exception->definitivelyRejected) {
                // A webhook may have completed the row while initialize ran.
                CreditPurchase::whereKey($purchase->id)->where('status', 'pending')->update(['status' => 'failed']);
            }
            // Ambiguous network/server failures stay pending for reconciliation
            // and a possible later success webhook.
            Log::warning('Paystack initialization failed.', ['reference' => $purchase->paystack_reference]);

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
        } catch (\JsonException) {
            return $this->error('Invalid webhook payload.', [], 400);
        }
        if (! is_array($payload)) {
            return $this->error('Invalid webhook payload.', [], 400);
        }
        if (($payload['event'] ?? null) !== 'charge.success') {
            return $this->success('Webhook received.');
        }
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_string($data['reference'] ?? null) || $data['reference'] === '') {
            return $this->error('Invalid charge payload.', [], 422);
        }

        return DB::transaction(function () use ($payload, $data) {
            $purchase = CreditPurchase::where('paystack_reference', $data['reference'])->lockForUpdate()->first();
            if (! $purchase) {
                Log::warning('Paystack webhook reference is not a credit purchase.', ['reference' => $data['reference']]);

                return $this->success('Webhook received.');
            }
            if ($purchase->status !== 'pending') {
                return $this->success('Webhook received.');
            }

            $purchase->paystack_response = $payload;
            if (($data['status'] ?? null) !== 'success'
                || ($data['amount'] ?? null) !== $purchase->amount_kobo_or_cents
                || ($data['currency'] ?? null) !== $purchase->currency) {
                $purchase->save();
                Log::warning('Paystack charge does not match purchase.', ['reference' => $purchase->paystack_reference]);

                return $this->error('Charge does not match purchase.', [], 422);
            }

            app(WorkspaceCreditService::class)->completePurchase($purchase);

            return $this->success('Webhook received.');
        }, 3);
    }
}
