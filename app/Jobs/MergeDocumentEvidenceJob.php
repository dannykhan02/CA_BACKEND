<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\Incremental\ContextResolver;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\Pipeline\DocumentProgress;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeDocumentEvidenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
     * Merge is deterministic and has no provider cost, so a retry is safe.
     */
    public int $tries = 2;

    /*
     * Horizon extraction worker = 360s
     * Redis retry_after          = 390s
     *
     * Stay safely below both.
     */
    public int $timeout = 330;

    public bool $failOnTimeout = true;

    /** $dispatchToken identifies the dispatch this message belongs to (null: pre-token message). */
    public function __construct(public string $chunkId, public ?string $dispatchToken = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('document-merge:'.$this->chunkId))->dontRelease()->expireAfter(360)];
    }

    public function handle(
        EvidenceMerger $merger,
        PipelineStageRecorder $recorder
    ): void {
        $unit = DocumentChunk::find($this->chunkId);
        if ($unit && $this->dispatchToken !== null && $unit->dispatch_token !== null && ! hash_equals($unit->dispatch_token, $this->dispatchToken)) {
            return; // Superseded by a newer dispatch of this merge unit.
        }
        $document = $unit?->document;

        if (
            ! $unit
            || ! $document
            || (! $document->canGenerateIntelligence()
                && ! ($document->status === 'Needs Review' && in_array($unit->status, ['completed', 'failed'], true)
                    && ($document->ai_pipeline['synthesis'] ?? 'pending') === 'pending'))
            || $unit->workspace_id !== $document->workspace_id
            || $unit->pipeline_key !== ($document->ai_pipeline['key'] ?? null)
        ) {
            return;
        }

        /*
         * Another delivery may already have completed this deterministic unit.
         */
        if ($unit->status === 'completed') {
            $this->finalizeMerge($document);

            return;
        }
        if ($document->status === 'Needs Review') {
            $document->forceFill(['status' => 'Processing'])->save();
        }

        /*
         * Claim the merge unit.
         *
         * Keep this transaction tiny. Do not hold document/workspace locks
         * while performing the potentially expensive evidence merge.
         */
        $claimed = DB::transaction(function () use ($unit) {
            $document = Document::whereKey($unit->document_id)->lockForUpdate()->firstOrFail();
            if (! $document->canGenerateIntelligence() || $unit->pipeline_key !== ($document->ai_pipeline['key'] ?? null)
                || DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $unit->pipeline_key)
                    ->where('stage', 'extraction')->whereIn('status', ['pending', 'queued', 'running'])->exists()) {
                return false;
            }
            $locked = DocumentChunk::whereKey($unit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'completed') {
                return false;
            }

            /*
             * Allow queued/pending merge units. A retry can also see a stale
             * running merge from its own previous interrupted attempt.
             */
            if (! in_array(
                $locked->status,
                ['pending', 'queued', 'running', 'failed'],
                true
            )) {
                return false;
            }

            $locked->update([
                'status' => 'running',
                'attempts' => $locked->attempts + 1,
                'started_at' => now(),
                'failure_class' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }
        app(DocumentProgress::class)->record($document->id, 'merging', 86);

        try {
            /*
             * These operations are deterministic and idempotent.
             *
             * IMPORTANT:
             * Do NOT wrap the entire merge in one giant DB transaction.
             * EvidenceMerger commits bounded batches and never locks the document row.
             */
            $document->refresh();

            $diagnostics = $merger->merge($document);

            $clock = hrtime(true);
            app(ContextResolver::class)->resolve($document);
            $diagnostics['context_ms'] = round((hrtime(true) - $clock) / 1e6, 1);
            $diagnostics += $this->chunkTree($unit);
            $unit->refresh();
            $diagnostics['queue_wait_ms'] = $unit->dispatched_at && $unit->started_at
                ? max(0, (int) $unit->dispatched_at->diffInMilliseconds($unit->started_at, true)) : null;
            // Counts and timings only: never quotes, evidence content or prompts.
            Log::info('Document evidence merge completed', ['document_id' => $document->id,
                'attempt' => $unit->fresh()?->attempts, ...$diagnostics]);

            /*
             * Finalize intelligence stage state first.
             *
             * This transaction is intentionally short.
             */
            DB::transaction(function () use (
                $unit,
                $document,
                $recorder,
                $diagnostics
            ) {
                $lockedDocument = Document::whereKey($document->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedUnit = DocumentChunk::whereKey($unit->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUnit->status === 'completed') {
                    return;
                }

                if (! $lockedDocument->canGenerateIntelligence()) {
                    return;
                }

                foreach (
                    ['entities', 'risks', 'deadlines', 'ai_analysis'] as $stage
                ) {
                    $job = $recorder->start(
                        $lockedDocument,
                        $stage
                    );

                    $recorder->complete($job, [
                        'pipeline_key' => $lockedUnit->pipeline_key,
                        'incremental' => true,
                    ]);
                }

                $recorder->skip(
                    $lockedDocument,
                    'document_type',
                    'Global evidence extraction does not require classification.'
                );

                /*
                 * Mark the merge checkpoint complete before billing.
                 *
                 * If billing settlement later fails, we do not need to repeat
                 * the expensive evidence merge.
                 */
                $lockedUnit->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'failure_class' => null,
                    'result' => ['diagnostics' => $diagnostics],
                ]);

                $lockedDocument->forceFill([
                    'progress' => max(95, (int) $lockedDocument->progress),
                    'has_structured_data' => $lockedDocument->kpis()->exists(),
                ])->save();
            });

            $this->finalizeMerge($document);
        } catch (\Throwable $e) {
            /*
             * Never leave a dead merge looking like active processing.
             */
            $freshUnit = DocumentChunk::find($this->chunkId);

            if ($freshUnit && $freshUnit->status !== 'completed') {
                $freshUnit->update([
                    'status' => 'failed',
                    'failure_class' => 'merge_failure',
                    'completed_at' => now(),
                ]);
            }

            $freshDocument = $document->fresh();

            if ($freshDocument?->status === 'Processing') {
                $freshDocument->forceFill([
                    'status' => 'Needs Review',
                    'error_message' => 'Document intelligence is partially available, but finalization could not be completed.',
                ])->save();
            }

            throw $e;
        }
    }

    /** Extraction tree shape for diagnostics: counts by status/failure class and depth only. */
    private function chunkTree(DocumentChunk $unit): array
    {
        $chunks = DocumentChunk::where('document_id', $unit->document_id)->where('pipeline_key', $unit->pipeline_key)
            ->where('stage', 'extraction')->get(['parent_id', 'status', 'failure_class', 'depth']);

        return [
            'root_chunks' => $chunks->whereNull('parent_id')->count(),
            'split_parents' => $chunks->where('status', 'split')->count(),
            'max_split_depth' => (int) $chunks->max('depth'),
            'leaf_statuses' => $chunks->where('status', '!=', 'split')->countBy('status')->all(),
            'leaf_failure_classes' => $chunks->whereNotIn('status', ['split', 'completed'])->countBy(fn ($c) => $c->failure_class ?? 'unknown')->all(),
        ];
    }

    /** Repeatable after a crash between the merge checkpoint and credit settlement. */
    private function finalizeMerge(Document $document): void
    {
        $fresh = $document->fresh();
        $coverage = app(EvidenceBudget::class)->forDocument($fresh)['coverage'];
        if ($coverage['evidence_total'] > $coverage['evidence_omitted']) {
            DB::transaction(function () use ($fresh) {
                $locked = Document::whereKey($fresh->id)->lockForUpdate()->firstOrFail();
                app(WorkspaceCreditService::class)->accountForReadyDocument($locked);
                if ($locked->status === 'Needs Review' && ($locked->ai_pipeline['synthesis'] ?? 'pending') === 'pending') {
                    $locked->forceFill(['status' => 'Processing', 'progress' => 95, 'error_message' => null]);
                }
                $locked->save();
            });
        }
        app(IncrementalPipeline::class)->pump($fresh->id);
    }

    public function failed(\Throwable $e): void
    {
        $unit = DocumentChunk::find($this->chunkId);

        if (! $unit) {
            return;
        }

        /*
         * If merge data had already been committed successfully before a
         * later billing/post-processing failure, preserve that checkpoint.
         */
        if ($unit->status !== 'completed') {
            $unit->update([
                'status' => 'failed',
                'failure_class' => $e instanceof TimeoutExceededException
                        ? 'worker_timeout'
                        : 'merge_failure',
                'completed_at' => now(),
            ]);
        }

        $document = Document::find($unit->document_id);

        if (
            $document
            && $document->status === 'Processing'
        ) {
            $document->forceFill([
                'status' => 'Needs Review',
                'error_message' => 'Document intelligence is partially available, but finalization could not be completed.',
            ])->save();
        }
    }
}
