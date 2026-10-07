<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\ProcessingJob;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\ProactiveChunkRisk;
use Illuminate\Console\Command;

/**
 * Read-only before/after report for one document's pipeline run.
 *
 * Everything here is derived from rows the pipeline already writes (processing_jobs,
 * document_chunks, document_ai_runs, document_evidence). The command calls no provider,
 * writes nothing and adds no observability infrastructure of its own.
 *
 * Metadata only: identifiers, timings, counts, token counts and USD amounts. Never source
 * text, quotes, prompts or responses.
 */
class PipelineReport extends Command
{
    protected $signature = 'docintel:pipeline-report {document : Document id} {--json : Machine-readable output}';

    protected $description = 'Read-only runtime, API-call, token, cost and acceptance metrics for one document';

    public function handle(): int
    {
        $document = Document::find($this->argument('document'));
        if (! $document) {
            $this->error('Document not found.');

            return self::FAILURE;
        }
        $report = $this->collect($document);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->render($report);

        return self::SUCCESS;
    }

    private function collect(Document $document): array
    {
        $key = $document->ai_pipeline['key'] ?? null;
        $runs = DocumentAiRun::where('document_id', $document->id)->get();
        $chunks = DocumentChunk::where('document_id', $document->id)
            ->when($key, fn ($query) => $query->where('pipeline_key', $key))->get();
        $extraction = $chunks->where('stage', 'extraction');
        $leaves = $extraction->where('status', '!=', 'split');
        $stages = ProcessingJob::where('document_id', $document->id)->get();
        $durations = $runs->pluck('duration_ms')->filter()->sort()->values();
        $extractionRuns = $runs->whereIn('chunk_id', $extraction->pluck('id')->all());
        $concurrency = $this->providerConcurrency($extractionRuns);
        $splitParents = $extraction->where('status', 'split');
        $splitRuns = $runs->whereIn('chunk_id', $splitParents->pluck('id')->all());
        $splitDetails = $splitParents->map(function ($parent) use ($extraction, $runs, $document) {
            $parentRuns = $runs->where('chunk_id', $parent->id);
            $children = $extraction->where('parent_id', $parent->id);
            $childRuns = $runs->whereIn('chunk_id', $children->pluck('id')->all());
            $source = mb_substr($document->extracted_text, $parent->start_offset, $parent->end_offset - $parent->start_offset);
            $grounding = app(EvidenceGrounding::class);
            $spans = $grounding->usesSpans($document) ? $grounding->chunkSpans($document, $parent) : null;
            $risk = $parent->cost_accounting['planning_risk'] ?? app(ProactiveChunkRisk::class)->assess($source,
                (int) ($parent->token_count ?: max(1, strlen($source) / 3)), $spans, $document->type);

            return ['chunk_id' => $parent->id, 'chunk_key' => $parent->identity,
                'depth' => $parent->depth, 'split_kind' => $parent->cost_accounting['split_kind'] ?? 'reactive',
                'reason' => $parent->failure_class, 'parent_input_tokens_counted' => $parent->token_count,
                'source_chars' => mb_strlen($source), 'source_spans' => $risk['source_spans'],
                'table_rows' => $risk['table_rows'], 'list_items' => $risk['list_items'],
                'expected_records_current_model' => $risk['expected_records'],
                'expected_output_tokens_current_model' => $risk['expected_output_tokens'],
                'parent_output_usable' => count($parent->result['records'] ?? []) > 0,
                'parent_provider_ms' => (int) $parentRuns->sum('duration_ms'),
                'parent_input_tokens' => (int) $parentRuns->sum('input_tokens'),
                'parent_output_tokens' => (int) $parentRuns->sum('output_tokens'),
                'parent_cost_usd' => round((float) $parentRuns->sum('estimated_cost_usd'), 6),
                'parent_settled_accounting_usd' => ($parent->cost_accounting['settled'] ?? false) ? (float) $parent->reserved_cost : null,
                'parent_actual_usage_known' => $parent->cost_accounting['actual_known'] ?? null,
                'parent_unpriced_calls' => $parentRuns->whereNull('estimated_cost_usd')->count(),
                'children' => $children->map(fn ($child) => ['chunk_key' => $child->identity,
                    'source_chars' => $child->end_offset - $child->start_offset, 'input_tokens_counted' => $child->token_count])->values()->all(),
                'child_calls' => $childRuns->count(), 'child_cost_usd' => round((float) $childRuns->sum('estimated_cost_usd'), 6)];
        })->values()->all();
        $queueTimings = $extraction->map(fn ($chunk) => $chunk->cost_accounting['queue_timing'] ?? null)->filter();
        $queueSum = fn (string $field) => $queueTimings->isEmpty() ? null
            : (int) $queueTimings->sum(fn ($timing) => $timing[$field] ?? 0);
        $proactiveParents = $splitParents->filter(fn ($chunk) => ($chunk->cost_accounting['split_kind'] ?? null) === 'proactive');
        $reactiveParents = $splitParents->filter(fn ($chunk) => ($chunk->cost_accounting['split_kind'] ?? null) !== 'proactive');
        $reactiveRuns = $runs->whereIn('chunk_id', $reactiveParents->pluck('id')->all());
        $coverage = $document->ai_pipeline['coverage'] ?? [];
        $partialReasons = [];
        foreach (['failed_chunks', 'dropped_records', 'saturated_chunks', 'unresolved_references', 'evidence_omitted'] as $field) {
            if (($coverage[$field] ?? 0) > 0) {
                $partialReasons[$field] = $coverage[$field];
            }
        }
        if ($runs->sum('optional_items_dropped') > 0) {
            $partialReasons['optional_items_dropped'] = (int) $runs->sum('optional_items_dropped');
        }
        if (($document->ai_pipeline['synthesis'] ?? null) !== 'completed') {
            $partialReasons['synthesis'] = $document->ai_pipeline['synthesis'] ?? 'incomplete';
        }
        if (($document->ai_pipeline['partial'] ?? false) && $partialReasons === []) {
            $partialReasons['unattributed_legacy_partial'] = true;
        }

        $returned = 0;
        $accepted = 0;
        $rejectionClasses = [];
        $rejectionReasons = [];
        $grounding = [];
        foreach ($extraction as $chunk) {
            $validation = $chunk->result['_validation'] ?? [];
            $returned += (int) ($validation['records_returned'] ?? $chunk->result['_returned_records'] ?? 0);
            $accepted += (int) ($validation['records_kept'] ?? count($chunk->result['records'] ?? []));
            foreach ($validation['rejections'] ?? $chunk->result['_dropped_records'] ?? [] as $class => $count) {
                $rejectionClasses[$class] = ($rejectionClasses[$class] ?? 0) + (int) $count;
            }
            foreach ($validation['rejection_reasons'] ?? [] as $reason => $count) {
                $rejectionReasons[$reason] = ($rejectionReasons[$reason] ?? 0) + (int) $count;
            }
            if ($mode = $validation['evidence_grounding_mode'] ?? null) {
                $grounding[$mode] = ($grounding[$mode] ?? 0) + 1;
            }
        }
        // Evidence rows carrying a resolved span id were grounded by reference, not by quote match.
        $spanSources = 0;
        $quoteSources = 0;
        if ($key) {
            foreach (DocumentEvidence::where('document_id', $document->id)->where('pipeline_key', $key)
                ->cursor() as $evidence) {
                foreach ($evidence->sources ?? [] as $source) {
                    isset($source['span_id']) ? $spanSources++ : $quoteSources++;
                }
            }
        }

        $finished = collect([$stages->max('completed_at'), $chunks->max('completed_at')])->filter()->max();
        $groundingReasons = ['missing_evidence_ids', 'evidence_ids_wrong_type', 'unknown_evidence_id',
            'evidence_id_outside_chunk', 'evidence_id_wrong_extraction_version', 'too_many_evidence_ids',
            'invalid_evidence_span_combination'];

        return [
            'document' => ['id' => $document->id, 'name' => $document->name, 'type' => $document->type,
                'pages' => $document->pages, 'status' => $document->status,
                'route' => $document->ai_pipeline['route'] ?? 'normal',
                'mode' => $document->ai_pipeline['mode'] ?? null,
                'grounding' => $document->ai_pipeline['grounding'] ?? 'legacy_quote',
                'extraction_version' => $document->ai_pipeline['extraction_version'] ?? null,
                'partial' => (bool) ($document->ai_pipeline['partial'] ?? false),
                'partial_reasons' => $partialReasons, 'coverage' => $coverage,
                'result' => ($document->ai_pipeline['partial'] ?? false) ? 'Partial' : ($document->status === 'Completed' ? 'Complete' : $document->status)],
            'timing_ms' => [
                'total' => $finished ? $document->created_at->diffInMilliseconds($finished) : null,
                'scan' => $this->stageMs($stages, ['virus_scan']),
                'extraction' => $this->stageMs($stages, ['extract', 'ocr_check']),
                'incremental_analysis' => $this->spanMs($extraction),
                'merge' => $this->spanMs($chunks->where('stage', 'merge')),
                'synthesis' => $this->spanMs($chunks->where('stage', 'synthesis')) ?? $this->stageMs($stages, ['document_summary']),
                'provider_total' => (int) $runs->sum('duration_ms'),
                'provider_extraction_sum' => (int) $extractionRuns->sum('duration_ms'),
                'provider_slowest' => (int) $runs->max('duration_ms'),
                'provider_median' => $durations->isEmpty() ? null : (int) $durations[intdiv($durations->count(), 2)],
                'provider_wall_clock_interval' => $concurrency['wall_ms'],
                'queue_worker_wait' => $queueSum('worker_wait_ms'),
                'fairness_wait' => $queueSum('fairness_wait_ms'),
                'provider_admission_wait' => $queueSum('provider_admission_wait_ms'),
            ],
            'provider' => [
                'calls' => $runs->count(),
                'extraction_calls' => $runs->whereNotNull('chunk_id')->count(),
                'failed_calls' => $runs->where('status', '!=', 'success')->count(),
                'timeout_calls' => $runs->where('failure_class', 'timeout')->count(),
                'repeat_calls' => $extractionRuns->count() - $extractionRuns->unique('chunk_id')->count(),
                'input_tokens' => (int) $runs->sum('input_tokens'),
                'output_tokens' => (int) $runs->sum('output_tokens'),
                'cache_write_tokens' => (int) $runs->sum('cache_creation_tokens'),
                'cache_read_tokens' => (int) $runs->sum('cache_read_tokens'),
                'cost_usd' => round((float) $runs->sum('estimated_cost_usd'), 4),
                'unpriced_calls' => $runs->whereNull('estimated_cost_usd')->count(),
                'output_tokens_per_accepted_record' => $accepted > 0 ? round($runs->sum('output_tokens') / $accepted, 1) : null,
                'effective_parallelism' => $concurrency['average'],
                'peak_concurrency' => $concurrency['peak'],
                'wall_time_at_concurrency_1_percent' => $concurrency['one_percent'],
                'wall_time_at_concurrency_2_plus_percent' => $concurrency['two_plus_percent'],
                'useful_successful_leaf_calls' => $runs->where('status', 'success')->whereIn('chunk_id', $leaves->pluck('id')->all())->count(),
                'split_parent_calls' => $splitRuns->count(),
                'wasted_split_provider_ms' => (int) $reactiveRuns->sum('duration_ms'),
                'wasted_split_input_tokens' => (int) $reactiveRuns->sum('input_tokens'),
                'wasted_split_input_tokens_counted' => (int) $reactiveParents->sum('token_count'),
                'wasted_split_output_tokens' => (int) $reactiveRuns->sum('output_tokens'),
                'wasted_split_cost_usd' => round((float) $reactiveRuns->sum('estimated_cost_usd'), 6),
                'wasted_split_settled_accounting_usd' => round((float) $reactiveParents->sum(fn ($chunk) => ($chunk->cost_accounting['settled'] ?? false)
                    ? $chunk->reserved_cost : 0), 6),
                'wasted_split_unpriced_calls' => $reactiveRuns->whereNull('estimated_cost_usd')->count(),
                'requests' => $runs->map(fn ($run) => ['document_id' => $run->document_id,
                    'provider_attempt_id' => $run->id, 'chunk_id' => $run->chunk_id,
                    'chunk_key' => $extraction->firstWhere('id', $run->chunk_id)?->identity,
                    'depth' => $extraction->firstWhere('id', $run->chunk_id)?->depth,
                    'logical_attempt' => $run->request_attempt, 'status' => $run->status,
                    'dispatch_reason' => $run->dispatch_reason,
                    'failure_class' => $run->failure_class, 'timeout_source' => $run->timeout_source,
                    'provider_response_received' => $run->provider_response_received,
                    'dispatched_at' => $run->dispatched_at?->toISOString(),
                    'worker_started_at' => $run->worker_started_at?->toISOString(),
                    'admission_requested_at' => $run->admission_requested_at?->toISOString(),
                    'lease_acquired_at' => $run->lease_acquired_at?->toISOString(),
                    'lease_released_at' => $run->lease_released_at?->toISOString(),
                    'worker_wait_ms' => $run->worker_wait_ms, 'fairness_wait_ms' => $run->fairness_wait_ms,
                    'admission_wait_ms' => $run->admission_wait_ms,
                    'started_at_estimate' => $run->created_at && $run->duration_ms !== null
                        ? $run->created_at->copy()->subMilliseconds((int) $run->duration_ms)->toISOString() : null,
                    'provider_started_at' => $run->provider_started_at?->toISOString(),
                    'provider_finished_at' => $run->provider_finished_at?->toISOString(),
                    'finished_at' => $run->created_at?->toISOString(), 'duration_ms' => $run->duration_ms,
                    'input_tokens_counted' => $run->input_tokens_counted,
                    'input_tokens' => $run->input_tokens, 'output_tokens' => $run->output_tokens,
                    'stop_reason' => $run->stop_reason,
                    'cost_usd' => $run->estimated_cost_usd,
                    'output_discarded' => $splitParents->contains('id', $run->chunk_id) && $run->status !== 'success'])->values()->all(),
            ],
            'chunks' => [
                'roots' => $extraction->whereNull('parent_id')->count(),
                'final_leaves' => $leaves->count(),
                'splits' => $extraction->where('status', 'split')->count(),
                'proactive_splits' => $proactiveParents->count(), 'reactive_splits' => $reactiveParents->count(),
                'proactive_savings' => null,
                'retries' => (int) $extraction->sum(fn ($chunk) => max(0, (int) $chunk->attempts - 1)),
                'source_spans' => $document->ai_pipeline['routing']['source_spans'] ?? null,
                'by_status' => $leaves->groupBy('status')->map->count()->sortKeys()->all(),
                'failure_classes' => $leaves->whereNotNull('failure_class')->groupBy('failure_class')->map->count()->sortKeys()->all(),
                'split_reasons' => $splitParents->groupBy('failure_class')->map->count()->sortKeys()->all(),
                'split_details' => $splitDetails,
                'queue_timing_observed_chunks' => $queueTimings->count(),
            ],
            'records' => [
                'returned' => $returned,
                'accepted' => $accepted,
                'rejected' => max(0, $returned - $accepted),
                'acceptance_rate' => $returned > 0 ? round($accepted / $returned * 100, 1) : null,
                'rejections' => $rejectionClasses,
                'rejection_reasons' => $rejectionReasons,
                'evidence_rows' => $key ? DocumentEvidence::where('document_id', $document->id)->where('pipeline_key', $key)->count() : 0,
            ],
            'grounding' => [
                'chunks_by_mode' => $grounding,
                'span_reference_sources' => $spanSources,
                'legacy_quote_sources' => $quoteSources,
                'quote_not_found_in_source' => (int) ($rejectionReasons['quote_not_found_in_source'] ?? 0),
                'span_id_rejections' => array_sum(array_intersect_key($rejectionReasons, array_fill_keys($groundingReasons, true))),
                'span_id_rejection_reasons' => array_intersect_key($rejectionReasons, array_fill_keys($groundingReasons, true)),
                'other_validation_rejections' => max(0, array_sum($rejectionReasons)
                    - (int) ($rejectionReasons['quote_not_found_in_source'] ?? 0)
                    - array_sum(array_intersect_key($rejectionReasons, array_fill_keys($groundingReasons, true)))),
            ],
        ];
    }

