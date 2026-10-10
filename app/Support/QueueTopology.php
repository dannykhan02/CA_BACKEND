<?php

namespace App\Support;

use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\CompareDocumentsJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\OcrPageBatchJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Jobs\ProcessDocumentVisualJob;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Jobs\RetryDeferredBillingEvent;
use App\Jobs\ScanUploadedFileJob;
use App\Jobs\SendTrackedDeadlineReminder;
use App\Jobs\SynthesizeBriefNarrativeJob;

/**
 * The single job-to-queue map. Every dispatch site uses for(); the same map is
 * registered with Queue::route() so a dispatch that forgets onQueue() still lands
 * on the right pool. Each queue has its own Horizon supervisor.
 *
 * extraction: jobs that call Anthropic per document (chunks, legacy AI, OCR, vision, comparison)
 * synthesis:  completion work that must not wait behind extraction backlog (merge, summary)
 * default:    non-Anthropic work (scan, text parsing, visual planning, embeddings, billing, mail)
 */
final class QueueTopology
{
    public const EXTRACTION = 'extraction';

    public const SYNTHESIS = 'synthesis';

    public const DEFAULT = 'default';

    public const ROUTES = [
        ProcessDocumentChunkJob::class => self::EXTRACTION,
        ClassifyDocumentTypeJob::class => self::EXTRACTION,
        ExtractDocumentEntitiesJob::class => self::EXTRACTION,
        DetectDocumentRisksJob::class => self::EXTRACTION,
        DetectDocumentDeadlinesJob::class => self::EXTRACTION,
        GenerateInsightsJob::class => self::EXTRACTION,
        OcrPageBatchJob::class => self::EXTRACTION, // Claude Vision OCR is the default OCR provider.
        ProcessDocumentVisualJob::class => self::EXTRACTION,
        CompareDocumentsJob::class => self::EXTRACTION,

        MergeDocumentEvidenceJob::class => self::SYNTHESIS,
        GenerateDocumentSummaryJob::class => self::SYNTHESIS,
        SynthesizeBriefNarrativeJob::class => self::SYNTHESIS, // B2 narrative; runs after Ready.

        ScanUploadedFileJob::class => self::DEFAULT,
        ExtractDocumentTextJob::class => self::DEFAULT, // Parsing only; its token count is non-blocking.
        AnalyzeEmbeddedVisualsJob::class => self::DEFAULT, // Planning/rasterization; no provider call.
        GenerateEmbeddingsJob::class => self::DEFAULT, // Voyage, not Anthropic.
        RetryDeferredBillingEvent::class => self::DEFAULT,
        SendTrackedDeadlineReminder::class => self::DEFAULT,
        ResumeAfterCreditConfirmationJob::class => self::DEFAULT, // Resumes after a large-credit confirmation; reserves credits first.
    ];

    public static function for(string $job): string
    {
        return self::ROUTES[$job] ?? self::DEFAULT;
    }

    public static function queues(): array
    {
        return [self::EXTRACTION, self::SYNTHESIS, self::DEFAULT];
    }
}
