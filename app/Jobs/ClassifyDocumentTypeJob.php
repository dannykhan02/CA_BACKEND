<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentTypeClassification;
use App\Services\AI\AiModels;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyDocumentTypeJob implements ShouldQueue
{
    use Batchable, Dispatchable, GuardsDocumentIntelligence, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $documentId, public bool $forceReprocess = false, public ?string $queuedStageId = null) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        // Batched jobs still run even after the batch is cancelled unless
        // you check this — cancellation happens if you later call
        // $batch->cancel() (not currently done anywhere in this pipeline,
        // but this guard is the standard Batchable pattern and costs
        // nothing to have in place now).
        if ($this->batch()?->cancelled()) {
            return;
        }

        $document = Document::find($this->documentId);

        if (! $document?->canGenerateIntelligence()) {
            return;
        }

        if ($this->skipIfUnchanged($document, 'document_type', 'document_type', $recorder)) {
            return;
        }

        $stage = $this->startIntelligence($document, 'document_type', $recorder);
        if (! $stage || $this->abandonIntelligence($document, $stage)) {
            return;
        }

        try {
            $result = $client->classifyDocumentType($document->extracted_text, $document->name, $document);
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
            DocumentTypeClassification::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'workspace_id' => $document->workspace_id,
                    'document_type' => $result['document_type'],
                    'confidence' => $result['confidence'],
                    'reasoning' => $result['reasoning'],
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => app(AiModels::class)->forTask('document_type'),
                ]
            );
        }, [
            'document_type' => $result['document_type'],
            'confidence' => $result['confidence'],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeIntelligenceFailure('document_type', $e);
        Log::error('ClassifyDocumentTypeJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
