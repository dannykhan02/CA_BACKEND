<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\Incremental\ContextResolver;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;

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

    public function __construct(public string $chunkId) {}

    public function handle(
        EvidenceMerger $merger,
        PipelineStageRecorder $recorder
    ): void {
        $unit = DocumentChunk::find($this->chunkId);
        $document = $unit?->document;

        if (
            ! $unit
            || ! $document
            || ! $document->canGenerateIntelligence()
            || $unit->workspace_id !== $document->workspace_id
            || $unit->pipeline_key !== ($document->ai_pipeline['key'] ?? null)
        ) {
            return;
        }

        /*
         * Another delivery may already have completed this deterministic unit.
         */
        if ($unit->status === 'completed') {
            return;
        }

        /*
         * Claim the merge unit.
         *
         * Keep this transaction tiny. Do not hold document/workspace locks
         * while performing the potentially expensive evidence merge.
         */
        $claimed = DB::transaction(function () use ($unit) {
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
                ['pending', 'queued', 'running'],
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

        try {
            /*
             * These operations are deterministic and idempotent.
             *
             * IMPORTANT:
             * Do NOT wrap the entire merge in one giant DB transaction.
             * EvidenceMerger already commits its individual evidence units.
             */
            $document->refresh();

            $merger->merge($document);

            app(ContextResolver::class)->resolve($document);

            /*
             * Finalize intelligence stage state first.
             *
             * This transaction is intentionally short.
             */
            DB::transaction(function () use (
                $unit,
                $document,
                $recorder
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
                    ['entities', 'risks', 'deadlines', 'ai_analysis']
                    as $stage
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
                ]);

                $lockedDocument->forceFill([
                    'progress' => 95,
                    'has_structured_data' =>
                        $lockedDocument->kpis()->exists(),
                ])->save();
            });

            /*
             * Billing deliberately happens OUTSIDE the merge transaction.
             *
             * accountForReadyDocument() performs its own workspace/billing
             * locking. Keeping it out here prevents the long merge transaction
             * from holding unrelated locks while the credit ledger settles.
             */
            $fresh = Document::findOrFail($document->id);

            DB::transaction(function () use ($fresh) {
                $locked = Document::whereKey($fresh->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                app(WorkspaceCreditService::class)
                    ->accountForReadyDocument($locked);

                $locked->forceFill([
                    'status' => 'Ready',
                    'progress' => 100,
                    'error_message' => null,
                ])->save();
            });

            /*
             * Continue optional/post-processing work only after finalization.
             */
            GenerateDocumentSummaryJob::dispatch($document->id)
                ->onQueue('extraction');

            AnalyzeEmbeddedVisualsJob::dispatch($document->id)
                ->onQueue('extraction');

            GenerateEmbeddingsJob::dispatch($document->id)
                ->onQueue('extraction');

            /*
             * Let the incremental coordinator reconcile final/partial state.
             */
            app(IncrementalPipeline::class)
                ->pump($document->id);
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
                    'error_message' =>
                        'Document intelligence is partially available, but finalization could not be completed.',
                ])->save();
            }

            throw $e;
        }
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
                'failure_class' =>
                    $e instanceof TimeoutExceededException
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
                'error_message' =>
                    'Document intelligence is partially available, but finalization could not be completed.',
            ])->save();
        }
    }
}