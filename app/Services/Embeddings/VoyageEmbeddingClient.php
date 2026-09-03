<?php

namespace App\Services\Embeddings;

use App\Exceptions\EmbeddingProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;
use function Sentry\withScope;
use function Sentry\captureException;

class VoyageEmbeddingClient
{
    public function embed(array $texts, string $inputType = 'document'): array
    {
        if (empty($texts)) {
            return ['embeddings' => [], 'model' => config('services.voyage.model')];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.voyage.api_key'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post('https://api.voyageai.com/v1/embeddings', [
                'input' => $texts,
                'model' => config('services.voyage.model'),
                'input_type' => $inputType,
            ]);

        if ($response->failed()) {
            Log::error('Voyage embeddings API error', ['status' => $response->status(), 'body' => $response->body()]);
            $e = new EmbeddingProviderException();
            withScope(function (Scope $scope) use ($e) {
                $scope->setTag('provider', 'voyage');
                $scope->setTag('operation', 'embeddings');
                captureException($e);
            });
            throw $e;
        }

        $data = $response->json();

        return [
            'embeddings' => array_map(fn ($item) => $item['embedding'], $data['data']),
            'model' => $data['model'] ?? config('services.voyage.model'),
        ];
    }
}
