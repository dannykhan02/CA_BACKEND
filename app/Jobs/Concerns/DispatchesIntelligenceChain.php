<?php

namespace App\Jobs\Concerns;

use App\Models\Document;
use Illuminate\Support\Facades\Bus;

/**
 * Dispatches the four-job AI intelligence batch (classification, entities,
 * risks, deadlines) plus the summary job that runs once they've all
 * settled. Shared by ExtractDocumentTextJob (native-text path) and
 * OcrPageBatchJob (OCR path's final batch) — both reach the same
 * "extracted_text is now set, kick off AI analysis" point, just via
 * different routes to get there.
 *
 * Was a chain (SomeJob::withChain([...])) with ->allowFailures() bolted
 * on — allowFailures() only exists on PendingBatch (Bus::batch()), not
 * PendingChain, so this fatally errored on every document. Switched to
 * a real batch so one stage failing (e.g. risks) doesn't cancel the
 * others (entities, deadlines), matching the intended
 * "classification ✓, entities ✓, risks ✗, deadlines pending" partial
 * state.
 *
 * GenerateDocumentSummaryJob is deliberately NOT in the batch array.
 * It reads the document_type/entities/risks/deadlines relations
 * written by these four jobs, and a batch gives no ordering guarantee
 * between its jobs — running it inside the batch risks a worker
 * picking it up before its siblings have written anything, silently
 * producing an incomplete summary instead of erroring. finally() runs
 * once, after every job in the batch has settled (success, failure,
 * or skip) — unlike then(), which only fires if nothing failed, which
 * would drop the summary entirely in the "risks ✗" partial-failure
 * case this pipeline is meant to support.
 */
trait DispatchesIntelligenceChain
{
    private function dispatchIntelligenceChain(Document $document): void
    {
        $documentId = $document->id;

        Bus::batch([
            new \App\Jobs\ClassifyDocumentTypeJob($documentId),
            new \App\Jobs\ExtractDocumentEntitiesJob($documentId),
            new \App\Jobs\DetectDocumentRisksJob($documentId),
            new \App\Jobs\DetectDocumentDeadlinesJob($documentId),
        ])
            ->name("document-intelligence:{$documentId}")
            ->onQueue('extraction')
            ->allowFailures()
            ->finally(function () use ($documentId) {
                \App\Jobs\GenerateDocumentSummaryJob::dispatch($documentId)
                    ->onQueue('extraction');
            })
            ->dispatch();
    }
}
