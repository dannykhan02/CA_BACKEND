<?php

namespace App\Services;

use App\Exceptions\PaystackInitializationException;
use App\Models\CreditPurchase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
                    'callback_url' => rtrim(config('app.frontend_url'), '/').'/#/billing/return',
                ]);
        } catch (ConnectionException) {
            throw new PaystackInitializationException;
        }

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaystackInitializationException(
                in_array($response->status(), [400, 401, 403, 422], true)
                || ($response->successful() && $response->json('status') === false)
            );
        }

        $url = $response->json('data.authorization_url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || $response->json('data.reference') !== $purchase->paystack_reference) {
            throw new PaystackInitializationException;
        }

        return ['authorization_url' => $url, 'reference' => $purchase->paystack_reference];
    }
}
