<?php

namespace App\Jobs;

use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentTypeClassification;
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
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public string $documentId, public bool $forceReprocess = false) {}

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

        if (! $document || ! $document->extracted_text) {
            return;
        }

        if ($this->skipIfUnchanged($document, 'document_type', 'document_type', $recorder)) {
            return;
        }

        $stage = $recorder->start($document, 'document_type');

        try {
            $result = $client->classifyDocumentType($document->extracted_text, $document->name, $document);
        } catch (\Throwable $e) {
            $recorder->fail($stage, $e->getMessage());
            $this->fail($e);
            return;
        }

        DocumentTypeClassification::updateOrCreate(
            ['document_id' => $document->id],
            [
                'workspace_id' => $document->workspace_id,
                'document_type' => $result['document_type'],
                'confidence' => $result['confidence'],
                'reasoning' => $result['reasoning'],
                'prompt_version' => (string) $result['prompt_version'],
                'provider' => 'anthropic',
                'model' => config('services.anthropic.model'),
            ]
        );

        $recorder->complete($stage, [
            'document_type' => $result['document_type'],
            'confidence' => $result['confidence'],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ClassifyDocumentTypeJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}