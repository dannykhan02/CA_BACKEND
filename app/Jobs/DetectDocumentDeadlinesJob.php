<?php

namespace App\Jobs;

use App\Exceptions\AiProcessingException;
use App\Jobs\Concerns\GuardsDocumentIntelligence;
use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentDeadline;
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

class DetectDocumentDeadlinesJob implements ShouldQueue
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

        if ($this->skipIfUnchanged($document, 'deadlines', 'deadlines', $recorder)) {
            return;
        }

        $stage = $this->startIntelligence($document, 'deadlines', $recorder);
        if (! $stage || $this->abandonIntelligence($document, $stage)) {
            return;
        }

        try {
            $result = $client->detectDocumentDeadlines($document->extracted_text, $document->name, $document);
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
            DocumentDeadline::where('document_id', $document->id)->where('intelligence_revision_id', $this->revisionId)->delete();

            foreach ($result['deadlines'] as $deadline) {
                $created = DocumentDeadline::create([
                    'intelligence_revision_id' => $this->revisionId,
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'deadline_type' => $deadline['deadline_type'] ?? null,
                    'title' => $deadline['title'],
                    'description' => $deadline['description'],
                    'due_date' => $deadline['due_date'] ?? null,
                    'date_type' => $deadline['date_type'],
                    'relative_text' => $deadline['relative_text'] ?? null,
                    'confidence' => $deadline['confidence'],
                    'evidence' => $deadline['evidence'],
                    'status' => 'open',
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => app(AiModels::class)->forTask('deadlines'),
                ]);
                app(\App\Services\Intelligence\NormalRouteProvenance::class)
                    ->record($document, 'deadline', (string) $created->id, $deadline);
            }
        }, ['deadline_count' => count($result['deadlines']),
            'deadlines_dropped' => array_sum($result['_optional_items_dropped'] ?? []),
            'deadline_drop_reasons' => $result['_optional_drop_reasons']['deadlines'] ?? [],
            'deadline_repairs' => $result['_deadline_repairs'] ?? []]);

        // Reasons and counts only; the deadline text itself is never logged.
        if (! empty($result['_optional_items_dropped']) || ! empty($result['_deadline_repairs'])) {
            Log::warning('Deadline validation dropped or repaired items', [
                'document_id' => $document->id,
                'kept' => count($result['deadlines']),
                'dropped' => $result['_optional_drop_reasons']['deadlines'] ?? [],
                'repaired' => $result['_deadline_repairs'] ?? [],
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeIntelligenceFailure('deadlines', $e);
        Log::error('DetectDocumentDeadlinesJob failed after retries', [
            'document_id' => $this->documentId,
            'failure_class' => $e instanceof AiProcessingException ? $e->classification : 'analysis_failure',
        ]);
    }
}
