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
use App\Services\AI\AiPricing;
use App\Services\AnthropicClient;
use App\Services\Pipeline\DocumentProgress;
use App\Support\QueueInspector;
use App\Support\QueueTopology;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncrementalPipeline
{
    public function __construct(
        private ChunkPlanner $planner,
        private AnthropicClient $client,
        private ExtractionCapacity $capacity
    ) {}

    /** Only capacity-type failures may be retried on smaller input. */
    public const SPLITTABLE_FAILURES = ['max_tokens', 'context_overflow', 'timeout'];

    /**
     * True when another document has extraction work waiting in the queue. A chunk that
     * already shares the document's permits then leaves the last free provider permit to it.
     */
    public function otherDocumentsQueued(DocumentChunk $chunk): bool
    {
        return DocumentChunk::where('stage', 'extraction')->where('status', 'queued')
            ->where('document_id', '!=', $chunk->document_id)->exists();
    }

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
         * The legacy four-job path truncates at max_extraction_chars, so it is only
         * used when that is lossless. Everything else is routed by model capacity.
         */
        $legacy =
            $metadata['tokens'] <= config('document_intelligence.large_tokens')
            && mb_strlen($text) <= config('document_processing.max_extraction_chars');

        $metadata['route'] = $legacy ? 'normal' : 'incremental';

        if (! $legacy) {
            app(DocumentProgress::class)->record($document->id, 'planning');
            $metadata['routing'] = $this->capacity->decide((int) $metadata['tokens'], $this->capacity->density($text, $document->type));
            $metadata['mode'] = $metadata['routing']['mode'];
            // Metadata only: never text, prompts or evidence.
            Log::info('Document intelligence route selected', ['document_id' => $document->id, ...$metadata['routing']]);
        }

        $document->forceFill([
            'ai_pipeline' => $metadata,
        ])->save();

        if ($legacy) {
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

        $tokens = (int) ($document->ai_pipeline['tokens'] ?? $this->planner->estimate($document->extracted_text));
        $routing = $this->capacity->decide($tokens, $this->capacity->density($document->extracted_text, $document->type));
        $plan = $this->planner->partition(
            $document->extracted_text,
            $routing['partition_tokens'],
            $density
        );
        $routing['root_chunks'] = count($plan);

        DB::transaction(function () use (
            $document,
            $key,
            $plan,
            $routing,
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
            $metadata['mode'] = $routing['mode'];
            $metadata['routing'] = $routing;
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

            // A new pipeline starts at the top of the synthesis fallback ladder.
            $metadata = [...$metadata, ...$this->client->synthesisReservation($locked, null, 0), 'synthesis' => 'pending',
                'synthesis_reductions' => 0, 'synthesis_degradations' => []];

            $locked->forceFill([
                'ai_pipeline' => $metadata,
            ])->save();
        });

        Log::info('Document intelligence partitions planned', ['document_id' => $document->id,
            'mode' => $routing['mode'], 'root_chunks' => $routing['root_chunks'],
            'partition_tokens' => $routing['partition_tokens'], 'document_tokens' => $routing['document_tokens'],
            'record_limit' => $routing['record_limit'], 'dense' => $routing['dense'], 'records_per_1k_tokens' => $routing['records_per_1k_tokens']]);

        $this->pump($document->id);
    }

    /**
     * Caller holds the document lock, serializing all paid stage admissions. Extraction must leave
     * the primary synthesis, one degraded synthesis retry and one repair affordable; a synthesis
     * admission passes $protect (the next fallback's reserve) so it cannot consume that headroom.
     */
    public function canReserve(Document $document, ?float $cost, bool $synthesis = false, float $protect = 0): bool
    {
        if ($cost === null) {
            return false;
        }
        $protected = $protect;
        if (($document->ai_pipeline['route'] ?? null) === 'incremental' && ! $synthesis
            && ($document->ai_pipeline['synthesis'] ?? null) !== 'completed') {
            if (! array_key_exists('synthesis_reserved_usd', $document->ai_pipeline)
                || ! array_key_exists('synthesis_degraded_reserved_usd', $document->ai_pipeline)) {
                $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
                    ...$this->client->synthesisReservation($document)]])->save();
            }
            $reserves = [$document->ai_pipeline['synthesis_reserved_usd'], $document->ai_pipeline['synthesis_degraded_reserved_usd'],
                $document->ai_pipeline['repair_reserved_usd']];
            if (in_array(null, $reserves, true)) {
                return false;
            }
            $protected = array_sum($reserves);
        }
        $committed = DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])->sum('reserved_cost');

        return round($committed + $cost + $protected, 6) <= ($document->ai_pipeline['budget_usd'] ?? 0);
    }

    /**
     * reserved_cost retains settled spend plus the one active attempt's upper bound.
     * $components are the per-request bounds in request order (e.g. synthesis, then repair), so an
     * unknown-usage settlement charges only the requests actually sent. $meta is metadata only.
     */
    public function reserveCost(DocumentChunk $unit, float $cost, array $meta = [], ?array $components = null): void
    {
        $unit->update(['status' => 'running', 'started_at' => now(), 'attempts' => $unit->attempts + 1,
            'reserved_cost' => round($unit->reserved_cost + $cost, 6),
            'cost_accounting' => [...$meta, 'attempt' => $unit->attempts + 1, 'previous_cost' => (float) $unit->reserved_cost,
                'estimate' => $cost, 'components' => $components ?? [$cost], 'settled' => false]]);
    }

    /** Metadata for one admission: queue wait since dispatch and observed extraction concurrency. */
    public function admissionMetadata(DocumentChunk $unit): array
    {
        $running = fn () => DocumentChunk::where('stage', $unit->stage)->where('status', 'running');

        return [
            // From the original dispatch (deferrals keep it), so provider-capacity waits are visible.
            'queue_wait_ms' => ($since = $unit->dispatched_at ?? $unit->updated_at) ? max(0, (int) $since->diffInMilliseconds(now(), true)) : null,
            'document_running' => $running()->where('document_id', $unit->document_id)->where('pipeline_key', $unit->pipeline_key)->count() + 1,
            'global_running' => $running()->count() + 1,
        ];
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
            $runs = DocumentAiRun::where('chunk_id', $locked->id)->where('request_attempt', $accounting['attempt'])
                ->orderBy('created_at')->orderBy('id')->get();
            $known = $runs->isNotEmpty() && $runs->every(fn ($run) => $run->estimated_cost_usd !== null);
            $cost = ! $providerCalled ? 0 : ($known ? (float) $runs->sum('estimated_cost_usd') : $this->conservativeCost($accounting, $runs));
            $locked->update(['reserved_cost' => round($accounting['previous_cost'] + $cost, 6),
                'cost_accounting' => [...$accounting, 'settled' => true, 'actual_known' => $known || ! $providerCalled, 'cost' => $cost]]);
            $document = Document::find($locked->document_id);
            $committed = (float) DocumentChunk::where('document_id', $locked->document_id)
                ->where('pipeline_key', $locked->pipeline_key)->sum('reserved_cost');
            $budget = (float) ($document?->ai_pipeline['budget_usd'] ?? 0);
            // Metadata only: identifiers, token counts and USD amounts.
            Log::info('Document AI cost settled', ['document_id' => $locked->document_id, 'chunk_id' => $locked->id,
                'stage' => $locked->stage, 'attempt' => $accounting['attempt'], 'provider_called' => $providerCalled,
                'estimated_cost_usd' => $accounting['estimate'], 'settled_cost_usd' => $cost, 'actual_known' => $known || ! $providerCalled,
                'input_tokens' => (int) $runs->sum('input_tokens'), 'output_tokens' => (int) $runs->sum('output_tokens'),
                'document_budget_usd' => $budget, 'document_committed_usd' => round($committed, 6),
                'document_budget_remaining_usd' => round($budget - $committed, 6)]);
        });
    }

    /**
     * Unknown usage (e.g. a timeout) keeps each sent request's full reserved bound; requests never
     * sent (e.g. a repair after a timed-out synthesis) are not charged. Known usage is actual.
     */
    private function conservativeCost(array $accounting, $runs): float
    {
        $components = $accounting['components'] ?? null;
        if (! is_array($components) || $runs->isEmpty() || $runs->count() > count($components)) {
            return (float) $accounting['estimate'];
        }
        $cost = 0.0;
        foreach ($runs->values() as $index => $run) {
            $cost += $run->estimated_cost_usd !== null ? (float) $run->estimated_cost_usd : (float) $components[$index];
        }

        return round($cost, 6);
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
                'ai_pipeline' => [...$locked->ai_pipeline, ...$this->client->synthesisReservation($locked, null, 0),
                    'analysis_revision' => ($locked->ai_pipeline['analysis_revision'] ?? 0) + 1,
                    'synthesis_reductions' => 0, 'synthesis_degradations' => [], 'synthesis_failure_reason' => null, 'synthesis' => 'pending', 'summary_stale' => true, 'recovery_complete' => false]])->save();
        });
        if (! $dispatch) {
            return;
        }
        if ($summaryOnly) {
            GenerateDocumentSummaryJob::dispatch($document->id, true)->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class));
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
                $this->recordExtractionProgress($document, $query()->get(['id', 'parent_id', 'status', 'start_offset', 'end_offset']));
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

                ProcessDocumentChunkJob::dispatch($chunk->id, $chunk->issueDispatchToken())
                    ->onQueue(QueueTopology::for(ProcessDocumentChunkJob::class))
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
                $document->forceFill(['status' => 'Processing', 'progress' => max(95, (int) $document->progress), 'error_message' => null])->save();
                app(DocumentProgress::class)->record($documentId, 'synthesizing', 95);
                GenerateDocumentSummaryJob::dispatch($documentId)->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class))->afterCommit();

                return;
            }

            if ($merge->status === 'pending') {
                $merge->update([
                    'status' => 'queued',
                ]);

                MergeDocumentEvidenceJob::dispatch($merge->id, $merge->issueDispatchToken())
                    ->onQueue(QueueTopology::for(MergeDocumentEvidenceJob::class))
                    ->afterCommit();
            }
        });
    }

    /**
     * Progress is weighted by source characters of terminal leaves over root characters. Splitting a
     * parent never changes that denominator, so the percentage cannot move backwards; "k of N"
     * counts roots (the planned sections) whose whole subtree is terminal.
     */
    private function recordExtractionProgress(Document $document, $chunks): void
    {
        $roots = $chunks->whereNull('parent_id');
        $total = max(1, $roots->sum(fn ($c) => $c->end_offset - $c->start_offset));
        $terminal = $chunks->whereNotIn('status', ['split', 'pending', 'queued', 'running']);
        $done = $terminal->sum(fn ($c) => $c->end_offset - $c->start_offset);
        $children = $chunks->groupBy('parent_id');
        $completeRoots = $roots->filter(fn ($root) => $this->subtreeFinished($root, $children))->count();
        $recovering = $chunks->whereNotNull('parent_id')->whereIn('status', ['pending', 'queued', 'running'])->isNotEmpty();
        app(DocumentProgress::class)->record($document->id, $recovering ? 'recovering' : 'extracting',
            50 + (int) floor(35 * min(1, $done / $total)),
            ['step' => min($roots->count(), $completeRoots + 1), 'total' => $roots->count(), 'sections_complete' => $completeRoots]);
    }

    private function subtreeFinished(DocumentChunk $chunk, $children): bool
    {
        if ($chunk->status !== 'split') {
            return ! in_array($chunk->status, ['pending', 'queued', 'running'], true);
        }

        return ($children[$chunk->id] ?? collect())->every(fn ($child) => $this->subtreeFinished($child, $children));
    }

    /**
     * Ancestors in a truncation-continuation chain: a continuation's parent is a
     * completed leaf over the same text (a split parent has status "split").
     */
    private function continuationAncestors(DocumentChunk $chunk): array
    {
        $ancestors = [];
        for ($parent = $chunk->parent_id ? DocumentChunk::find($chunk->parent_id) : null;
            $parent && $parent->status === 'completed' && $parent->start_offset === $chunk->start_offset && $parent->end_offset === $chunk->end_offset;
            $parent = $parent->parent_id ? DocumentChunk::find($parent->parent_id) : null) {
            $ancestors[] = $parent;
        }

        return $ancestors;
    }

    /** Request fields for a continuation: records already returned for this slice (metadata, short quotes). */
    public function continuationContext(DocumentChunk $chunk): array
    {
        $records = [];
        foreach ($this->continuationAncestors($chunk) as $ancestor) {
            foreach ($ancestor->result['records'] ?? [] as $record) {
                $records[] = ['kind' => $record['kind'], 'label' => $record['label'], 'value' => mb_substr($record['value'], 0, 120),
                    'quote' => mb_substr($record['quote'], 0, (int) config('document_intelligence.continuation_quote_chars'))];
            }
        }

        return $records === [] ? [] : ['already_extracted' => $records];
    }

    /**
     * Keep strictly validated records salvaged from a truncated response, then request
     * only the remaining output for the same slice (bounded), instead of discarding the
     * paid output and re-sending the text as split halves.
     */
    public function keepSalvaged(DocumentChunk $chunk, Document $document, array $partial, bool $affordable): void
    {
        $continuations = count($this->continuationAncestors($chunk));
        $continue = $affordable && $continuations < (int) config('document_intelligence.max_truncation_continuations');
        DB::transaction(function () use ($chunk, $partial, $continue) {
            Document::whereKey($chunk->document_id)->lockForUpdate()->firstOrFail();
            $locked = DocumentChunk::whereKey($chunk->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'running') {
                return;
            }
            if ($continue) {
                DocumentChunk::firstOrCreate(['document_id' => $locked->document_id, 'pipeline_key' => $locked->pipeline_key,
                    'identity' => $locked->identity.'.r'], [
                        'workspace_id' => $locked->workspace_id, 'parent_id' => $locked->id, 'depth' => $locked->depth,
                        'start_offset' => $locked->start_offset, 'end_offset' => $locked->end_offset,
                        'start_page' => $locked->start_page, 'end_page' => $locked->end_page, 'token_count' => $locked->token_count,
                        'input_hash' => $locked->input_hash, 'pipeline_version' => $locked->pipeline_version,
                        'prompt_version' => $locked->prompt_version]);
            }
            // Without a continuation the unextracted remainder is disclosed as saturated coverage.
            $locked->update(['status' => 'completed', 'completed_at' => now(), 'failure_class' => null,
                'result' => $partial + ['_truncated' => true, '_continued' => $continue, '_saturated' => ! $continue]]);
        });
        Log::info('Document intelligence truncated response salvaged', ['document_id' => $chunk->document_id,
            'chunk_id' => $chunk->id, 'salvaged_records' => count($partial['records']), 'continued' => $continue,
            'continuation' => $continuations, 'affordable' => $affordable]);
    }

    /**
     * Per-document hard ceiling for further extraction spend: true when at least one more
     * minimal extraction request (prompt overhead + full output cap) fits the document budget
     * without touching the synthesis/repair hold. Running siblings will settle below their
     * reservations, so their presence defers the decision to admission instead.
     */
    public function canAffordMoreExtraction(Document $document, DocumentChunk $current): bool
    {
        return DB::transaction(function () use ($document, $current) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (DocumentChunk::where('document_id', $locked->id)->where('pipeline_key', $current->pipeline_key)
                ->where('stage', 'extraction')->where('status', 'running')->whereKeyNot($current->id)->exists()) {
                return true;
            }
            $capacity = app(ExtractionCapacity::class);
            $cost = app(AiPricing::class)->reserve($capacity->model(), $capacity->promptOverheadTokens(), $capacity->outputTokens(), cacheWrite: true);

            return $this->canReserve($locked, $cost);
        });
    }

    /** Ceiling reached: no split, retry or continuation; coverage reports the leaf as incomplete. */
    public function stopForBudget(DocumentChunk $chunk): void
    {
        $reason = $chunk->failure_class;
        $chunk->update(['status' => 'budget', 'failure_class' => 'budget_exceeded', 'completed_at' => now()]);
        Log::info('Document intelligence extraction stopped at spend ceiling', ['document_id' => $chunk->document_id,
            'chunk_id' => $chunk->id, 'failure_class' => $reason]);
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
        if (! in_array($chunk->failure_class, self::SPLITTABLE_FAILURES, true)) {
            $chunk->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);
            $this->logSplit($chunk, 'not_split', 0);

            return;
        }

        $text = mb_substr(
            $document->extracted_text,
            $chunk->start_offset,
            $chunk->end_offset - $chunk->start_offset
        );

        /*
         * Each child must stay above the minimum size: a failure on a slice that
         * small is degenerate output, not oversized input.
         */
        if (
            mb_strlen($text) / 2
                < config('document_intelligence.minimum_split_chars')
            || $chunk->depth
                >= config('document_intelligence.max_split_depth')
        ) {
            $reason = $chunk->failure_class;
            $chunk->update([
                'status' => 'failed',
                'failure_class' => 'split_limit',
                'completed_at' => now(),
            ]);
            $this->logSplit($chunk, 'split_limit', 0, $reason);

            return;
        }

        /*
         * Reduce the offending chunk to approximately half its estimated
         * token size.
         */
        $target = max(
            1,
            (int) ceil(($chunk->token_count ?: $this->planner->estimate($text)) / 2)
        );

        $children = $this->planner->plan(
            $text,
            $target,
            $chunk->start_offset,
            $chunk->start_page ?? 1,
            false
        );

        $outcome = DB::transaction(function () use ($chunk, $children) {
            // Document first, as every other admission path does, so the per-document
            // split budget below is checked serially across concurrent leaves.
            Document::whereKey($chunk->document_id)->lockForUpdate()->firstOrFail();
            $locked = DocumentChunk::whereKey($chunk->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'split') {
                return 'split';
            }

            /*
             * Per-document split budget. Depth and minimum child size bound one
             * branch; this bounds the whole tree, so a document whose output was
             * badly underestimated cannot fan out into dozens of re-sent requests.
             */
            $units = fn () => DocumentChunk::where('document_id', $chunk->document_id)
                ->where('pipeline_key', $chunk->pipeline_key)->where('stage', 'extraction');
            $budget = $units()->whereNull('parent_id')->count()
                * (int) config('document_intelligence.max_split_parents_per_root');
            if ($units()->where('status', 'split')->count() >= $budget) {
                $locked->update([
                    'status' => 'failed',
                    'failure_class' => 'split_limit',
                    'completed_at' => now(),
                ]);

                return 'split_budget';
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

            return 'split';
        });
        if ($outcome === 'split_budget') {
            $this->logSplit($chunk, 'split_budget', 0, $chunk->failure_class);

            return;
        }
        $this->logSplit($chunk, 'split', count($children));
    }

    /** Metadata only: identifiers, failure class, depth and sizes. */
    private function logSplit(DocumentChunk $chunk, string $outcome, int $children, ?string $reason = null): void
    {
        Log::info('Document intelligence chunk split decision', ['document_id' => $chunk->document_id,
            'chunk_id' => $chunk->id, 'outcome' => $outcome, 'failure_class' => $reason ?? $chunk->failure_class,
            'depth' => $chunk->depth, 'children' => $children, 'token_count' => $chunk->token_count]);
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
         * Recover a lost queue dispatch (process died between commit and push, or Redis lost
         * its data) without duplicating work that is merely waiting in a backed-up queue.
         * A queued unit older than the minimum age is reset only when no queue message
         * carrying its dispatch token remains. If the queue cannot be inspected, the age
         * rule alone applies; that stays safe because the reset issues a new token and a
         * surviving old message is then dropped as superseded before any provider call.
         */
        $present = app(QueueInspector::class)->pendingDispatchTokens();
        DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $key)
            ->whereIn('stage', ['extraction', 'merge'])
            ->where('status', 'queued')
            ->where(fn ($q) => $q->where('dispatched_at', '<', now()->subMinutes((int) config('document_intelligence.recovery_queued_minutes')))
                ->orWhere(fn ($legacy) => $legacy->whereNull('dispatched_at')
                    ->where('updated_at', '<', now()->subMinutes((int) config('document_intelligence.recovery_queued_minutes')))))
            ->get()
            ->reject(fn (DocumentChunk $unit) => $present !== null && $unit->dispatch_token !== null && isset($present[$unit->dispatch_token]))
            ->each(function (DocumentChunk $unit) use ($present) {
                Log::info('Lost queue dispatch recovered', ['document_id' => $unit->document_id, 'chunk_id' => $unit->id,
                    'stage' => $unit->stage, 'queue_inspected' => $present !== null]);
                $unit->update(['status' => 'pending']);
            });

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
                )->onQueue(QueueTopology::for(GenerateDocumentSummaryJob::class));
            }

            if (! $units()->where('stage', 'visual_plan')->exists()) {
                AnalyzeEmbeddedVisualsJob::dispatch(
                    $document->id
                )->onQueue(QueueTopology::for(AnalyzeEmbeddedVisualsJob::class));
            }

            if (
                ! $document->processingJobs()
                    ->where('stage', 'chunk')
                    ->exists()
            ) {
                GenerateEmbeddingsJob::dispatch(
                    $document->id
                )->onQueue(QueueTopology::for(GenerateEmbeddingsJob::class));
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
