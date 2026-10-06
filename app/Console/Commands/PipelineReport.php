<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\ProcessingJob;
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
                'result' => ($document->ai_pipeline['partial'] ?? false) ? 'Partial' : ($document->status === 'Completed' ? 'Complete' : $document->status)],
            'timing_ms' => [
                'total' => $finished ? $document->created_at->diffInMilliseconds($finished) : null,
                'scan' => $this->stageMs($stages, ['virus_scan']),
                'extraction' => $this->stageMs($stages, ['extract', 'ocr_check']),
                'incremental_analysis' => $this->spanMs($extraction),
                'merge' => $this->spanMs($chunks->where('stage', 'merge')),
                'synthesis' => $this->spanMs($chunks->where('stage', 'synthesis')) ?? $this->stageMs($stages, ['document_summary']),
                'provider_total' => (int) $runs->sum('duration_ms'),
                'provider_slowest' => (int) $runs->max('duration_ms'),
                'provider_median' => $durations->isEmpty() ? null : (int) $durations[intdiv($durations->count(), 2)],
            ],
            'provider' => [
                'calls' => $runs->count(),
                'extraction_calls' => $runs->whereNotNull('chunk_id')->count(),
                'failed_calls' => $runs->where('status', '!=', 'success')->count(),
                'input_tokens' => (int) $runs->sum('input_tokens'),
                'output_tokens' => (int) $runs->sum('output_tokens'),
                'cache_write_tokens' => (int) $runs->sum('cache_creation_tokens'),
                'cache_read_tokens' => (int) $runs->sum('cache_read_tokens'),
                'cost_usd' => round((float) $runs->sum('estimated_cost_usd'), 4),
                'unpriced_calls' => $runs->whereNull('estimated_cost_usd')->count(),
                'output_tokens_per_accepted_record' => $accepted > 0 ? round($runs->sum('output_tokens') / $accepted, 1) : null,
            ],
            'chunks' => [
                'roots' => $extraction->whereNull('parent_id')->count(),
                'final_leaves' => $leaves->count(),
                'splits' => $extraction->where('status', 'split')->count(),
                'retries' => (int) $extraction->sum(fn ($chunk) => max(0, (int) $chunk->attempts - 1)),
                'source_spans' => $document->ai_pipeline['routing']['source_spans'] ?? null,
                'by_status' => $leaves->groupBy('status')->map->count()->sortKeys()->all(),
                'failure_classes' => $leaves->whereNotNull('failure_class')->groupBy('failure_class')->map->count()->sortKeys()->all(),
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
        $this->line('Total runtime: '.$this->duration($report['timing_ms']['total']));
        $this->newLine();
        $this->line('Time:');
        foreach (['scan' => 'scan', 'extraction' => 'extraction', 'incremental_analysis' => 'incremental analysis',
            'merge' => 'merge', 'synthesis' => 'synthesis', 'provider_total' => 'provider (sum of calls)',
            'provider_slowest' => 'slowest provider call', 'provider_median' => 'median provider call'] as $field => $label) {
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
        $this->newLine();
        $this->line('Chunks:');
        $this->line('  roots: '.$report['chunks']['roots']);
        $this->line('  final leaves: '.$report['chunks']['final_leaves']);
        $this->line('  splits: '.$report['chunks']['splits']);
        $this->line('  retries: '.$report['chunks']['retries']);
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
