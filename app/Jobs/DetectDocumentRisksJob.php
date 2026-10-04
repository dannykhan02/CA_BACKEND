<?php

namespace App\Jobs;

use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Models\Document;
use App\Models\DocumentRisk;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectDocumentRisksJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments, GuardsDocumentIntelligence;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public string $documentId, public bool $forceReprocess = false) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $document = Document::find($this->documentId);

        if (! $document?->canGenerateIntelligence()) {
            return;
        }

        if ($this->skipIfUnchanged($document, 'risks', 'risks', $recorder)) {
            return;
        }

        $stage = $this->startIntelligence($document, 'risks', $recorder);
        if (! $stage || $this->abandonIntelligence($document, $stage)) {
            return;
        }

        try {
            $result = $client->detectDocumentRisks($document->extracted_text, $document->name, $document);
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

        DB::transaction(function () use ($document, $result) {
            Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $reviewedStatuses = $document->risks()->get()->keyBy(fn ($risk) => hash('sha256', $risk->title.'|'.$risk->evidence))->map->status;
            DocumentRisk::where('document_id', $document->id)->delete();

            foreach ($result['risks'] as $risk) {
                DocumentRisk::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'risk_type' => $risk['risk_type'] ?? null,
                    'title' => $risk['title'],
                    'description' => $risk['description'],
                    'severity' => $risk['severity'],
                    'confidence' => $risk['confidence'],
                    'evidence' => $risk['evidence'],
                    'status' => $reviewedStatuses[hash('sha256', $risk['title'].'|'.$risk['evidence'])] ?? 'open',
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => config('services.anthropic.model'),
                ]);
            }
        });

        $recorder->complete($stage, ['risk_count' => count($result['risks'])]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DetectDocumentRisksJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
