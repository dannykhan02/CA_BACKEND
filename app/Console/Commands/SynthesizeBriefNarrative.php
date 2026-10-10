<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Intelligence\B2\NarrativeContextBuilder;
use App\Services\Intelligence\B2\NarrativeSynthesizer;
use App\Services\Intelligence\B2\StageASnapshot;
use Illuminate\Console\Command;

/**
 * Operator entry point for B2. `--dry-run` is the default and makes no provider call at all: it
 * reports the context the document would produce, its bounded token estimate and the attempt
 * identity, which is also what the offline evaluation harness reads.
 *
 * Metadata only: ids, counts, hashes and USD. Never narrative or evidence text.
 */
class SynthesizeBriefNarrative extends Command
{
    protected $signature = 'docintel:brief-narrative {document : Document ID}
        {--execute : Make the paid synthesis call instead of only reporting the planned context}
        {--json : Machine-readable output}';

    protected $description = 'Plan or run B2 verified narrative synthesis for one document (dry run by default)';

    public function handle(StageASnapshot $snapshots, NarrativeContextBuilder $contexts,
        NarrativeSynthesizer $synthesizer): int
    {
        $document = Document::find($this->argument('document'));
        if (! $document) {
            $this->error('Document not found.');

            return self::FAILURE;
        }
        if (! config('intelligence_v2.enabled') || ! config('intelligence_v2.b2.enabled')) {
            $this->error('Intelligence V2 B2 is disabled (DOCINTEL_INTELLIGENCE_V2 / DOCINTEL_V2_BRIEF_NARRATIVE).');

            return self::FAILURE;
        }

        $asOf = new \DateTimeImmutable;
        $snapshot = $snapshots->build($document, $asOf);
        $built = $contexts->build($snapshot);
        $model = $synthesizer->model();
        $report = [
            'document_id' => $document->id,
            'pipeline_key' => $snapshot['pipeline_key'],
            'stage_a_records' => count($snapshot['records']),
            'eligible_records' => $built['eligible'],
            'supplied_records' => count($built['supplied']),
            'omitted_records' => $built['omitted'],
            'key_figures' => count($snapshot['key_figure_ids']),
            'coverage_state' => $snapshot['coverage']['state'] ?? null,
            'context_tokens_estimated' => $contexts->tokens($built['context']),
            'max_output_tokens' => (int) config('intelligence_v2.b2.max_output_tokens'),
            'model' => $model,
            'contract_version' => (string) config('intelligence_v2.b2.contract_version'),
            'prompt_version' => (string) config('intelligence_v2.b2.prompt_version'),
            'input_hash' => $contexts->inputHash($built['context'], $model),
            'executed' => false,
        ];

        if ($this->option('execute')) {
            $outcome = $synthesizer->synthesize($document, $asOf);
            $report = [...$report, 'executed' => true, 'status' => $outcome['status'],
                'fallback_reason' => $outcome['fallback_reason'],
                'provider_called' => $outcome['provider_called'], 'chunk_id' => $outcome['unit_id']];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($report as $key => $value) {
            $this->line(str_pad(str_replace('_', ' ', $key), 28).': '
                .(is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '-')));
        }

        return self::SUCCESS;
    }
}
