<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\ExtractionCapacity;
use Illuminate\Console\Command;

class DocumentAiBenchmark extends Command
{
    protected $signature = 'docintel:benchmark {document?} {--text= : Local UTF-8 fixture, planning only}';

    protected $description = 'Read-only AI benchmark metrics or local chunk planning; never calls a provider';

    public function handle(ChunkPlanner $planner): int
    {
        if (app()->environment('production')) {
            $this->error('Benchmarking is restricted to development/staging.');

            return self::FAILURE;
        }
        $started = hrtime(true);
        if ($path = $this->option('text')) {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error('Unreadable text fixture.');

                return self::FAILURE;
            }
            $text = file_get_contents($path);
            $large = $planner->estimate($text) > config('document_intelligence.large_tokens') || mb_strlen($text) > config('document_processing.max_extraction_chars');
            $routing = $large ? app(ExtractionCapacity::class)->decide($planner->estimate($text)) : null;
            $chunks = $large ? $planner->partition($text, $routing['partition_tokens']) : [];
            $this->line(json_encode(['mode' => 'planning_only', 'estimated_tokens' => $planner->estimate($text),
                'route' => $large ? 'incremental' : 'normal', 'routing' => $routing, 'chunk_count' => count($chunks),
                'planned_text_generation_requests' => $large ? count($chunks) + 1 : 6, 'optional_requests' => 'not estimated',
                'duration_ms' => (hrtime(true) - $started) / 1000000, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $document = Document::find($this->argument('document'));
        if (! $document) {
            $this->error('Provide a document ID or --text path.');

            return self::FAILURE;
        }
        $runs = DocumentAiRun::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->get();
        $chunks = DocumentChunk::where('document_id', $document->id)->get();
        $evidence = DocumentEvidence::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->get();
        $validSources = $evidence->filter(function ($item) use ($document) {
            foreach ($item->sources as $source) {
                if (mb_substr($document->extracted_text, $source['start_offset'], $source['end_offset'] - $source['start_offset']) !== ($source['quote'] ?? $item->data['quote'])) {
                    return false;
                }
            }

            return count($item->sources) > 0;
        })->count();
        $visuals = $chunks->where('stage', 'visual');
        $stages = $document->processingJobs()->get();
        $finished = collect([$stages->max('completed_at'), $chunks->max('completed_at')])->filter()->max();
        $summary = $document->intelligenceSummary;
        $optional = $summary ? count($summary->trends ?? []) + count($summary->tensions ?? []) + count($summary->questions ?? []) + count($summary->material_findings ?? []) : 0;
        $dropped = $runs->sum('optional_items_dropped');
        $this->line(json_encode([
            'document_id' => $document->id, 'pipeline' => $document->ai_pipeline, 'status' => $document->status,
            'provider_request_count' => $runs->count(), 'input_tokens' => $runs->sum('input_tokens'), 'output_tokens' => $runs->sum('output_tokens'),
            'cache_write_tokens' => $runs->sum('cache_creation_tokens'), 'cache_read_tokens' => $runs->sum('cache_read_tokens'),
            'estimated_cost_usd' => $runs->sum('estimated_cost_usd'), 'unpriced_calls' => $runs->whereNull('estimated_cost_usd')->count(),
            'provider_duration_ms' => $runs->sum('duration_ms'),
            'worker_process_peak_memory_bytes' => $runs->max('process_peak_memory_bytes'),
            'elapsed_ms' => $runs->isEmpty() ? null : $runs->min('created_at')->diffInMilliseconds($runs->max('created_at')),
            'upload_to_last_completed_stage_ms' => $finished ? $document->created_at->diffInMilliseconds($finished) : null,
            'processing_stage_attempt_count' => $stages->count(),
            'provider_retry_count' => $runs->filter(fn ($r) => $r->request_attempt > 1)->count(),
            'retry_count' => $chunks->sum(fn ($c) => max(0, $c->attempts - 1)), 'failed_requests' => $runs->where('status', '!=', 'success')->count(),
            'chunk_count' => $chunks->where('stage', 'extraction')->count(), 'job_attempt_count' => $chunks->sum('attempts'),
            'entity_count' => $document->entities()->count(), 'kpi_count' => $document->kpis()->count(),
            'deadline_count' => $document->deadlines()->count(), 'obligation_count' => $document->deadlines()->where('deadline_type', 'obligation')->count(),
            'source_validity_rate' => $evidence->count() ? $validSources / $evidence->count() : null,
            'optional_item_rejection_rate' => $optional + $dropped ? $dropped / ($optional + $dropped) : null,
            'visual_completion_rate' => $visuals->count() ? $visuals->where('status', 'completed')->count() / $visuals->count() : null,
            'summary_present' => (bool) $summary, 'summary_completeness' => 'Requires human evaluation against source document',
            'report_peak_memory_bytes' => memory_get_peak_usage(true),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
