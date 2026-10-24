<?php

namespace App\Services\Embeddings;

use App\Exceptions\EmbeddingProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;

use function Sentry\captureException;
use function Sentry\withScope;

class VoyageEmbeddingClient
{
    public function embed(array $texts, string $inputType = 'document'): array
    {
        if (empty($texts)) {
            return ['embeddings' => [], 'model' => config('services.voyage.model')];
        }

        $started = hrtime(true);
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.voyage.api_key'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post('https://api.voyageai.com/v1/embeddings', [
                'input' => $texts,
                'model' => config('services.voyage.model'),
                'input_type' => $inputType,
            ]);

        if ($response->failed()) {
            $requestId = $response->header('request-id') ?? $response->header('x-request-id');
            Log::error('Voyage embeddings API error', [
                'provider' => 'voyage', 'operation' => 'embeddings',
                'model' => preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', (string) config('services.voyage.model'))
                    ? config('services.voyage.model') : '[invalid]',
                'status' => $response->status(),
                'failure_code' => match (true) {
                    in_array($response->status(), [401, 403], true) => 'authentication',
                    $response->status() === 429 => 'rate_limit',
                    $response->serverError() => 'provider_error',
                    default => 'invalid_request',
                },
                'request_id' => is_string($requestId) && preg_match('/\A[a-zA-Z0-9._-]{1,120}\z/', $requestId) ? $requestId : null,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
            ]);
            $e = new EmbeddingProviderException;
            withScope(function (Scope $scope) use ($e) {
                $scope->setTag('provider', 'voyage');
                $scope->setTag('operation', 'embeddings');
                captureException($e);
            });
            throw $e;
        }

        $data = $response->json();
        // Usage is logged, never billed: embeddings have no per-document cost record, so this is the only trace of it.
        Log::info('Voyage embeddings usage', [
            'provider' => 'voyage', 'operation' => 'embeddings',
            'model' => preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', (string) ($data['model'] ?? config('services.voyage.model')))
                ? ($data['model'] ?? config('services.voyage.model')) : '[invalid]',
            'inputs' => count($texts),
            'total_tokens' => is_int($data['usage']['total_tokens'] ?? null) ? $data['usage']['total_tokens'] : null,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
        ]);

        return [
            'embeddings' => array_map(fn ($item) => $item['embedding'], $data['data']),
            'model' => $data['model'] ?? config('services.voyage.model'),
        ];
    }
}
