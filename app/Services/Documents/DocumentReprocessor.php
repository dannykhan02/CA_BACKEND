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
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\DocumentIntelligenceService;
use App\Services\EntitlementService;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Support\QueueTopology;
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

    /** $confirmCredits: null = internal/operator call; an int (0 = none yet) = a customer request that must accept any large quote. */
    public function reprocess(Document $document, User $actor, bool $intelligenceOnly = false, ?string $requestedStage = null, ?int $confirmCredits = null): Document
    {
        $document->refresh();
        abort_if($document->status === 'Failed'
            && app(DocumentIntelligenceService::class)->getDocumentFailure($document)['recovery'] === 'replace',
            422, 'Replace or remove this document; its file cannot be processed.');

        if ($requestedStage !== null) {
            abort_unless($intelligenceOnly && in_array($requestedStage, [...array_keys(self::OPTIONAL_JOBS), 'document_summary'], true),
                422, 'Invalid intelligence stage.');
        }

        $revisions = app(DocumentRevisionPublisher::class);
        if ($revisions->enabled() && $intelligenceOnly) {
            abort(409, 'Section retry requires a new full intelligence revision.');
        }

        if (($document->ai_pipeline['route'] ?? null) === 'incremental'
            && $document->extracted_text && $document->status !== 'Failed') {
            app(AiCreditAdmission::class)->admitReanalysis($document, $requestedStage === 'document_summary', $actor->id, $confirmCredits);
            if ($revisions->enabled()) {
                $revision = $revisions->begin($document);
                $document->refresh()->forceFill(['status' => 'Processing', 'progress' => 50,
                    'error_message' => null, 'last_updated_by' => $actor->id])->save();
                app(IncrementalPipeline::class)->start($document->fresh());

                return $document->fresh();
            }
            app(IncrementalPipeline::class)->reanalyze($document, $actor->id, $requestedStage === 'document_summary');

            return $document->fresh();
        }

        if ($revisions->enabled()) {
            abort_unless(in_array($document->status, ['Ready', 'Failed', 'Needs Review'], true)
                || ($document->status === 'Processing' && $document->published_intelligence_revision_id !== null),
                422, 'This document cannot be reprocessed.');
            app(EntitlementService::class)->reserveDocument($document, true);
            $revision = $revisions->begin($document);
            $started = DB::transaction(function () use ($document, $actor, $revision, $revisions): bool {
                $locked = Document::whereKey($document->id)->lockForUpdate()->first();
                if (! $locked || ! $revisions->owns($locked, $revision->id)) {
                    return false;
                }
                $locked->forceFill(['status' => 'Processing', 'progress' => 0, 'extracted_text' => null,
                    'error_message' => null, 'last_updated_by' => $actor->id])->save();

                return true;
            });
            abort_unless($started, 409, 'A newer document processing run superseded this request.');
            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id, $revision->id))->onQueue(QueueTopology::for(ExtractDocumentTextJob::class)),
                (new GenerateInsightsJob($document->id, true, $revision->id))->onQueue(QueueTopology::for(GenerateInsightsJob::class)),
            ])->onQueue(QueueTopology::for(ScanUploadedFileJob::class))->dispatch($document->id, $revision->id);

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

        abort_unless($intelligenceOnly || in_array($document->status,
            $revisions->enabled() ? ['Failed', 'Needs Review', 'Ready'] : ['Failed', 'Needs Review'], true),
            422, 'Only Failed or Needs Review documents can be reprocessed.');

        // Optional analysis does not change document allowance. A core retry
        // retains the existing reservation/credit semantics.
        if (! $intelligenceOnly) {
            app(EntitlementService::class)->reserveDocument($document, true);
        }
        $revisionId = ! $intelligenceOnly ? $revisions->begin($document)?->id : null;

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
                    ->onQueue(QueueTopology::EXTRACTION)->allowFailures()->finally(function () use ($documentId, $attemptIds) {
                        if (Document::find($documentId)?->canGenerateIntelligence()) {
                            GenerateDocumentSummaryJob::dispatch($documentId, true, $attemptIds['document_summary'])->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class));
                        }
                    })->dispatch();
            } else {
                GenerateDocumentSummaryJob::dispatch($document->id, true, $attemptIds['document_summary'])->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class));
            }

            return $document->fresh();
        }

        if ($document->status === 'Failed') {
            // Failed prerequisites must run again even when old text remains.
            // Clear it so queued intelligence cannot start during the new scan.
            DB::transaction(function () use ($document, $actor, $revisionId) {
                if ($revisionId === null && ($document->ai_pipeline['route'] ?? null) === 'incremental' && isset($document->ai_pipeline['key'])) {
                    app(IncrementalPipeline::class)->reanalyze($document, $actor->id, dispatch: false);
                    $document->refresh();
                }
                $document->forceFill([
                    'status' => 'Processing', 'progress' => 0, 'extracted_text' => null,
                    'error_message' => null, 'last_updated_by' => $actor->id,
                ])->save();
            });

            ScanUploadedFileJob::withChain([
                (new ExtractDocumentTextJob($document->id, $revisionId))->onQueue(QueueTopology::for(ExtractDocumentTextJob::class)),
                (new GenerateInsightsJob($document->id, true, $revisionId))->onQueue(QueueTopology::for(GenerateInsightsJob::class)),
                (new GenerateEmbeddingsJob($document->id))->onQueue(QueueTopology::for(GenerateEmbeddingsJob::class)),
            ])->onQueue(QueueTopology::for(ScanUploadedFileJob::class))->dispatch($document->id, $revisionId);

            return $document->fresh();
        }

        // Review and intelligence-only retries reuse valid extracted text.
        if (! $intelligenceOnly) {
            $document->forceFill([
                'status' => 'Processing', 'progress' => 60, 'error_message' => null,
                'last_updated_by' => $actor->id,
            ])->save();
            GenerateInsightsJob::dispatch($document->id, true, $revisionId)->onQueue(QueueTopology::for(GenerateInsightsJob::class));
        }

        if (! $document->fresh()?->canGenerateIntelligence()) {
            return $document->fresh();
        }
        if ($revisionId !== null) {
            // GenerateInsightsJob dispatches the typed batch only after its candidate KPIs are committed.
            return $document->fresh();
        }

        $documentId = $document->id;

        Bus::batch([
            new ClassifyDocumentTypeJob($documentId, true, revisionId: $revisionId),
            new ExtractDocumentEntitiesJob($documentId, true, revisionId: $revisionId),
            new DetectDocumentRisksJob($documentId, true, revisionId: $revisionId),
            new DetectDocumentDeadlinesJob($documentId, true, revisionId: $revisionId),
        ])
            ->name("document-reprocess:{$documentId}")
            ->onQueue(QueueTopology::EXTRACTION)
            ->allowFailures()
            ->finally(function () use ($documentId, $revisionId) {
                if (! Document::find($documentId)?->canGenerateIntelligence()) {
                    return;
                }
                GenerateDocumentSummaryJob::dispatch($documentId, true, revisionId: $revisionId)
                    ->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class));
            })
            ->dispatch();

        return $document->fresh();
    }
}
