<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Pipeline\ProcessingStageReconciler;

/**
 * Aggregates persisted intelligence without calling Anthropic or dispatching
 * jobs. Status reads reconcile expired processing attempts, but never alter
 * extracted intelligence or billing records.
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
        app(ProcessingStageReconciler::class)->reconcile($document);
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

        if (($document->ai_pipeline['route'] ?? null) === 'incremental') {
            $chunks = DocumentChunk::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
                ->where('pipeline_key', $document->ai_pipeline['key'])->where('stage', 'extraction')->pluck('status');
            $state = $chunks->contains(fn ($s) => in_array($s, ['failed', 'uncertain', 'budget'])) ? 'failed'
                : ($chunks->contains(fn ($s) => in_array($s, ['pending', 'queued', 'running'])) ? 'processing' : 'completed');
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                $status[$stage] = $state;
            }
            $status['document_type'] = $document->documentTypeClassification()->exists() ? 'completed' : 'skipped';
            if ($status['document_summary'] === 'not_started') {
                $status['document_summary'] = $state === 'processing' ? 'pending' : 'failed';
            }
        }

        return $status;
    }

    public function processingDetails(Document $document): array
    {
        $units = DocumentChunk::where('document_id', $document->id)->where('workspace_id', $document->workspace_id);
        if (isset($document->ai_pipeline['key'])) {
            $units->where('pipeline_key', $document->ai_pipeline['key']);
        }
        $counts = $units->selectRaw('stage, status, count(*) as total')->groupBy('stage', 'status')->get();
        $stages = [];
        foreach ($counts as $count) {
            $stages[$count->stage][$count->status] = (int) $count->total;
        }
        $partial = (bool) ($document->ai_pipeline['partial'] ?? false);
        foreach ($stages as $states) {
            if (array_intersect(array_keys($states), ['failed', 'uncertain', 'budget'])) {
                $partial = true;
            }
        }

        return ['route' => $document->ai_pipeline['route'] ?? 'normal', 'partial' => $partial,
            'evidenceTrimmed' => (bool) ($document->ai_pipeline['evidence_trimmed'] ?? false), 'stages' => $stages];
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