    /** Run rows are written at completion; their starts are reconstructed from duration_ms. */
    private function providerConcurrency($runs): array
    {
        $events = [];
        $sum = 0;
        foreach ($runs as $run) {
            if ((! $run->provider_started_at || ! $run->provider_finished_at)
                && (! $run->created_at || ! $run->duration_ms || $run->duration_ms < 0)) {
                continue;
            }
            $end = $run->provider_finished_at?->getTimestampMs() ?? $run->created_at->getTimestampMs();
            $start = $run->provider_started_at?->getTimestampMs() ?? $end - (int) $run->duration_ms;
            $events[] = [$start, 1];
            $events[] = [$end, -1];
            $sum += max(0, $end - $start);
        }
        if ($events === []) {
            return ['wall_ms' => null, 'average' => null, 'peak' => 0, 'one_percent' => null, 'two_plus_percent' => null];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $first = $events[0][0];
        $last = $first;
        $active = $peak = $one = $two = 0;
        foreach ($events as [$at, $change]) {
            $elapsed = max(0, $at - $last);
            if ($active === 1) {
                $one += $elapsed;
            } elseif ($active >= 2) {
                $two += $elapsed;
            }
            $active += $change;
            $peak = max($peak, $active);
            $last = $at;
        }
        $wall = max(1, $last - $first);

        return ['wall_ms' => $wall, 'average' => round($sum / $wall, 2), 'peak' => $peak,
            'one_percent' => round($one / $wall * 100, 1), 'two_plus_percent' => round($two / $wall * 100, 1)];
    }

    /** Wall-clock span of a set of processing-job attempts. */
    private function stageMs($stages, array $names): ?int
    {
        $rows = $stages->whereIn('stage', $names)->filter(fn ($job) => $job->started_at && $job->completed_at);

        return $rows->isEmpty() ? null : (int) $rows->min('started_at')->diffInMilliseconds($rows->max('completed_at'));
    }

    /** Wall-clock span of a set of chunks, which run concurrently. */
    private function spanMs($chunks): ?int
    {
        $rows = $chunks->filter(fn ($chunk) => $chunk->started_at && $chunk->completed_at);

        return $rows->isEmpty() ? null : (int) $rows->min('started_at')->diffInMilliseconds($rows->max('completed_at'));
    }

    private function render(array $report): void
    {
        $this->line('Document: '.$report['document']['name'].' ('.$report['document']['id'].')');
        $this->line('Route: '.$report['document']['route'].'/'.($report['document']['mode'] ?? '-')
            .'  Grounding: '.$report['document']['grounding']);
        $this->line('Result: '.$report['document']['result']);
        foreach ($report['document']['partial_reasons'] as $reason => $value) {
            $this->line('  coverage '.$reason.': '.$value);
        }
        $this->line('Total runtime: '.$this->duration($report['timing_ms']['total']));
        $this->newLine();
        $this->line('Time:');
        foreach (['scan' => 'scan', 'extraction' => 'extraction', 'incremental_analysis' => 'incremental analysis',
            'merge' => 'merge', 'synthesis' => 'synthesis', 'provider_total' => 'provider (sum of calls)',
            'provider_extraction_sum' => 'extraction provider sum', 'provider_wall_clock_interval' => 'extraction provider wall',
            'provider_slowest' => 'slowest provider call',
            'provider_median' => 'median provider call', 'queue_worker_wait' => 'queue worker wait (sum)',
            'fairness_wait' => 'fairness delay (sum)', 'provider_admission_wait' => 'capacity delay (sum)'] as $field => $label) {
            $this->line('  '.str_pad($label.':', 26).$this->duration($report['timing_ms'][$field]));
        }
        $this->newLine();
        $this->line('Provider calls: '.$report['provider']['calls'].' ('.$report['provider']['extraction_calls']
            .' extraction, '.$report['provider']['failed_calls'].' failed)');
        $this->line('Input tokens: '.number_format($report['provider']['input_tokens']));
        $this->line('Output tokens: '.number_format($report['provider']['output_tokens']));
        $this->line('Cache write/read tokens: '.number_format($report['provider']['cache_write_tokens'])
            .' / '.number_format($report['provider']['cache_read_tokens']));
        $this->line('Provider cost: $'.number_format($report['provider']['cost_usd'], 4)
            .($report['provider']['unpriced_calls'] ? ' ('.$report['provider']['unpriced_calls'].' unpriced)' : ''));
        $this->line('Output tokens / accepted record: '.($report['provider']['output_tokens_per_accepted_record'] ?? 'n/a'));
        $this->line('Effective provider parallelism: '.($report['provider']['effective_parallelism'] ?? 'n/a')
            .' (peak '.$report['provider']['peak_concurrency'].')');
        $this->line('Provider wall time at 1 / 2+ calls: '.($report['provider']['wall_time_at_concurrency_1_percent'] ?? 'n/a')
            .'% / '.($report['provider']['wall_time_at_concurrency_2_plus_percent'] ?? 'n/a').'%');
        $this->line('Useful leaf / split-parent calls: '.$report['provider']['useful_successful_leaf_calls']
            .' / '.$report['provider']['split_parent_calls']);
        $this->line('Failed / timeout / repeat calls: '.$report['provider']['failed_calls'].' / '
            .$report['provider']['timeout_calls'].' / '.$report['provider']['repeat_calls']);
        $this->line('Wasted split work: '.$this->duration($report['provider']['wasted_split_provider_ms'])
            .', '.number_format($report['provider']['wasted_split_input_tokens']).' actual input ('
            .number_format($report['provider']['wasted_split_input_tokens_counted']).' counted), '
            .number_format($report['provider']['wasted_split_output_tokens']).' output tokens, $'
            .number_format($report['provider']['wasted_split_cost_usd'], 4)
            .($report['provider']['wasted_split_unpriced_calls'] ? ' + '.$report['provider']['wasted_split_unpriced_calls'].' unpriced calls' : '')
            .' (settled accounting $'.number_format($report['provider']['wasted_split_settled_accounting_usd'], 4).')');
        $this->newLine();
        $this->line('Chunks:');
        $this->line('  roots: '.$report['chunks']['roots']);
        $this->line('  final leaves: '.$report['chunks']['final_leaves']);
        $this->line('  splits: '.$report['chunks']['splits']);
        $this->line('  proactive / reactive splits: '.$report['chunks']['proactive_splits'].' / '.$report['chunks']['reactive_splits']);
        $this->line('  proactive savings: unmeasured without a comparable parent attempt');
        $this->line('  retries: '.$report['chunks']['retries']);
        $this->line('  queue timing observed: '.$report['chunks']['queue_timing_observed_chunks'].' chunks');
        foreach ($report['chunks']['split_reasons'] as $reason => $count) {
            $this->line('  split '.$reason.': '.$count);
        }
        if ($report['chunks']['source_spans'] !== null) {
            $this->line('  source spans: '.$report['chunks']['source_spans']);
        }
        foreach ($report['chunks']['by_status'] as $status => $count) {
            $this->line('  '.$status.': '.$count);
        }
        foreach ($report['chunks']['failure_classes'] as $class => $count) {
            $this->line('  failure '.$class.': '.$count);
        }
        $this->newLine();
        $this->line('Records:');
        $this->line('  returned: '.$report['records']['returned']);
        $this->line('  accepted: '.$report['records']['accepted']);
        $this->line('  rejected: '.$report['records']['rejected']);
        $this->line('  acceptance: '.($report['records']['acceptance_rate'] === null ? 'n/a' : $report['records']['acceptance_rate'].'%'));
        $this->line('  evidence rows: '.$report['records']['evidence_rows']);
        foreach ($report['records']['rejections'] as $class => $count) {
            $this->line('  '.$class.': '.$count);
        }
        foreach ($report['records']['rejection_reasons'] as $reason => $count) {
            $this->line('    '.$reason.': '.$count);
        }
        $this->newLine();
        $this->line('Grounding:');
        foreach ($report['grounding']['chunks_by_mode'] as $mode => $count) {
            $this->line('  chunks in '.$mode.': '.$count);
        }
        $this->line('  span-reference sources: '.$report['grounding']['span_reference_sources']);
        $this->line('  legacy quote sources: '.$report['grounding']['legacy_quote_sources']);
        $this->line('  quote_not_found_in_source: '.$report['grounding']['quote_not_found_in_source']);
        $this->line('  unknown/invalid span IDs: '.$report['grounding']['span_id_rejections']);
        foreach ($report['grounding']['span_id_rejection_reasons'] as $reason => $count) {
            $this->line('    '.$reason.': '.$count);
        }
        $this->line('  other validation rejects: '.$report['grounding']['other_validation_rejections']);
    }

    private function duration(?int $ms): string
    {
        if ($ms === null) {
            return 'n/a';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }
        $seconds = intdiv($ms, 1000);

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }
}
