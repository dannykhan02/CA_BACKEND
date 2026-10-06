<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 cost measurements from persisted provider usage. Read-only (enforced
 * with a READ ONLY transaction) and metadata-only: IDs, counts, tokens and USD.
 * Never prints document names, text, prompts, quotes or evidence.
 */
class DocumentAiUsageReport extends Command
{
    protected $signature = 'docintel:ai-usage-report {document? : Limit to one document ID}
        {--days=7 : Look-back window in days} {--limit=10 : Rows in per-document rankings} {--json : Machine-readable output}';

    protected $description = 'Read-only AI token/cost report: spend by day/model/purpose, amplification, outcomes, waste, retries';

    public function handle(): int
    {
        $report = DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            return $this->measure();
        });

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($report as $section => $rows) {
            $this->info(str_replace('_', ' ', $section));
            if ($rows === []) {
                $this->line('  (no rows)');

                continue;
            }
            $this->table(array_keys((array) $rows[0]), array_map(fn ($row) => array_values((array) $row), $rows));
        }

        return self::SUCCESS;
    }

    private function runs()
    {
        $query = DB::table('document_ai_runs as r')->where('r.created_at', '>=', now()->subDays((int) $this->option('days')));
        if ($this->argument('document')) {
            $query->where('r.document_id', $this->argument('document'));
        }

        return $query;
    }

    private function measure(): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $tokens = 'coalesce(r.input_tokens,0) + coalesce(r.output_tokens,0) + coalesce(r.cache_creation_tokens,0) + coalesce(r.cache_read_tokens,0)';
        $sums = 'count(*) as calls, sum(coalesce(r.input_tokens,0)) as input_tokens, sum(coalesce(r.output_tokens,0)) as output_tokens,
            sum(coalesce(r.cache_creation_tokens,0)) as cache_write_tokens, sum(coalesce(r.cache_read_tokens,0)) as cache_read_tokens,
            round(sum(coalesce(r.estimated_cost_usd,0))::numeric, 4) as cost_usd, sum(case when r.estimated_cost_usd is null then 1 else 0 end) as unpriced_calls';

        // Leaf-linked runs are labelled by their pipeline stage; other runs keep their purpose.
        $purpose = 'coalesce(c.stage, r.purpose)';

        $byDay = $this->runs()->leftJoin('document_chunks as c', 'c.id', '=', 'r.chunk_id')
            ->selectRaw("date(r.created_at) as day, r.model, {$purpose} as purpose, {$sums}")
            ->groupByRaw("date(r.created_at), r.model, {$purpose}")->orderByRaw('1, 2, 3')->get()->all();

        $perDocument = $this->runs()->join('documents as d', 'd.id', '=', 'r.document_id')
            ->selectRaw("r.document_id, d.ai_pipeline->>'mode' as mode, (d.ai_pipeline->>'tokens')::bigint as document_tokens,
                coalesce((d.ai_pipeline->>'analysis_revision')::int, 0) as reanalyses, {$sums}, sum({$tokens}) as provider_tokens,
                round(sum({$tokens})::numeric / nullif((d.ai_pipeline->>'tokens')::bigint, 0), 2) as amplification")
            ->groupByRaw("r.document_id, d.ai_pipeline->>'mode', d.ai_pipeline->>'tokens', d.ai_pipeline->>'analysis_revision'")
            ->orderByRaw('amplification desc nulls last, provider_tokens desc')->limit($limit)->get()->all();

        $callOutcomes = $this->runs()->leftJoin('document_chunks as c', 'c.id', '=', 'r.chunk_id')
            ->selectRaw("r.document_id, coalesce(r.failure_class, r.status, 'unknown') as call_outcome, {$sums}")
            ->groupByRaw("r.document_id, coalesce(r.failure_class, r.status, 'unknown')")->orderByRaw('1, 2')->get()->all();

        $leaves = DB::table('document_chunks as c')->where('c.stage', 'extraction')
            ->when($this->argument('document'), fn ($q, $id) => $q->where('c.document_id', $id))
            ->whereIn('c.document_id', $this->runs()->select('r.document_id'))
            ->selectRaw("c.document_id, c.status, coalesce(c.failure_class, '-') as failure_class, count(*) as chunks,
                max(c.depth) as max_depth, sum(c.attempts) as attempts, round(sum(c.reserved_cost)::numeric, 4) as committed_usd")
            ->groupByRaw('c.document_id, c.status, c.failure_class')->orderByRaw('1, 2, 3')->get()->all();

        // Spend that produced no usable output: failed calls, and calls on leaves that ended split/failed/budget/uncertain.
        $wasted = $this->runs()->leftJoin('document_chunks as c', 'c.id', '=', 'r.chunk_id')
            ->where(fn ($q) => $q->where('r.status', '!=', 'success')
                ->orWhereIn('c.status', ['split', 'failed', 'budget', 'uncertain', 'superseded']))
            ->selectRaw("r.document_id, coalesce(c.status, '-') as leaf_status, coalesce(r.failure_class, c.failure_class, '-') as reason, {$sums}")
            ->groupByRaw("r.document_id, coalesce(c.status, '-'), coalesce(r.failure_class, c.failure_class, '-')")
            ->orderByRaw('cost_usd desc')->limit($limit * 3)->get()->all();

        // Overlap characters re-sent, converted with each document's own tokens-per-character density.
        $overlap = DB::table('document_chunks as c')->join('documents as d', 'd.id', '=', 'c.document_id')
            ->where('c.stage', 'extraction')->where('c.attempts', '>', 0)->where('c.overlap_chars', '>', 0)
            ->whereIn('c.document_id', $this->runs()->select('r.document_id'))
            ->selectRaw("c.document_id, sum(c.overlap_chars * c.attempts) as overlap_chars_sent,
                round(sum(c.overlap_chars * c.attempts) * (d.ai_pipeline->>'tokens')::numeric / nullif(char_length(d.extracted_text), 0)) as overlap_tokens_sent")
            ->groupByRaw("c.document_id, d.ai_pipeline->>'tokens', char_length(d.extracted_text)")->get()->all();

        $retries = $this->runs()->where('r.request_attempt', '>', 1)
            ->selectRaw("r.document_id, r.model, {$sums}")->groupByRaw('r.document_id, r.model')->get()->all();

        // A completed leaf billed more than once would mean a job retry/restart re-called finished work.
        $recalled = $this->runs()->join('document_chunks as c', 'c.id', '=', 'r.chunk_id')->where('c.stage', 'extraction')
            ->selectRaw("r.chunk_id, r.document_id, count(*) as calls, sum(case when r.status = 'success' then 1 else 0 end) as successful_calls")
            ->groupByRaw('r.chunk_id, r.document_id')->havingRaw("sum(case when r.status = 'success' then 1 else 0 end) > 1")
            ->get()->all();

        return ['spend_by_day_model_purpose' => $byDay, 'worst_documents_by_amplification' => $perDocument,
            'provider_calls_by_outcome' => $callOutcomes, 'extraction_leaves_by_outcome' => $leaves,
            'wasted_spend' => $wasted, 'overlap_resent' => $overlap, 'retry_spend' => $retries,
            'completed_leaves_recalled' => $recalled];
    }
}
