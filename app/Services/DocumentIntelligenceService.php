<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentIntelligenceRevision;
use App\Services\Pipeline\ProcessingStageReconciler;
use Illuminate\Database\Eloquent\Builder;

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
        $document = $this->publishedView($document);
        // Eloquent eager loading constructs relation queries on a fresh model instance.
        // That instance lacks the published view's transient read revision pin, so it
        // queries the active (or null) revision and silently returns empty relations.
        foreach (['documentTypeClassification', 'entities', 'risks', 'deadlines', 'intelligenceSummary', 'kpis'] as $relation) {
            if ($document->relationLoaded($relation)) {
                continue;
            }
            $query = $document->$relation();
            if ($relation === 'kpis' && $document->intelligenceRevisionForRelations() === null) {
                $query->where(fn ($q) => $q->where('workspace_id', $document->workspace_id)->orWhereNull('workspace_id'));
            } else {
                $query->where('workspace_id', $document->workspace_id);
            }
            $document->setRelation($relation, in_array($relation, ['documentTypeClassification', 'intelligenceSummary'], true)
                ? $query->first() : $query->get());
        }
        if ($document->ai_pipeline['summary_stale'] ?? false) {
            $document->setRelation('intelligenceSummary', null);
        }

        return $document;
    }

    /** A single pointer read pins every subsequent read to one immutable revision. */
    public function publishedView(Document $document): Document
    {
        if (array_key_exists('read_intelligence_revision_id', $document->getAttributes())) {
            return $document;
        }
        $id = $document->published_intelligence_revision_id;
        if ($id === null) {
            if ($document->active_intelligence_revision_id !== null || (int) $document->intelligence_generation > 0) {
                $view = clone $document;
                $view->unsetRelations();
                $state = $document->active_intelligence_revision_id !== null ? 'Processing'
                    : (in_array($document->status, ['Failed', 'Needs Review'], true)
                        ? $document->status : 'Needs Review');
                $view->forceFill(['read_intelligence_revision_id' => '00000000-0000-0000-0000-000000000000',
                    'extracted_text' => null, 'insights' => null, 'ai_pipeline' => null,
                    'status' => $state]);

                return $view;
            }

            return $document; // Historical unversioned document, never claimed coherent by V1.
        }
        $revision = DocumentIntelligenceRevision::whereKey($id)
            ->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
            ->where('state', 'published')->first();
        if ($revision === null || hash('sha256', (string) $revision->extracted_text) !== $revision->extracted_text_hash) {
            throw new \LogicException('Published intelligence revision is unavailable or corrupt.');
        }
        $view = clone $document;
        $view->unsetRelations();
        $view->forceFill(['read_intelligence_revision_id' => $revision->id,
            'intelligence_processing_status' => $document->status,
            'intelligence_processing_error' => $document->error_message,
            'extracted_text' => $revision->extracted_text,
            'ai_pipeline' => $revision->ai_pipeline,
            'insights' => $revision->insights,
            'has_structured_data' => $revision->has_structured_data,
            'pages' => $revision->pages,
            'status' => 'Ready', 'progress' => 100, 'error_message' => null]);

        return $view;
    }

    /** Pin a multi-document typed query to each document's own published pointer. */
    public function publishedRows(Builder $query, string $table): Builder
    {
        return $query->whereExists(function ($read) use ($table): void {
            $read->selectRaw('1')->from('documents as published_document')
                ->whereColumn('published_document.id', "{$table}.document_id")
                ->whereColumn('published_document.workspace_id', "{$table}.workspace_id")
                ->whereNull('published_document.deleted_at')
                ->where(function ($revision) use ($table): void {
                    $revision->where(function ($current) use ($table): void {
                        $current->whereColumn('published_document.published_intelligence_revision_id', "{$table}.intelligence_revision_id")
                            ->whereNotNull('published_document.published_intelligence_revision_id')
                            ->whereExists(function ($verified): void {
                                $verified->selectRaw('1')->from('document_intelligence_revisions as published_revision')
                                    ->whereColumn('published_revision.id', 'published_document.published_intelligence_revision_id')
                                    ->where('published_revision.state', 'published');
                            });
                    })->orWhere(function ($legacy) use ($table): void {
                        $legacy->whereNull('published_document.published_intelligence_revision_id')
                            ->where('published_document.intelligence_generation', 0)
                            ->whereNull("{$table}.intelligence_revision_id");
                    });
                });
        });
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
            ->where('intelligence_revision_id', $document->getAttribute('read_intelligence_revision_id')
                ?? $document->active_intelligence_revision_id)
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
            if ($document->ai_pipeline['summary_stale'] ?? false) {
                $status['document_summary'] = $document->status === 'Processing' ? 'pending' : 'failed';
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
            'evidenceTrimmed' => (bool) ($document->ai_pipeline['evidence_trimmed'] ?? false), 'stages' => $stages,
            'coverage' => $document->ai_pipeline['coverage'] ?? null,
            'synthesisCoverageWarning' => $document->ai_pipeline['synthesis_coverage_warning'] ?? null];
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
