<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentEntity;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractDocumentEntitiesJob implements ShouldQueue
{
    use Batchable, Dispatchable, GuardsDocumentIntelligence, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;

    public int $timeout = 330;

    public bool $failOnTimeout = true;

    public function __construct(public string $documentId, public bool $forceReprocess = false, public ?string $queuedStageId = null) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $document = Document::find($this->documentId);

        if (! $document?->canGenerateIntelligence()) {
            return;
        }

        if ($this->skipIfUnchanged($document, 'entities', 'entities', $recorder)) {
            return;
        }

        $stage = $this->startIntelligence($document, 'entities', $recorder);
        if (! $stage || $this->abandonIntelligence($document, $stage)) {
            return;
        }

        try {
            $result = $client->extractDocumentEntities($document->extracted_text, $document->name, $document);
        } catch (\Throwable $e) {
            if ($this->abandonIntelligence($document, $stage)) {
                return;
            }
            $recorder->fail($stage, $e->getMessage());
            $this->fail($e);

            return;
        }

        if ($this->abandonIntelligence($document, $stage)) {
            return;
        }

        $this->persistIntelligence($document, $stage, $recorder, function () use ($document, $result) {
            // Delete-before-insert, same pattern as GenerateInsightsJob's
            // kpis/charts — a reprocessed document must not accumulate
            // stale entities alongside fresh ones.
            DocumentEntity::where('document_id', $document->id)->delete();

            foreach ($result['entities'] as $entity) {
                DocumentEntity::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'entity_type' => $entity['entity_type'],
                    'value' => $entity['value'],
                    'normalized_value' => $entity['normalized_value'] ?? $entity['value'],
                    'confidence' => $entity['confidence'],
                    'context' => $entity['context'] ?? null,
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => config('services.anthropic.model'),
                ]);
            }
        }, ['entity_count' => count($result['entities'])]);
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeIntelligenceFailure('entities', $e);
        Log::error('ExtractDocumentEntitiesJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
