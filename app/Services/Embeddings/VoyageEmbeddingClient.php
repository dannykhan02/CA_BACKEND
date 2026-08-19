<?php

namespace App\Services\Embeddings;

use App\Exceptions\EmbeddingProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoyageEmbeddingClient
{
    /**
     * @param string[] $texts batched in a single request — Voyage bills and
     *   rate-limits per request, so batching chunks from one document into
     *   one call is meaningfully cheaper than one call per chunk.
     * @return array{embeddings: float[][], model: string}
     */
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
                'input_type' => $inputType, // 'document' when indexing, 'query' when searching
            ]);

        if ($response->failed()) {
            // Full status/body logged here for internal debugging only —
            // this must never reach the client. The thrown exception below
            // carries a generic message on purpose; bootstrap/app.php maps
            // EmbeddingProviderException to a clean 503 so upstream status
            // codes, URLs, and response bodies never leak into the API
            // response (audit finding — previously this exact status/body
            // was reaching clients verbatim via the default error renderer).
            Log::error('Voyage embeddings API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new EmbeddingProviderException();
        }

        $data = $response->json();

        return [
            'embeddings' => array_map(fn ($item) => $item['embedding'], $data['data']),
            'model' => $data['model'] ?? config('services.voyage.model'),
        ];
    }
}