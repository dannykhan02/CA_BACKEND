<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentRisk;
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

class DetectDocumentRisksJob implements ShouldQueue
{
    use Batchable, Dispatchable, GuardsDocumentIntelligence, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $documentId, public bool $forceReprocess = false, public ?string $queuedStageId = null, public ?string $revisionId = null) {}

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
            $recorder->fail($stage, 'Document analysis failed.');
            $this->fail($e);

            return;
        }

        if ($this->abandonIntelligence($document, $stage)) {
            return;
        }

        $this->persistIntelligence($document, $stage, $recorder, function () use ($document, $result) {
            $reviewedStatuses = $document->risks()->get()->keyBy(fn ($risk) => hash('sha256', $risk->title.'|'.$risk->evidence))->map->status;
            DocumentRisk::where('document_id', $document->id)->where('intelligence_revision_id', $this->revisionId)->delete();

            foreach ($result['risks'] as $risk) {
                $created = DocumentRisk::create([
                    'intelligence_revision_id' => $this->revisionId,
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
                    'model' => app(AiModels::class)->forTask('risks'),
                ]);
                app(\App\Services\Intelligence\NormalRouteProvenance::class)
                    ->record($document, 'risk', (string) $created->id, $risk);
            }
        }, ['risk_count' => count($result['risks'])]);
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeIntelligenceFailure('risks', $e);
        Log::error('DetectDocumentRisksJob failed after retries', [
            'document_id' => $this->documentId,
            'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'analysis_failure',
        ]);
    }
}
