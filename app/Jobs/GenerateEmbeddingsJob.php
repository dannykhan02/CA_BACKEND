<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Services\Embeddings\TextChunker;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(public string $documentId) {}

    public function handle(TextChunker $chunker, VoyageEmbeddingClient $client, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);

        // Search is a value-add on top of a successfully processed document,
        // not a blocker for it — a document stuck at Failed/Needs Review
        // shouldn't have this job attempt anything, but it also must never
        // downgrade a document's status on failure the way extraction/AI
        // stages correctly do, since "can't search this yet" isn't the same
        // severity as "this document couldn't be processed at all."
        if (! $document || ! in_array($document->status, ['Ready'], true)) {
            return;
        }

        $text = Cache::get("document:{$document->id}:extracted_text");
        if (! $text) {
            Log::info("Embeddings skipped — extracted text cache expired for document {$document->id}, not re-extracting for a non-critical stage.");
            return;
        }

        $chunkStage = $recorder->start($document, 'chunk');

        $chunks = $chunker->chunk($text);
        if (empty($chunks)) {
            $recorder->complete($chunkStage, ['chunks' => 0]);
            return;
        }

        try {
            $result = $client->embed($chunks, 'document');
        } catch (\Throwable $e) {
            // Non-fatal by design — see status-gate comment above.
            $recorder->fail($chunkStage, $e->getMessage());
            return;
        }

        DB::transaction(function () use ($document, $chunks, $result) {
            // Replace, not accumulate — same reasoning as kpis()/charts()
            // delete-before-insert in GenerateInsightsJob: a reprocessed
            // document must not leave stale chunks from a prior version
            // searchable alongside the current ones.
            DocumentEmbedding::where('document_id', $document->id)->delete();

            foreach ($chunks as $index => $chunkText) {
                DocumentEmbedding::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'chunk_text' => $chunkText,
                    'provider' => 'voyage',
                    'model' => $result['model'],
                    'embedding' => '[' . implode(',', $result['embeddings'][$index]) . ']',
                ]);
            }
        });

        $recorder->complete($chunkStage, ['chunks' => count($chunks), 'model' => $result['model']]);
    }

    public function failed(\Throwable $e): void
    {
        // Deliberately no document status change — see handle()'s comment.
        Log::error('GenerateEmbeddingsJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
