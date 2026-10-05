<?php

namespace App\Services\AI\Incremental;

use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\MergeDocumentEvidenceJob;
use App\Jobs\ProcessDocumentChunkJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Services\AI\AiModels;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\DB;

class IncrementalPipeline
{
    public function __construct(
        private ChunkPlanner $planner,
        private AnthropicClient $client
    ) {}

    public function route(Document $document): bool
    {
        if (
            ! config('document_intelligence.incremental')
            || ! $document->canGenerateIntelligence()
        ) {
            return false;
        }

        $text = $document->extracted_text;
        $hash = hash('sha256', $text);
        $model = app(AiModels::class)->forTask('extraction');

        $metadata = $document->ai_pipeline ?? [];

        if (
            ($metadata['text_hash'] ?? null) !== $hash
            || ($metadata['model'] ?? null) !== $model
        ) {
            /*
             * Byte count is only a cheap upper-bound preflight.
             * If the document looks large, ask Anthropic for the real count.
             */
            $method = 'byte_upper_bound';
            $tokens = strlen($text);

            if ($tokens > config('document_intelligence.large_tokens')) {
                try {
                    $tokens = $this->client->countTokens($text, $model);
                    $method = 'anthropic';
                } catch (\Throwable) {
                    $tokens = $this->planner->estimate($text);
                    $method = 'estimated';
                }
            }

            $metadata = [
                'text_hash' => $hash,
                'model' => $model,
                'tokens' => $tokens,
                'count_method' => $method,
            ];
        }

        /*
         * Avoid the legacy character truncation even if token density happens
         * to be unusually low.
         */
        $large =
            $metadata['tokens'] > config('document_intelligence.large_tokens')
            || mb_strlen($text) > config('document_processing.max_extraction_chars');

        $metadata['route'] = $large ? 'incremental' : 'normal';

        $document->forceFill([
            'ai_pipeline' => $metadata,
        ])->save();

        if (! $large) {
            return false;
        }

        $this->start($document);

        return true;
    }

    public function start(Document $document): void
    {
        $version = (string) config('document_intelligence.pipeline_version');
        $prompt = (string) config('document_intelligence.prompt_version');

        $key = hash(
            'sha256',
            implode('|', [
                $document->workspace_id,
                $document->id,
                hash('sha256', $document->extracted_text),
                $version,
                $prompt,
                app(AiModels::class)->forTask('extraction'),
            ])
        );

        /*
         * Existing pipeline:
         * do not create the root chunks again.
         */
        if (
            ($document->ai_pipeline['key'] ?? null) === $key
            && DocumentChunk::where('document_id', $document->id)
                ->where('pipeline_key', $key)
                ->where('stage', 'extraction')
                ->exists()
        ) {
            $this->pump($document->id);

            return;
        }

        $density =
            ($document->ai_pipeline['count_method'] ?? null) === 'anthropic'
                ? $document->ai_pipeline['tokens']
                    / max(1, strlen($document->extracted_text))
                : 1 / 3;

        $plan = $this->planner->plan(
            $document->extracted_text,
            tokensPerByte: $density
        );

        DB::transaction(function () use (
            $document,
            $key,
            $plan,
            $version,
            $prompt
        ) {
            $locked = Document::whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canGenerateIntelligence()) {
                return;
            }

            $pageMapKnown =
                $locked->type === 'PDF'
                && substr_count($locked->extracted_text, "\f") + 1
                    === (int) $locked->pages;

            foreach ($plan as $index => $range) {
                if (! $pageMapKnown) {
                    $range['start_page'] = null;
                    $range['end_page'] = null;
                }

                DocumentChunk::firstOrCreate(
                    [
                        'document_id' => $locked->id,
                        'pipeline_key' => $key,
                        'identity' => 'chunk:'.$index,
                    ],
                    $range + [
                        'workspace_id' => $locked->workspace_id,
                        'pipeline_version' => $version,
                        'prompt_version' => $prompt,
                    ]
                );
            }

            $metadata = $locked->ai_pipeline ?? [];

            $metadata['key'] = $key;
            $metadata['route'] = 'incremental';
            $metadata['recovery_complete'] = false;
            $metadata['pipeline_version'] = $version;
            $metadata['prompt_version'] = $prompt;

            $metadata['budget_usd'] = min(
                config('document_intelligence.budget_max_usd'),
                config('document_intelligence.budget_base_usd')
                    + (
                        $metadata['tokens']
                        ?? $this->planner->estimate($locked->extracted_text)
                    ) / 1000
                    * config(
                        'document_intelligence.budget_per_1000_tokens_usd'
                    )
            );

            $metadata = [...$metadata, ...$this->client->synthesisReservation($locked), 'synthesis' => 'pending'];

            $locked->forceFill([
                'ai_pipeline' => $metadata,
            ])->save();
        });

