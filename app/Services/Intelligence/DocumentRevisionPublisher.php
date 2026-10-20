<?php

namespace App\Services\Intelligence;

use App\Exceptions\RevisionPublicationRefused;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Services\WorkspaceCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Candidate construction happens outside the short, pointer-only publication transaction. */
class DocumentRevisionPublisher
{
    public function enabled(): bool
    {
        return (bool) config('document_intelligence.revision_v1', false);
    }

    public function begin(Document $document): ?DocumentIntelligenceRevision
    {
        if (! $this->enabled()) {
            return null;
        }

        return DB::transaction(function () use ($document): DocumentIntelligenceRevision {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->workspace_id !== $document->workspace_id) {
                throw new \LogicException('Document revision owner is unavailable.');
            }
            $generation = (int) $locked->intelligence_generation + 1;
            if ($locked->active_intelligence_revision_id) {
                DocumentIntelligenceRevision::whereKey($locked->active_intelligence_revision_id)
                    ->whereIn('state', ['candidate', 'ready'])->update(['state' => 'superseded']);
            }
            $revision = DocumentIntelligenceRevision::create([
                'workspace_id' => $locked->workspace_id,
                'document_id' => $locked->id,
                'generation' => $generation,
                'state' => 'candidate',
                'file_hash' => $locked->file_hash,
            ]);
            $locked->forceFill(['intelligence_generation' => $generation,
                'active_intelligence_revision_id' => $revision->id])->save();

            return $revision;
        });
    }

    public function owns(Document $document, ?string $revisionId): bool
    {
        if ($document->active_intelligence_revision_id === null) {
            return ! $this->enabled() && $revisionId === null;
        }

        return $revisionId !== null && $document->active_intelligence_revision_id === $revisionId
            && DocumentIntelligenceRevision::whereKey($revisionId)
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('generation', $document->intelligence_generation)->where('state', 'candidate')->exists();
    }

    public function ownsPipeline(Document $document, string $pipelineKey): bool
    {
        if ($document->active_intelligence_revision_id === null) {
            return ! $this->enabled();
        }

        return DocumentIntelligenceRevision::whereKey($document->active_intelligence_revision_id)
            ->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
            ->where('generation', $document->intelligence_generation)->where('state', 'candidate')
            ->where('pipeline_key', $pipelineKey)->exists();
    }

    public function normalStagesComplete(Document $document, string $revisionId): bool
    {
        $revision = DocumentIntelligenceRevision::whereKey($revisionId)
            ->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->first();
        if (! $revision || $revision->state !== 'candidate') {
            return false;
        }
        $required = ['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines'];
        $rows = DB::table('processing_jobs')->where('document_id', $document->id)
            ->where('intelligence_revision_id', $revisionId)->whereIn('stage', $required)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $states = [];
        foreach ($rows as $row) {
            $states[$row->stage] ??= $row->status;
        }

        return count(array_filter($required, fn ($stage) => ($states[$stage] ?? null) === 'completed')) === count($required);
    }

    /**
     * All required candidate artifacts must already be committed before this call.
     *
     * Retried: taking the credit here means this transaction holds the document lock and then the
     * workspace lock, so two documents of one workspace publishing at the same instant can deadlock
     * on the workspace row. A deadlock aborts one side, and re-running it succeeds — the work is
     * idempotent, because an already-published revision returns early and the charge is claimed once.
     */
    public function publish(Document $document, string $revisionId): DocumentIntelligenceRevision
    {
        try {
            return $this->commit($document, $revisionId);
        } catch (RevisionPublicationRefused $refusal) {
            // The transaction that refused has rolled back, so the condition is recorded here.
            Log::warning('Document revision publication refused', ['document_id' => $refusal->documentId,
                'revision_id' => $refusal->revisionId, 'generation' => $refusal->generation,
                'condition' => $refusal->condition]);
            DocumentIntelligenceRevision::whereKey($refusal->revisionId)->where('state', 'candidate')
                ->update(['publication_reason' => $refusal->condition]);

            throw $refusal;
        }
    }

    private function commit(Document $document, string $revisionId): DocumentIntelligenceRevision
    {
        return DB::transaction(function () use ($document, $revisionId): DocumentIntelligenceRevision {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $revision = DocumentIntelligenceRevision::whereKey($revisionId)->lockForUpdate()->firstOrFail();
            if ($locked->published_intelligence_revision_id === $revisionId && $revision->state === 'published') {
                return $revision;
            }
            if (! $this->owns($locked, $revisionId) || $locked->trashed()
                || ! in_array($locked->status, ['Processing', 'Ready'], true)) {
                $this->refuse($revision, 'owner_rejected', 'Revision no longer owns document processing.');
            }
            if (! is_string($revision->file_hash) || $revision->file_hash === ''
                || $revision->file_hash !== $locked->file_hash
                || trim((string) $locked->extracted_text) === '') {
                $this->refuse($revision, 'source_unavailable', 'Revision source or text is unavailable.');
            }
            $textHash = hash('sha256', $locked->extracted_text);
            $pipeline = $locked->ai_pipeline ?? [];
            if (! in_array($pipeline['route'] ?? null, ['normal', 'incremental'], true)) {
                $this->refuse($revision, 'no_pipeline_route', 'Revision has no validated pipeline route.');
            }
            $pipelineKey = $pipeline['key'] ?? null;
            if (($pipeline['route'] ?? null) === 'incremental' && ! is_string($pipelineKey)) {
                $this->refuse($revision, 'no_pipeline_identity', 'Incremental revision has no pipeline identity.');
            }
            if (($pipeline['route'] ?? null) === 'incremental') {
                $evidenceReady = DB::table('document_chunks')->where('document_id', $locked->id)
                    ->where('pipeline_key', $pipelineKey)->where('stage', 'merge')->where('status', 'completed')->exists();
                $set = DB::table('source_provenance_sets')->where('document_id', $locked->id)
                    ->where('workspace_id', $locked->workspace_id)->where('pipeline_key', $pipelineKey)
                    ->where('source_revision', $textHash)->where('published', true)->first();
                if (! $evidenceReady || ! $set) {
                    $this->refuse($revision, 'evidence_incomplete', 'Evidence or provenance stage is incomplete.');
                }
            } else {
                $set = is_string($pipelineKey) ? DB::table('source_provenance_sets')
                    ->where('document_id', $locked->id)->where('workspace_id', $locked->workspace_id)
                    ->where('pipeline_key', $pipelineKey)->where('source_revision', $textHash)
                    ->where('policy_version', SourceProvenance::POLICY)
                    ->where('published', true)->first() : null;
            }
            $required = ['ai_analysis', 'entities', 'risks', 'deadlines', 'document_summary'];
            if (($pipeline['route'] ?? null) !== 'incremental') {
                $required[] = 'document_type';
            }
            $stageRows = DB::table('processing_jobs')->where('document_id', $locked->id)
                ->where('intelligence_revision_id', $revisionId)
                ->whereIn('stage', $required)->orderByDesc('created_at')->orderByDesc('id')->get();
            $stageStates = [];
            foreach ($stageRows as $stageRow) {
                $stageStates[$stageRow->stage] ??= $stageRow->status;
            }
            foreach ($required as $stage) {
                if (($stageStates[$stage] ?? null) !== 'completed') {
                    $this->refuse($revision, 'stage:'.$stage, 'Required intelligence stage is incomplete: '.$stage);
                }
            }
            if (! DB::table('document_intelligence_summaries')->where('document_id', $locked->id)
                ->where('intelligence_revision_id', $revisionId)->exists()) {
                $this->refuse($revision, 'summary_missing', 'Typed intelligence summary is incomplete.');
            }
            $structure = DB::table('structured_extractions')->where('document_id', $locked->id)
                ->where('source_hash', $locked->file_hash)->where('text_hash', $textHash)
                ->where('active', true)->first(['id', 'parser_version', 'policy_version']);
            $previous = $locked->published_intelligence_revision_id;
            $revision->forceFill([
                'state' => 'published', 'extracted_text' => $locked->extracted_text,
                'extracted_text_hash' => $textHash, 'pipeline_key' => $pipelineKey,
                'parser_version' => $structure?->parser_version, 'structure_policy' => $structure?->policy_version,
                'structured_extraction_id' => $structure?->id,
                'provenance_policy' => $set?->policy_version ?? 'textual.compatibility.v1',
                'provenance_set_id' => $set?->id,
                'ai_pipeline' => $pipeline, 'insights' => $locked->insights,
                'has_structured_data' => $locked->has_structured_data,
                'pages' => $locked->pages,
                'previous_published_revision_id' => $previous,
                'publication_reason' => $previous === null ? 'initial' : 'reprocess',
                'ready_at' => now(), 'published_at' => now(),
            ])->save();
            $locked->forceFill(['published_intelligence_revision_id' => $revisionId,
                'active_intelligence_revision_id' => null, 'status' => 'Ready', 'progress' => 100])->save();
            // The charge point (docs/billing-credits.md): a published revision is the delivered
            // product, so the credit is taken here and nowhere earlier. The debit shares this
            // transaction and this document lock, so a publication is never charged without
            // publishing and never published without being charged.
            app(WorkspaceCreditService::class)->accountForReadyDocument($locked);
            Log::info('Document intelligence revision published', ['document_id' => $locked->id,
                'old_revision_id' => $previous, 'new_revision_id' => $revisionId,
                'generation' => $revision->generation]);

            return $revision;
        }, 3);
    }

    /** Refusing to publish is never silent: $condition says which one, on the revision and in the log. */
    public function fail(Document $document, string $revisionId, string $condition): void
    {
        // publication_reason is varchar(32): a long provider failure class must not turn a
        // recorded failure into a write error on the path that is reporting one.
        $condition = substr($condition, 0, 32);
        DB::transaction(function () use ($document, $revisionId, $condition): void {
            $locked = Document::whereKey($document->id)->lockForUpdate()->first();
            if (! $locked || $locked->active_intelligence_revision_id !== $revisionId) {
                return;
            }
            $candidate = DocumentIntelligenceRevision::whereKey($revisionId)->where('state', 'candidate')->first();
            DocumentIntelligenceRevision::whereKey($revisionId)->where('state', 'candidate')
                ->update(['state' => 'failed', 'failed_at' => now(), 'publication_reason' => $condition]);
            $locked->forceFill(['active_intelligence_revision_id' => null])->save();
            if ($candidate) {
                Log::warning('Document intelligence revision failed', ['document_id' => $locked->id,
                    'revision_id' => $revisionId, 'generation' => $candidate->generation,
                    'condition' => $condition]);
            }
        });
    }

    /** @throws RevisionPublicationRefused */
    private function refuse(DocumentIntelligenceRevision $revision, string $condition, string $message): never
    {
        throw new RevisionPublicationRefused($message, $condition, $revision->document_id,
            $revision->id, (int) $revision->generation);
    }
}
