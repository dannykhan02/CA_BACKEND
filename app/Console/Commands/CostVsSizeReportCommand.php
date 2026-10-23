<?php

namespace App\Console\Commands;

use App\Services\AiCredits\CostVsSizeReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures provider cost against document size, per stage, and against what the current quote would charge, to calibrate the bands. Read-only (a READ ONLY transaction on
 * PostgreSQL) and metadata-only: ids, types, counts, tokens and USD. It changes no quoting or charging.
 */
class CostVsSizeReportCommand extends Command
{
    protected $signature = 'docintel:cost-vs-size-report
        {--days= : Only documents created in the last N days (default: all Ready documents)}
        {--summary-only : Print only the per-bucket summary, not one CSV row per document}
        {--skip-would-quote : Do not compute what the current quote would charge each document (faster, because that reads the text of every document)}
        {--stress=1.25 : Multiplier applied to the p90 cost for the second credits column}';

    protected $description = 'Read-only: provider cost per Ready document against its token size, with a per-bucket summary and the credits the p90 needs';

    public function handle(CostVsSizeReport $report): int
    {
        $days = $this->option('days') === null ? null : max(1, (int) $this->option('days'));
        $stress = max(1.0, (float) $this->option('stress'));
        $rows = DB::transaction(function () use ($report, $days) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            return $report->rows($days, false);
        });
        if (! $this->option('skip-would-quote')) {
            // The quote logic only reads, but it is application code with caches behind it, so whatever it might touch is rolled back.
            DB::beginTransaction();
            try {
                $rows = $report->withWouldQuote($rows);
            } finally {
                DB::rollBack();
            }
        }

        if (! $this->option('summary-only')) {
            $this->line(rtrim($report->csv($rows)));
            $this->newLine();
        }
        $summary = $report->summary($rows, $stress);
        $this->info(sprintf('%d Ready documents with priced runs. Cost = sum of estimated_cost_usd (tokens x configured price). Provider cost per credit: USD %s.',
            count($rows), config('ai_credits.provider_cost_usd_per_credit')));
        $this->table(array_keys($summary[0]), array_map('array_values', $summary));
        $this->line('Bands by token edge: '.implode(', ', array_map(fn ($name, $b) => sprintf('%s <= %d tokens = %d credits (cap USD %s)', $name, $b['max_tokens'], $b['credits'], $b['credits'] * (float) config('ai_credits.provider_cost_usd_per_credit')),
            array_keys(config('ai_credits.bands')), config('ai_credits.bands'))).'. Nothing was changed.');

        return self::SUCCESS;
    }
}
