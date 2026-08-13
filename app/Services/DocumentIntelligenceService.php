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
            ->orderByDesc('created_at')
            ->get()
            ->unique('stage');

        $status = [];
        foreach (self::STAGES as $stage) {
            $job = $latestPerStage->firstWhere('stage', $stage);
            $status[$stage] = $job?->status ?? 'not_started';
        }

        return $status;
    }
}
