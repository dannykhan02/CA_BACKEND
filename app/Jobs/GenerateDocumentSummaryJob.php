<?php

namespace App\Jobs;

use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentIntelligenceSummary;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Grounded document-level summary — feeds Claude the ALREADY-EXTRACTED
 * structured data (entities/risks/deadlines/document_type), not the raw
 * document text. This is deliberate: the summary must be traceable to
 * verified extraction results, not a fresh independent read of the
 * document that could disagree with what entities/risks/deadlines jobs
 * already found. No new facts can enter here that didn't already pass
 * through the Day 3/4 validators.
 *
 * Dispatched from the intelligence batch's finally() callback rather than
 * included in the batch array itself — it reads relations written by its
 * four siblings, and finally() only fires once all of them have settled,
 * which a batch's own internal ordering cannot guarantee. Because it's
 * dispatched standalone (not inside Bus::batch([...])), $this->batch()
 * will always be null here — Batchable is kept for consistency with the
 * rest of the pipeline, not because this job is tracked by a batch.
 */
class GenerateDocumentSummaryJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public string $documentId, public bool $forceReprocess = false) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        if ($this->skipIfUnchanged($document, 'document_summary', 'document_summary', $recorder)) {
            return;
        }

        $document->loadMissing(['documentTypeClassification', 'entities', 'risks', 'deadlines']);

        $extractedData = [
            'document_type' => $document->documentTypeClassification?->document_type,
            'entities' => $document->entities->map(fn ($e) => [
                'type' => $e->entity_type, 'value' => $e->value,
            ])->toArray(),
            'risks' => $document->risks->map(fn ($r) => [
                'title' => $r->title, 'severity' => $r->severity, 'description' => $r->description,
            ])->toArray(),
            'deadlines' => $document->deadlines->map(fn ($d) => [
                'title' => $d->title, 'date_type' => $d->date_type,
                'due_date' => $d->due_date?->toDateString(), 'relative_text' => $d->relative_text,
            ])->toArray(),
        ];

        $stage = $recorder->start($document, 'document_summary');

        try {
            $result = $client->generateDocumentSummary(json_encode($extractedData), $document->name, $document);
        } catch (\Throwable $e) {
            $recorder->fail($stage, $e->getMessage());
            $this->fail($e);
            return;
        }

        DocumentIntelligenceSummary::updateOrCreate(
            ['document_id' => $document->id],
            [
                'workspace_id' => $document->workspace_id,
                'executive_summary' => $result['executive_summary'],
                'key_findings' => $result['key_findings'],
                'critical_risks' => $result['critical_risks'],
                'upcoming_deadlines' => $result['upcoming_deadlines'],
                'important_entities' => $result['important_entities'],
                'recommended_attention' => $result['recommended_attention'],
                'prompt_version' => (string) $result['prompt_version'],
                'provider' => 'anthropic',
                'model' => config('services.anthropic.model'),
            ]
        );

        $recorder->complete($stage, ['generated' => true]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateDocumentSummaryJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}