<?php

namespace App\Services\AiCredits;

use Illuminate\Support\Facades\DB;

/**
 * Measured provider cost per Ready document against its size, for calibrating the quote gate. Read-only: it selects
 * ids, types, counts, tokens and USD. Never document names, text, prompts or quotes, and it changes no quoting or
 * charging. Cost is the sum of document_ai_runs.estimated_cost_usd (tokens x configured price), not the invoice.
 */
class CostVsSizeReport
{
    /** Bucket upper bounds in quote tokens (inclusive); the last bucket is open. */
    public const BUCKETS = [
        '0-1k' => 1000, '1-2k' => 2000, '2-4k' => 4000, '4-6k' => 6000, '6-10k' => 10000,
        '10-20k' => 20000, '20-30k' => 30000, '30-60k' => 60000, '60k+' => PHP_INT_MAX,
    ];

    public const COLUMNS = ['document_id', 'file_type', 'pages', 'quote_tokens', 'local_estimate_tokens', 'provider_tokens', 'route', 'ocr_pages',
        'total_cost_usd', 'calls', 'first_try_cost_usd', 'first_try_calls', 'failed_or_retried_calls'];

    public function __construct(private QuoteService $quotes) {}

    /**
     * One row per Ready document with at least one priced run.
     *
     * "First try" = a successful run on its first request attempt. A run that failed, or that is a retry or split
     * (request_attempt > 1), is counted in the total but not in first_try, so the difference is what retries and
     * failures added.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?int $days = null): array
    {
        $since = $days === null ? '' : ' and d.created_at >= ?';
        $records = DB::select("
            select d.id, d.type, d.pages,
                   octet_length(d.extracted_text) as bytes,
                   d.ai_pipeline::jsonb ->> 'count_method' as count_method,
                   d.ai_pipeline::jsonb ->> 'tokens' as pipeline_tokens,
                   d.ai_pipeline::jsonb ->> 'route' as route,
                   (select count(distinct o.page_number) from ocr_results o where o.document_id = d.id) as ocr_pages,
                   sum(r.estimated_cost_usd) as total_cost,
                   count(*) as calls,
                   sum(case when r.status = 'success' and r.request_attempt = 1 then r.estimated_cost_usd else 0 end) as first_try_cost,
                   sum(case when r.status = 'success' and r.request_attempt = 1 then 1 else 0 end) as first_try_calls
            from documents d
            join document_ai_runs r on r.document_id = d.id
            where d.status = 'Ready' and d.deleted_at is null
              and r.estimated_cost_usd > 0
              and r.purpose not in ('document_qa', 'document_comparison')".$since.'
            group by d.id
            order by d.created_at, d.id', $days === null ? [] : [now()->subDays($days)]);

        $rows = [];
        foreach ($records as $r) {
            $bytes = (int) $r->bytes;
            $ocrPages = (int) $r->ocr_pages;
            $provider = $r->count_method === 'anthropic' && is_numeric($r->pipeline_tokens) ? (int) $r->pipeline_tokens : null;
            $rows[] = [
                'document_id' => $r->id,
                'file_type' => $r->type,
                'pages' => (int) $r->pages,
                'quote_tokens' => $this->quotes->estimateTokensFor($r->type, $bytes, $provider, $ocrPages),
                'local_estimate_tokens' => $this->quotes->estimateTokensFor($r->type, $bytes, null, $ocrPages),
                'provider_tokens' => $provider,
                'route' => $r->route,
                'ocr_pages' => $ocrPages,
                'total_cost_usd' => round((float) $r->total_cost, 6),
                'calls' => (int) $r->calls,
                'first_try_cost_usd' => round((float) $r->first_try_cost, 6),
                'first_try_calls' => (int) $r->first_try_calls,
                'failed_or_retried_calls' => (int) $r->calls - (int) $r->first_try_calls,
            ];
        }

        return $rows;
    }

    /**
     * Per token bucket: count, median, p90 and max cost, and what the CURRENT gate and band structure make of the p90.
     *
     * credits_for_p90 is what the provider cap must be worth to pay the p90 cost (cost / provider_cost_usd_per_credit,
     * rounded up); band_for_p90 is the cheapest band that has at least that many credits. The same for p90 x 1.25.
     * gate_usd is the gate's required cap at the bucket's median size (QuoteService::minCompletionCostUsd) and gate_band
     * the band the gate then yields for a document of that size, so the measured and the assumed cost sit side by side.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function summary(array $rows, float $stress = 1.25): array
    {
        $perCredit = (float) config('ai_credits.provider_cost_usd_per_credit');
        $grouped = array_fill_keys(array_keys(self::BUCKETS), []);
        foreach ($rows as $row) {
            $grouped[$this->bucket((int) $row['quote_tokens'])][] = $row;
        }

        $out = [];
        foreach ($grouped as $bucket => $items) {
            if ($items === []) {
                $out[] = ['bucket' => $bucket, 'documents' => 0, 'median_usd' => null, 'p90_usd' => null, 'max_usd' => null,
                    'credits_for_p90' => null, 'band_for_p90' => null, 'credits_for_p90_x'.$stress => null, 'band_for_p90_x'.$stress => null,
                    'median_tokens' => null, 'gate_usd' => null, 'gate_band' => null];

                continue;
            }
            $costs = array_map(fn ($r) => (float) $r['total_cost_usd'], $items);
            $tokens = array_map(fn ($r) => (int) $r['quote_tokens'], $items);
            $p90 = $this->quantile($costs, 0.90);
            $medianTokens = (int) round($this->quantile($tokens, 0.50));
            $gate = $this->quotes->classify($medianTokens, false);
            $needed = fn (float $usd) => (int) ceil(round($usd / $perCredit, 6));
            $out[] = ['bucket' => $bucket, 'documents' => count($items), 'median_usd' => round($this->quantile($costs, 0.50), 4),
                'p90_usd' => round($p90, 4), 'max_usd' => round(max($costs), 4),
                'credits_for_p90' => $needed($p90), 'band_for_p90' => $this->bandFor($needed($p90)),
                'credits_for_p90_x'.$stress => $needed($p90 * $stress), 'band_for_p90_x'.$stress => $this->bandFor($needed($p90 * $stress)),
                'median_tokens' => $medianTokens, 'gate_usd' => $this->quotes->minCompletionCostUsd($medianTokens),
                'gate_band' => $gate['declined'] ? 'declined' : $gate['band']];
        }

        return $out;
    }

    public function bucket(int $tokens): string
    {
        foreach (self::BUCKETS as $name => $max) {
            if ($tokens <= $max) {
                return $name;
            }
        }

        return '60k+';
    }

    /** The cheapest band with at least this many credits, or 'above_top_band'. */
    public function bandFor(int $credits): string
    {
        foreach (config('ai_credits.bands') as $name => $band) {
            if ((int) $band['credits'] >= $credits) {
                return $name;
            }
        }

        return 'above_top_band';
    }

    /** Linear interpolation between ranks, as CreditEconomicsReport does. */
    public function quantile(array $values, float $q): float
    {
        sort($values);
        $count = count($values);
        $pos = $count > 1 ? $q * ($count - 1) : 0;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($c) => $row[$c] ?? '', self::COLUMNS));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
