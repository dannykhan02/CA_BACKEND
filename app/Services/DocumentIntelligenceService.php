<?php

namespace App\Services;

use App\Models\Document;

/**
 * Read-only aggregation over Day 3/4 extraction tables and the Day 6
 * grounded summary — does NOT call Anthropic, does NOT dispatch jobs,
 * does NOT write to entities/risks/deadlines/document_intelligence_summaries.
 * Purely reads what the existing extraction jobs and
 * GenerateDocumentSummaryJob already produced.
 */
class DocumentIntelligenceService
{
    private const STAGES = ['document_type', 'entities', 'risks', 'deadlines', 'document_summary'];

    public function loadIntelligence(Document $document): Document
    {
        return $document->loadMissing([
            'documentTypeClassification', 'entities', 'risks', 'deadlines', 'intelligenceSummary',
        ]);
    }

    /**
     * Latest status per intelligence stage from processing_jobs — reuses
     * existing infrastructure rather than inventing a parallel status
     * system. A stage with no processing_jobs row at all (never run) is
     * reported as 'not_started', distinct from a genuine DB status value.
     */
    public function getProcessingStatus(Document $document): array
    {
        $latestPerStage = $document->processingJobs()
            ->whereIn('stage', self::STAGES)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->unique('stage');

        $status = [];
        foreach (self::STAGES as $stage) {
            $job = $latestPerStage->firstWhere('stage', $stage);
            $status[$stage] = $document->status === 'Failed'
                && ! in_array($job?->status, ['completed', 'failed'], true)
                    ? 'blocked' : ($job?->status ?? 'not_started');
        }

        return $status;
    }

    /** Public, document-level failure metadata; never expose raw provider errors. */
    public function getDocumentFailure(Document $document): ?array
    {
        if ($document->status !== 'Failed') {
            return null;
        }

        $stage = $document->processingJobs()
            ->whereNotIn('stage', self::STAGES)->where('status', 'failed')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $message = $document->error_message ?: 'Document processing could not be completed.';
        // Legacy documents store the public failure reason in error_message.
        // Conservatively require replacement for files known to be unusable.
        $replace = preg_match('/corrupt|password.protected|unsupported|malware scan and was not processed|no extractable text found in this document/i', $message) === 1
            || str_starts_with($stage?->error_message ?? '', 'MALWARE_FOUND:');

        return [
            'kind' => $stage?->stage === 'ai_analysis' ? 'analysis' : 'processing',
            'stage' => $stage?->stage,
            'message' => $message,
            'recovery' => $replace ? 'replace' : 'retry_processing',
        ];
    }
}
