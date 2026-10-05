<?php

namespace App\Services\Documents;

use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\DocumentIntelligenceService;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class DocumentReprocessor
{
    private const OPTIONAL_JOBS = [
        'document_type' => ClassifyDocumentTypeJob::class,
        'entities' => ExtractDocumentEntitiesJob::class,
        'risks' => DetectDocumentRisksJob::class,
        'deadlines' => DetectDocumentDeadlinesJob::class,
    ];

    public function reprocess(Document $document, User $actor, bool $intelligenceOnly = false, ?string $requestedStage = null): Document
    {
        $document->refresh();
        abort_if($document->status === 'Failed'
            && app(DocumentIntelligenceService::class)->getDocumentFailure($document)['recovery'] === 'replace',
            422, 'Replace or remove this document; its file cannot be processed.');

        if ($requestedStage !== null) {
            abort_unless($intelligenceOnly && in_array($requestedStage, [...array_keys(self::OPTIONAL_JOBS), 'document_summary'], true),
                422, 'Invalid intelligence stage.');
        }

        if (($document->ai_pipeline['route'] ?? null) === 'incremental'
            && $document->extracted_text && $document->status !== 'Failed') {
            if ($requestedStage === 'document_summary') {
                GenerateDocumentSummaryJob::dispatch($document->id, true)->onQueue('extraction');

                return $document;
            }
            // Explicit user resume changes failed input, while retaining completed units.
            $document->forceFill(['status' => 'Processing', 'error_message' => null, 'last_updated_by' => $actor->id])->save();
            $pipeline = app(IncrementalPipeline::class);
            foreach (DocumentChunk::where('document_id', $document->id)
                ->where('pipeline_key', $document->ai_pipeline['key'])->where('stage', 'extraction')
                ->whereIn('status', ['failed', 'uncertain'])->get() as $chunk) {
                $pipeline->split($chunk, $document);
            }
            $pipeline->start($document);

            return $document->fresh();
        }

        if ($intelligenceOnly) {
            $states = app(DocumentIntelligenceService::class)->getProcessingStatus($document);
            abort_unless($document->status === 'Ready' && $document->extracted_text
                && count(array_intersect($states, ['failed', 'not_started'])) > 0, 422,
                'Only missing or failed intelligence on a processed document can be retried here.');
            if ($requestedStage !== null) {
                abort_unless(in_array($states[$requestedStage], ['failed', 'not_started'], true), 422,
                    'This analysis section does not need retrying.');
            }
        }

        abort_unless($intelligenceOnly || in_array($document->status, ['Failed', 'Needs Review'], true),
            422, 'Only Failed or Needs Review documents can be reprocessed.');

        // Optional analysis does not change document allowance. A core retry
        // retains the existing reservation/credit semantics.
        if (! $intelligenceOnly) {
            app(EntitlementService::class)->reserveDocument($document, true);
        }

        if ($intelligenceOnly) {
            $selected = $requestedStage !== null ? [$requestedStage] : array_keys(array_filter($states,
                fn ($status) => in_array($status, ['failed', 'not_started'], true)));
            $refreshSummary = count(array_intersect($selected, array_keys(self::OPTIONAL_JOBS))) > 0;
            // The summary is synthesized from the four extraction stages.
            // Refresh it once after those selected stages settle.
            if ($refreshSummary && ! in_array('document_summary', $selected, true)) {
                $selected[] = 'document_summary';
            }
            $attemptIds = DB::transaction(function () use ($document, $actor, $selected) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $current = app(DocumentIntelligenceService::class)->getProcessingStatus($locked);
                $attemptIds = [];
                foreach ($selected as $stage) {
                    abort_unless($stage === 'document_summary'
                        ? ! in_array($current[$stage], ['pending', 'processing'], true)
                        : in_array($current[$stage], ['failed', 'not_started'], true), 409,
                        'Analysis retry is already in progress or has completed.');
                    $attemptIds[$stage] = ProcessingJob::create(['workspace_id' => $locked->workspace_id,
                        'document_id' => $locked->id, 'stage' => $stage, 'status' => 'pending'])->id;
                }
                $locked->update(['last_updated_by' => $actor->id]);

                return $attemptIds;
            });
            $jobs = [];
            foreach ($selected as $stage) {
                if (isset(self::OPTIONAL_JOBS[$stage])) {
                    $jobs[] = new (self::OPTIONAL_JOBS[$stage])($document->id, true, $attemptIds[$stage]);
                }
            }
            if ($jobs) {
                $documentId = $document->id;
                Bus::batch($jobs)->name("document-intelligence-retry:{$documentId}")
                    ->onQueue('extraction')->allowFailures()->finally(function () use ($documentId, $attemptIds) {
                        if (Document::find($documentId)?->canGenerateIntelligence()) {
                            GenerateDocumentSummaryJob::dispatch($documentId, true, $attemptIds['document_summary'])->onQueue('extraction');
                        }
                    })->dispatch();
            } else {
                GenerateDocumentSummaryJob::dispatch($document->id, true, $attemptIds['document_summary'])->onQueue('extraction');
            }

            return $document->fresh();
        }

        if ($document->status === 'Failed') {
            // Failed prerequisites must run again even when old text remains.
            // Clear it so queued intelligence cannot start during the new scan.
            $document->forceFill([
                'status' => 'Processing',
                'progress' => 0,
                'extracted_text' => null,
                'error_message' => null,
                'last_updated_by' => $actor->id,
            ])->save();

            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id))->onQueue('extraction'),
                (new GenerateInsightsJob($document->id, true))->onQueue('extraction'),
                (new GenerateEmbeddingsJob($document->id))->onQueue('extraction'),
            ])->onQueue('default')->dispatch($document->id);

            return $document->fresh();
        }

        // Review and intelligence-only retries reuse valid extracted text.
        if (! $intelligenceOnly) {
            $document->forceFill([
                'status' => 'Processing', 'progress' => 60, 'error_message' => null,
                'last_updated_by' => $actor->id,
            ])->save();
            GenerateInsightsJob::dispatch($document->id, true)->onQueue('extraction');
        }

        if (! $document->fresh()?->canGenerateIntelligence()) {
            return $document->fresh();
        }

        $documentId = $document->id;

        Bus::batch([
            new ClassifyDocumentTypeJob($documentId, true),
            new ExtractDocumentEntitiesJob($documentId, true),
            new DetectDocumentRisksJob($documentId, true),
            new DetectDocumentDeadlinesJob($documentId, true),
        ])
            ->name("document-reprocess:{$documentId}")
            ->onQueue('extraction')
            ->allowFailures()
            ->finally(function () use ($documentId) {
                if (! Document::find($documentId)?->canGenerateIntelligence()) {
                    return;
                }
                GenerateDocumentSummaryJob::dispatch($documentId, true)
                    ->onQueue('extraction');
            })
            ->dispatch();

        return $document->fresh();
    }
}