        $this->pump($document->id);
    }

    /** Caller holds the document lock, serializing all paid stage admissions. */
    public function canReserve(Document $document, ?float $cost, bool $synthesis = false): bool
    {
        if ($cost === null) {
            return false;
        }
        $protected = 0;
        if (($document->ai_pipeline['route'] ?? null) === 'incremental' && ! $synthesis
            && ($document->ai_pipeline['synthesis'] ?? null) !== 'completed') {
            if (! array_key_exists('synthesis_reserved_usd', $document->ai_pipeline)) {
                $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
                    ...$this->client->synthesisReservation($document)]])->save();
            }
            if ($document->ai_pipeline['synthesis_reserved_usd'] === null || $document->ai_pipeline['repair_reserved_usd'] === null) {
                return false;
            }
            $protected = $document->ai_pipeline['synthesis_reserved_usd'] + $document->ai_pipeline['repair_reserved_usd'];
        }
        $committed = DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])->sum('reserved_cost');

        return round($committed + $cost + $protected, 6) <= ($document->ai_pipeline['budget_usd'] ?? 0);
    }

    /** reserved_cost retains settled spend plus the one active attempt's upper bound. */
    public function reserveCost(DocumentChunk $unit, float $cost): void
    {
        $unit->update(['status' => 'running', 'started_at' => now(), 'attempts' => $unit->attempts + 1,
            'reserved_cost' => round($unit->reserved_cost + $cost, 6),
            'cost_accounting' => ['attempt' => $unit->attempts + 1, 'previous_cost' => (float) $unit->reserved_cost,
                'estimate' => $cost, 'settled' => false]]);
    }

    /** Idempotent settlement. Unknown/time-limited requests retain their conservative commitment. */
    public function settleCost(DocumentChunk $unit, bool $providerCalled = true): void
    {
        DB::transaction(function () use ($unit, $providerCalled) {
            Document::whereKey($unit->document_id)->lockForUpdate()->firstOrFail();
            $locked = DocumentChunk::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $accounting = $locked->cost_accounting;
            if (! $accounting || $accounting['settled'] || $accounting['attempt'] !== (int) $unit->attempts) {
                return;
            }
            $runs = DocumentAiRun::where('chunk_id', $locked->id)->where('request_attempt', $accounting['attempt'])->get();
            $known = $runs->isNotEmpty() && $runs->every(fn ($run) => $run->estimated_cost_usd !== null);
            $cost = ! $providerCalled ? 0 : ($known ? (float) $runs->sum('estimated_cost_usd') : $accounting['estimate']);
            $locked->update(['reserved_cost' => round($accounting['previous_cost'] + $cost, 6),
                'cost_accounting' => [...$accounting, 'settled' => true, 'actual_known' => $known || ! $providerCalled, 'cost' => $cost]]);
        });
    }

    /** Explicit user retry: retain evidence/cost history and invalidate dependent checkpoints. */
    public function reanalyze(Document $document, string $actorId, bool $summaryOnly = false, bool $dispatch = true): void
    {
        DB::transaction(function () use ($document, $actorId, $summaryOnly) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $units = fn () => DocumentChunk::where('document_id', $locked->id)->where('pipeline_key', $locked->ai_pipeline['key']);
            abort_if($locked->status === 'Processing' || $units()->whereIn('status', ['pending', 'queued', 'running'])->exists(),
                409, 'Analysis is already in progress.');
            if (! $summaryOnly) {
                // No recursive splitting of invalid evidence. Only explicit user requests reopen failures.
                $units()->where('stage', 'extraction')->whereIn('status', ['failed', 'uncertain', 'budget'])
                    ->update(['status' => 'pending', 'failure_class' => null, 'completed_at' => null]);
                $units()->where('stage', 'merge')->update(['status' => 'pending', 'failure_class' => null, 'completed_at' => null]);
                $units()->where('stage', 'context')->update(['status' => 'superseded']);
            }
            $units()->where('stage', 'synthesis')->update(['status' => 'superseded']);
            $locked->forceFill(['status' => 'Processing', 'progress' => $summaryOnly ? 95 : 50,
                'error_message' => null, 'last_updated_by' => $actorId,
                'ai_pipeline' => [...$locked->ai_pipeline, ...$this->client->synthesisReservation($locked),
                    'analysis_revision' => ($locked->ai_pipeline['analysis_revision'] ?? 0) + 1,
                    'synthesis_reductions' => 0, 'synthesis' => 'pending', 'summary_stale' => true, 'recovery_complete' => false]])->save();
        });
        if (! $dispatch) {
            return;
        }
        if ($summaryOnly) {
            GenerateDocumentSummaryJob::dispatch($document->id, true)->onQueue('extraction');
        } else {
            $this->pump($document->id);
        }
    }

    /**
     * Keep at most N outstanding extraction jobs per document.
     * Workers never wait synchronously for child chunks.
     */
    public function pump(string $documentId): void
    {
        DB::transaction(function () use ($documentId) {
            $document = Document::whereKey($documentId)
                ->lockForUpdate()
                ->first();

            if (! $document?->canGenerateIntelligence()) {
                return;
            }

            $key = $document->ai_pipeline['key'] ?? null;

            if (! $key) {
                return;
            }

            $query = fn () => DocumentChunk::where(
                'document_id',
                $documentId
            )
                ->where('pipeline_key', $key)
                ->where('stage', 'extraction');

            /*
             * Split parents are not leaves and therefore do not count toward
             * progress.
             */
            $leafCount = $query()
                ->where('status', '!=', 'split')
                ->count();

            if ($document->status === 'Processing' && $leafCount) {
                $completedCount = $query()
                    ->where('status', 'completed')
                    ->count();

                $document->forceFill([
                    'progress' => 50 + (int) (
                        35 * $completedCount / $leafCount
                    ),
                ])->save();
            }

            /*
             * Bounded fan-out.
             */
            $active = $query()
                ->whereIn('status', ['queued', 'running'])
                ->count();

            $slots = max(
                0,
                (int) config('document_intelligence.concurrency') - $active
            );

            foreach (
                $query()
                    ->where('status', 'pending')
                    ->orderBy('start_offset')
                    ->limit($slots)
                    ->get() as $chunk
            ) {
                $chunk->update([
                    'status' => 'queued',
                ]);

                ProcessDocumentChunkJob::dispatch($chunk->id)
                    ->onQueue('extraction')
                    ->afterCommit();
            }

            /*
             * Do not merge until all extraction leaves have reached a
             * terminal state.
             */
            if (
                $query()
                    ->whereIn('status', ['pending', 'queued', 'running'])
                    ->exists()
            ) {
                return;
            }

            /*
             * A split parent is not considered a failure. Only terminal
             * non-completed leaf states count here.
             */
            $hasFailures = $query()
                ->whereNotIn('status', ['completed', 'split'])
                ->exists();

            $hasCompleted = $query()
                ->where('status', 'completed')
                ->exists();

            /*
             * Nothing trustworthy survived extraction. There is nothing that
             * can safely be merged or synthesized.
             */
            if ($hasFailures && ! $hasCompleted) {
                $billingFailure = $query()->where('failure_class', 'billing')->exists();
                $document->forceFill([
                    'status' => 'Needs Review',
                    'progress' => 100,
                    'error_message' => $billingFailure
                        ? 'AI analysis is temporarily unavailable. Please contact support.'
                        : 'Document intelligence could not produce usable evidence.',
                    'ai_pipeline' => [...$document->ai_pipeline, 'partial' => true, 'synthesis' => 'no_evidence',
                        'coverage' => ['failed_chunks' => $leafCount, 'total_chunks' => $leafCount,
                            'comprehensive' => false, 'warning' => 'No usable document evidence was extracted.']],
                ])->save();

                return;
            }

            /*
             * Important:
             *
             * Partial extraction is still useful. Successful chunks must be
             * merged even when other independent chunks failed.
             */
            $merge = DocumentChunk::firstOrCreate(
                [
                    'document_id' => $documentId,
                    'pipeline_key' => $key,
                    'identity' => 'merge',
                ],
                [
                    'workspace_id' => $document->workspace_id,
                    'stage' => 'merge',
                    'input_hash' => $key,
                    'pipeline_version' => config('document_intelligence.pipeline_version'),
                    'prompt_version' => config('document_intelligence.prompt_version'),
                ]
            );

            /*
             * Merge finished. Finalize according to coverage.
             */
            if ($merge->status === 'completed') {
                $coverage = app(EvidenceBudget::class)->forDocument($document)['coverage'];
                $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
                    'partial' => ! $coverage['comprehensive'], 'coverage' => $coverage]])->save();
                if ($coverage['evidence_total'] <= $coverage['evidence_omitted']) {
                    $document->forceFill(['status' => 'Needs Review', 'progress' => 100,
                        'error_message' => 'Document intelligence could not produce usable evidence.',
                        'ai_pipeline' => [...$document->ai_pipeline, 'partial' => true, 'synthesis' => 'no_evidence']])->save();

                    return;
                }
                if (($document->ai_pipeline['synthesis'] ?? null) === 'completed') {
                    return;
                }
                if (DocumentChunk::where('document_id', $documentId)->where('pipeline_key', $key)
                    ->where('stage', 'synthesis')->whereIn('status', ['failed', 'budget', 'uncertain'])->exists()
                    && ! DocumentChunk::where('document_id', $documentId)->where('pipeline_key', $key)
                        ->where('stage', 'synthesis')->whereIn('status', ['pending', 'running'])->exists()) {
                    return;
                }
                // Synthesis is required, even when only partial evidence survived.
                $document->forceFill(['status' => 'Processing', 'progress' => 95, 'error_message' => null])->save();
                GenerateDocumentSummaryJob::dispatch($documentId)->onQueue('extraction')->afterCommit();

                return;
            }

            if ($merge->status === 'pending') {
                $merge->update([
                    'status' => 'queued',
                ]);

                MergeDocumentEvidenceJob::dispatch($merge->id)
                    ->onQueue('extraction')
                    ->afterCommit();
            }
        });
    }

    public function split(
        DocumentChunk $chunk,
        Document $document
    ): void {
        /*
         * Only failures that can actually benefit from smaller input may
         * create child chunks.
         *
         * invalid_evidence / invalid_schema / invalid_date are output
         * contract failures. Splitting those recursively creates smaller and
         * smaller requests without fixing the underlying problem.
         */
        if (
            ! in_array(
                $chunk->failure_class,
                ['max_tokens', 'context_overflow', 'timeout'],
                true
            )
        ) {
            $chunk->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            return;
        }

        $text = mb_substr(
            $document->extracted_text,
            $chunk->start_offset,
            $chunk->end_offset - $chunk->start_offset
        );

        if (
            mb_strlen($text)
                < config('document_intelligence.minimum_split_chars')
            || $chunk->depth
                >= config('document_intelligence.max_split_depth')
        ) {
            $chunk->update([
                'status' => 'failed',
                'failure_class' => 'split_limit',
                'completed_at' => now(),
            ]);

            return;
        }

        /*
         * Reduce the offending chunk to approximately half its estimated
         * token size.
         */
        $target = max(
            1,
            (int) ceil($this->planner->estimate($text) / 2)
        );

        $children = $this->planner->plan(
            $text,
            $target,
            $chunk->start_offset,
            $chunk->start_page ?? 1,
            false
        );

        DB::transaction(function () use ($chunk, $children) {
            $locked = DocumentChunk::whereKey($chunk->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'split') {
                return;
            }

            foreach ($children as $index => $range) {
                if ($chunk->start_page === null) {
                    $range['start_page'] = null;
                    $range['end_page'] = null;
                }

                DocumentChunk::firstOrCreate(
                    [
                        'document_id' => $chunk->document_id,
                        'pipeline_key' => $chunk->pipeline_key,
                        'identity' => $chunk->identity.'.'.$index,
                    ],
                    $range + [
                        'workspace_id' => $chunk->workspace_id,
                        'parent_id' => $chunk->id,
                        'depth' => $chunk->depth + 1,
                        'pipeline_version' => $chunk->pipeline_version,
                        'prompt_version' => $chunk->prompt_version,
                    ]
                );
            }

            $locked->update([
                'status' => 'split',
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * A crash after sending a provider request is ambiguous.
     * Never silently replay potentially billable work.
     */
    public function recover(Document $document): void
    {
        $key = $document->ai_pipeline['key'] ?? '';

        DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $key)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(7))
            ->update([
                'status' => 'uncertain',
                'failure_class' => 'interrupted',
            ]);

        /*
         * Recover a lost queue dispatch after the database commit.
         * Duplicate deliveries are harmless because ProcessDocumentChunkJob
         * claims the unit under a lock.
         */
        DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $key)
            ->where('stage', 'extraction')
            ->where('status', 'queued')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
            ]);

        foreach (
            DocumentChunk::where('document_id', $document->id)
                ->where('pipeline_key', $key)
                ->where('stage', 'merge')
                ->where('status', 'queued')
                ->where('updated_at', '<', now()->subMinutes(10))
                ->get() as $merge
        ) {
            $merge->update([
                'status' => 'pending',
            ]);
        }

        $this->pump($document->id);

        $fresh = $document->fresh();

        /*
         * Continue post-processing only after a fully Ready document.
         *
         * Needs Review documents may already contain merged partial evidence,
         * but recovery should not pretend the pipeline was fully successful.
         */
        if ($fresh?->status === 'Ready') {
            $units = fn () => DocumentChunk::where(
                'document_id',
                $document->id
            )->where(
                'pipeline_key',
                $document->ai_pipeline['key']
            );

            if (
                ! $document->intelligenceSummary()->exists()
                && (
                    ! $units()->where('stage', 'synthesis')->exists()
                    || $units()
                        ->where('stage', 'synthesis')
                        ->whereIn('status', ['completed', 'pending'])
                        ->exists()
                )
            ) {
                GenerateDocumentSummaryJob::dispatch(
                    $document->id,
                    true
                )->onQueue('extraction');
            }

            if (! $units()->where('stage', 'visual_plan')->exists()) {
                AnalyzeEmbeddedVisualsJob::dispatch(
                    $document->id
                )->onQueue('extraction');
            }

            if (
                ! $document->processingJobs()
                    ->where('stage', 'chunk')
                    ->exists()
            ) {
                GenerateEmbeddingsJob::dispatch(
                    $document->id
                )->onQueue('extraction');
            }

            if (
                ! $units()
                    ->whereIn(
                        'status',
                        ['pending', 'queued', 'running']
                    )
                    ->exists()
                && $units()
                    ->where('stage', 'visual_plan')
                    ->exists()
                && (
                    $document->intelligenceSummary()->exists()
                    || $units()->where('stage', 'synthesis')->exists()
                )
                && $document->processingJobs()
                    ->where('stage', 'chunk')
                    ->exists()
            ) {
                $fresh = $document->refresh();

                $fresh->forceFill([
                    'ai_pipeline' => [
                        ...$fresh->ai_pipeline,
                        'recovery_complete' => true,
                    ],
                ])->save();
            }
        }
    }
}
