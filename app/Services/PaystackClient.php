<?php

namespace App\Services;

use App\Exceptions\PaystackInitializationException;
use App\Models\CreditPurchase;
use App\Models\Subscription;
use App\Support\SafeExceptionContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackClient
{
    public function verify(string $reference): array
    {
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->acceptJson()->connectTimeout(5)->timeout(10)
            ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));

        if (! $response->successful() || $response->json('status') !== true
            || ! is_array($response->json('data'))) {
            throw new \RuntimeException('Payment verification is temporarily unavailable.');
        }

        return $response->json();
    }

    /** @return array{authorization_url: string, reference: string} */
    public function initialize(CreditPurchase $purchase, string $email): array
    {
        try {
            // Do not retry this POST automatically: a timeout may still have
            // initialized a transaction at Paystack.
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email' => $email,
                    'amount' => (string) $purchase->amount_kobo_or_cents,
                    'currency' => $purchase->currency,
                    'reference' => $purchase->paystack_reference,
                    ...($purchase->provider_plan_code ? ['plan' => $purchase->provider_plan_code, 'channels' => ['card']] : []),
                    'metadata' => ['purchase_reference' => $purchase->paystack_reference],
                    'callback_url' => rtrim(config('app.frontend_url'), '/').'/#/billing/return',
                ]);
        } catch (ConnectionException $exception) {
            Log::error('Paystack initialization connection failed.', SafeExceptionContext::for($exception, [
                'purchase_id' => $purchase->id,
                'reference' => $purchase->paystack_reference,
                'user_id' => $purchase->user_id,
                'workspace_id' => $purchase->workspace_id,
            ]));

            throw new PaystackInitializationException(false, 'connection_error');
        }

        $payload = $response->json();
        $providerMessage = is_array($payload) ? ($payload['message'] ?? null) : null;
        $safeMessage = is_string($providerMessage)
            ? SafeExceptionContext::for(new \RuntimeException($providerMessage))['exception_message']
            : null;

        if (! $response->successful()) {
            throw new PaystackInitializationException(
                in_array($response->status(), [400, 401, 403, 422], true),
                'upstream_http_error',
                $response->status(),
                $safeMessage,
            );
        }
        if (! is_array($payload)) {
            throw new PaystackInitializationException(false, 'response_json_invalid', $response->status());
        }
        $providerStatus = $payload['status'] ?? null;
        if ($providerStatus !== true) {
            throw new PaystackInitializationException($providerStatus === false, 'upstream_status_invalid', $response->status(), $safeMessage);
        }
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new PaystackInitializationException(false, 'response_data_invalid', $response->status(), $safeMessage);
        }

        $url = $data['authorization_url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new PaystackInitializationException(false, 'authorization_url_missing', $response->status(), $safeMessage);
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new PaystackInitializationException(false, 'authorization_url_invalid', $response->status(), $safeMessage);
        }
        if (($data['reference'] ?? null) !== $purchase->paystack_reference) {
            throw new PaystackInitializationException(false, 'reference_mismatch', $response->status(), $safeMessage);
        }

        return ['authorization_url' => $url, 'reference' => $purchase->paystack_reference];
    }

    public function disableSubscription(Subscription $subscription): void
    {
        $response = Http::withToken(config('services.paystack.secret_key'))->acceptJson()->connectTimeout(5)->timeout(10)
            ->get('https://api.paystack.co/subscription/'.rawurlencode($subscription->provider_subscription_code));
        if (! $response->successful() || $response->json('status') !== true) {
            throw new \RuntimeException('Unable to retrieve subscription.');
        }
        $token = $response->json('data.email_token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Subscription cancellation is temporarily unavailable.');
        }
        $response = Http::withToken(config('services.paystack.secret_key'))->acceptJson()->connectTimeout(5)->timeout(10)
            ->post('https://api.paystack.co/subscription/disable', ['code' => $subscription->provider_subscription_code, 'token' => $token]);
        if (! $response->successful() || $response->json('status') !== true) {
            throw new \RuntimeException('Unable to cancel subscription.');
        }
    }

    public function managementLink(Subscription $subscription): string
    {
        $response = Http::withToken(config('services.paystack.secret_key'))->acceptJson()->connectTimeout(5)->timeout(10)
            ->get('https://api.paystack.co/subscription/'.rawurlencode($subscription->provider_subscription_code).'/manage/link');
        $url = $response->json('data.link');
        if (! $response->successful() || $response->json('status') !== true || ! is_string($url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'paystack.com') {
            throw new \RuntimeException('Payment management is temporarily unavailable.');
        }

        return $url;
    }
}
