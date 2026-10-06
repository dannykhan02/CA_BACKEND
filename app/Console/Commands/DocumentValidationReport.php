<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only, count-only report for the current incremental analysis revision. */
class DocumentValidationReport extends Command
{
    protected $signature = 'docintel:validation-report {document : Document UUID} {--json : Machine-readable output}';

    protected $description = 'Summarize extraction acceptance and rejection reasons without reading document content';

    public function handle(): int
    {
        $report = DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $document = Document::query()->select('id', 'status', 'ai_pipeline')->findOrFail($this->argument('document'));
            $query = DocumentChunk::query()->where('document_id', $document->id)->where('stage', 'extraction');
            if ($key = $document->ai_pipeline['key'] ?? null) {
                $query->where('pipeline_key', $key);
            }
            $chunks = $query->get(['identity', 'status', 'result']);
            $statuses = ['completed' => 0, 'failed' => 0, 'budget' => 0, 'split' => 0];
            $classes = [];
            $reasons = [];
            $returned = $kept = $dropped = $unknown = 0;
            $worst = [];

            foreach ($chunks as $chunk) {
                $statuses[$chunk->status] = ($statuses[$chunk->status] ?? 0) + 1;
                if ($chunk->status === 'split') {
                    continue;
                }
                $result = $chunk->result ?? [];
                $validation = $result['_validation'] ?? null;
                if ($validation === null && ! isset($result['_returned_records']) && ! isset($result['records'])) {
                    $unknown++;

                    continue;
                }
                $chunkKept = (int) ($validation['records_kept'] ?? count($result['records'] ?? []));
                $chunkDropped = (int) ($validation['records_dropped'] ?? array_sum($result['_dropped_records'] ?? []));
                $chunkReturned = (int) ($validation['records_returned'] ?? $result['_returned_records'] ?? $chunkKept + $chunkDropped);
                $returned += $chunkReturned;
                $kept += $chunkKept;
                $dropped += $chunkDropped;
                foreach (($validation['rejections'] ?? $result['_dropped_records'] ?? []) as $name => $count) {
                    $classes[$name] = ($classes[$name] ?? 0) + $count;
                }
                foreach (($validation['rejection_reasons'] ?? []) as $name => $count) {
                    $reasons[$name] = ($reasons[$name] ?? 0) + $count;
                }
                if ($chunkReturned > 0) {
                    $top = $validation['rejection_reasons'] ?? [];
                    arsort($top);
                    $worst[] = ['chunk_key' => $chunk->identity, 'returned' => $chunkReturned,
                        'kept' => $chunkKept, 'dropped' => $chunkDropped,
                        'acceptance_percent' => round(100 * $chunkKept / $chunkReturned, 1),
                        'top_rejection_reason' => array_key_first($top)];
                }
            }
            arsort($classes);
            arsort($reasons);
            usort($worst, fn ($a, $b) => ($b['dropped'] <=> $a['dropped']) ?: strcmp($a['chunk_key'], $b['chunk_key']));

            return ['document_id' => $document->id, 'status' => $document->status,
                'total_leaves' => $chunks->where('status', '!=', 'split')->count(),
                'chunk_statuses' => $statuses, 'chunks_without_diagnostics' => $unknown,
                'records_returned' => $returned, 'records_accepted' => $kept, 'records_rejected' => $dropped,
                'acceptance_percent' => $returned ? round(100 * $kept / $returned, 1) : 0.0,
                'rejection_classes' => $classes, 'rejection_reasons' => $reasons,
                'worst_chunks' => array_slice($worst, 0, 10)];
        });

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->info('Validation report');
        $this->line("Document: {$report['document_id']} ({$report['status']})");
        $this->line('Leaves: '.$report['total_leaves'].'; completed: '.$report['chunk_statuses']['completed'].'; failed: '
            .$report['chunk_statuses']['failed'].'; budget: '.$report['chunk_statuses']['budget'].'; split: '.$report['chunk_statuses']['split']);
        $this->line("Records returned: {$report['records_returned']}; accepted: {$report['records_accepted']}; rejected: {$report['records_rejected']}");
        $this->line("Acceptance rate: {$report['acceptance_percent']}%; chunks without diagnostics: {$report['chunks_without_diagnostics']}");
        foreach (['Rejection classes' => $report['rejection_classes'], 'Rejection reasons' => $report['rejection_reasons']] as $title => $counts) {
            $this->info($title);
            foreach ($counts as $name => $count) {
                $this->line("  {$name}: {$count}");
            }
        }
        $this->info('Worst chunks');
        foreach ($report['worst_chunks'] as $chunk) {
            $this->line("  {$chunk['chunk_key']}: {$chunk['returned']} returned / {$chunk['kept']} kept / {$chunk['dropped']} dropped"
                ." ({$chunk['acceptance_percent']}%; top: ".($chunk['top_rejection_reason'] ?? 'unknown').')');
        }

        return self::SUCCESS;
    }
}
