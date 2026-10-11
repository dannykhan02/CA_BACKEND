<?php

namespace Tests\Unit\Services;

use App\Exceptions\EmbeddingProviderException;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VoyageEmbeddingClientPrivacyTest extends TestCase
{
    public function test_failed_response_logs_only_safe_metadata(): void
    {
        config(['services.voyage.model' => 'voyage-3', 'services.voyage.api_key' => 'private-api-key']);
        Http::preventStrayRequests();
        Http::fake(['api.voyageai.com/*' => Http::response('Private query and document text; private-api-key', 429,
            ['request-id' => 'voyage-request-123'])]);
        Log::spy();

        try {
            app(VoyageEmbeddingClient::class)->embed(['Private query and document text'], 'query');
            $this->fail('A failed embedding request must throw.');
        } catch (EmbeddingProviderException) {
            // The public exception carries no upstream body.
        }

        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            $encoded = json_encode($context);

            return $message === 'Voyage embeddings API error'
                && $context['provider'] === 'voyage' && $context['operation'] === 'embeddings'
                && $context['model'] === 'voyage-3' && $context['status'] === 429
                && $context['failure_code'] === 'rate_limit' && $context['request_id'] === 'voyage-request-123'
                && is_int($context['duration_ms']) && $context['duration_ms'] >= 0
                && ! str_contains($encoded, 'Private query') && ! str_contains($encoded, 'private-api-key')
                && ! array_key_exists('body', $context);
        });
    }
}
