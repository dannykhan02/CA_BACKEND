<?php

namespace App\Services\AiCredits;

use App\Models\Document;
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

    /** Cost per stage, summed over a document's runs (stage = what the purpose is for, see stageOf()). */
    public const STAGE_COLUMNS = ['stage_extraction_usd', 'stage_synthesis_usd', 'stage_ocr_usd', 'stage_chart_vision_usd', 'stage_other_usd',
        'largest_request_usd', 'largest_request_purpose'];

    /** What the CURRENT QuoteService would quote this document, computed and thrown away. */
    public const WOULD_COLUMNS = ['would_tokens', 'token_source', 'would_band', 'would_credits', 'would_cap_usd', 'synthesis_floor_usd', 'would_route'];

    /** Incremental-route documents only: the pipeline's synthesis reservation against what synthesis really cost. */
    public const INCREMENTAL_COLUMNS = ['reservation_floor_usd', 'real_synthesis_cost_usd', 'floor_over_real'];

    public function __construct(private QuoteService $quotes) {}

    /**
     * Which stage a run's purpose belongs to. extraction = the purposes the config gives the extraction model role (chunks,
     * insights, entities, risks, deadlines, document type ...), synthesis = the synthesis role (document summary, brief). OCR
     * and chart vision are reported on their own. Anything else keeps being reported, under its real purpose value in the
     * per-purpose columns and as "other" here.
     */
    public function stageOf(string $purpose): string
    {
        return match (true) {
            $purpose === 'ocr' => 'ocr',
            $purpose === 'chart_vision' => 'chart_vision',
            (config('document_intelligence.model_roles')[$purpose] ?? null) === 'synthesis' => 'synthesis',
            (config('document_intelligence.model_roles')[$purpose] ?? null) === 'extraction' => 'extraction',
            default => 'other',
        };
    }

    /**
     * One row per Ready document with at least one priced run.
     *
     * "First try" = a successful run on its first request attempt. A run that failed, or that is a retry or split
     * (request_attempt > 1), is counted in the total but not in first_try, so the difference is what retries and
     * failures added.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?int $days = null, bool $withQuote = true): array
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

        $this->addStages($rows);

        return $withQuote ? $this->withWouldQuote($rows) : $rows;
    }

    /**
     * The would-quote and incremental columns. Reads every document (its text feeds the density signal); writes nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withWouldQuote(array $rows): array
    {
        $this->addWouldQuote($rows);

        return $rows;
    }

    /**
     * Cost and model per purpose for each document, the same cost per stage, and the largest single request.
     * Purposes are reported under their real values; nothing is renamed or merged in `by_purpose`.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function addStages(array &$rows): void
    {
        $byDocument = [];
        foreach (array_chunk(array_column($rows, 'document_id'), 500) as $ids) {
            $records = DB::table('document_ai_runs')->whereIn('document_id', $ids)->where('estimated_cost_usd', '>', 0)
                ->whereNotIn('purpose', ['document_qa', 'document_comparison'])
                ->selectRaw("document_id, purpose, sum(estimated_cost_usd) as cost, count(*) as calls, max(estimated_cost_usd) as largest, string_agg(distinct model, '|' order by model) as models")
                ->groupBy('document_id', 'purpose')->orderBy('purpose')->get();
            foreach ($records as $record) {
                $byDocument[$record->document_id][$record->purpose] = ['cost' => round((float) $record->cost, 6), 'calls' => (int) $record->calls,
                    'largest' => round((float) $record->largest, 6), 'models' => (string) $record->models];
            }
        }
        foreach ($rows as &$row) {
            $purposes = $byDocument[$row['document_id']] ?? [];
            $stages = array_fill_keys(['extraction', 'synthesis', 'ocr', 'chart_vision', 'other'], 0.0);
            $largest = 0.0;
            $largestPurpose = null;
            foreach ($purposes as $purpose => $figures) {
                $stages[$this->stageOf($purpose)] += $figures['cost'];
                if ($figures['largest'] > $largest) {
                    $largest = $figures['largest'];
                    $largestPurpose = $purpose;
                }
            }
            $row['by_purpose'] = $purposes;
            foreach ($stages as $stage => $cost) {
                $row['stage_'.$stage.'_usd'] = round($cost, 6);
            }
            $row['largest_request_usd'] = $largest;
            $row['largest_request_purpose'] = $largestPurpose;
        }
        unset($row);
    }

    /**
     * What QuoteService would quote each document today (QuoteService::previewDocument: signals and classification, no quote row,
     * no reservation, no write of any kind), and for an incremental-route document the pipeline's synthesis reservation against
     * the synthesis cost that was really incurred.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function addWouldQuote(array &$rows): void
    {
        $index = [];
        foreach ($rows as $at => $row) {
            $index[$row['document_id']] = $at;
        }
        foreach (array_chunk(array_keys($index), 100) as $ids) {
            foreach (Document::whereIn('id', $ids)->get() as $document) {
                $row = &$rows[$index[$document->id]];
                $preview = $this->quotes->previewDocument($document);
                $c = $preview['classification'];
                $signals = $preview['signals'];
                $row['would_tokens'] = $signals['tokens'];
                $row['token_source'] = $signals['token_source'];
                $row['would_band'] = $c['declined'] ? 'declined' : $c['band'];
                $row['would_credits'] = $c['declined'] ? null : $c['credits'];
                $row['would_cap_usd'] = $c['declined'] ? null : $c['cap'];
                $row['synthesis_floor_usd'] = $signals['synthesis_floor_usd'];
                $row['would_route'] = $signals['incremental_route'] ? 'incremental' : 'normal';
                if ($row['route'] === 'incremental') {
                    $floor = $this->quotes->synthesisFloorUsd($document);
                    $row['reservation_floor_usd'] = round($floor, 6);
                    $row['real_synthesis_cost_usd'] = $row['stage_synthesis_usd'];
                    $row['floor_over_real'] = $row['stage_synthesis_usd'] > 0 ? round($floor / $row['stage_synthesis_usd'], 2) : null;
                }
                unset($row);
            }
        }
    }

    /**
     * Per token bucket: count, median, p90 and max cost, and what the CURRENT bands make of the p90.
     *
     * credits_for_p90 is what the provider cap must be worth to pay the p90 cost (cost / provider_cost_usd_per_credit,
     * rounded up); band_for_p90 is the cheapest band that has at least that many credits. The same for p90 x 1.25.
     * edge_band is the band the quote actually gives a document of the bucket's median size (QuoteService::classify), edge_cap_usd
     * its spend cap, and edge_covers_max / edge_covers_p90_x1.25 whether that cap pays the bucket's worst / p90 x 1.25 cost, so
     * the measured cost and the price sit side by side.
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
                    'median_tokens' => null, 'edge_band' => null, 'edge_cap_usd' => null, 'edge_covers_max' => null, 'edge_covers_p90_x'.$stress => null,
                    'median_would_credits' => null, 'median_would_cap_over_cost' => null, 'would_minus_needed_x'.$stress => null];

                continue;
            }
            $costs = array_map(fn ($r) => (float) $r['total_cost_usd'], $items);
            $tokens = array_map(fn ($r) => (int) $r['quote_tokens'], $items);
            $p90 = $this->quantile($costs, 0.90);
            $medianTokens = (int) round($this->quantile($tokens, 0.50));
            $edge = $this->quotes->classify($medianTokens, false);
            $needed = fn (float $usd) => (int) ceil(round($usd / $perCredit, 6));
            // What the quote would charge these documents (only when the rows carry it), and how far its cap is above what they cost.
            $would = array_values(array_filter(array_map(fn ($r) => $r['would_credits'] ?? null, $items), fn ($c) => $c !== null));
            $ratios = array_values(array_filter(array_map(fn ($r) => isset($r['would_cap_usd']) && $r['total_cost_usd'] > 0 ? $r['would_cap_usd'] / $r['total_cost_usd'] : null, $items), fn ($x) => $x !== null));
            $medianWould = $would === [] ? null : round($this->quantile($would, 0.50), 2);
            $out[] = ['bucket' => $bucket, 'documents' => count($items), 'median_usd' => round($this->quantile($costs, 0.50), 4),
                'p90_usd' => round($p90, 4), 'max_usd' => round(max($costs), 4),
                'credits_for_p90' => $needed($p90), 'band_for_p90' => $this->bandFor($needed($p90)),
                'credits_for_p90_x'.$stress => $needed($p90 * $stress), 'band_for_p90_x'.$stress => $this->bandFor($needed($p90 * $stress)),
                'median_tokens' => $medianTokens, 'edge_band' => $edge['declined'] ? 'declined' : $edge['band'],
                'edge_cap_usd' => $edge['declined'] ? null : $edge['cap'],
                'edge_covers_max' => $edge['declined'] ? null : $edge['cap'] >= max($costs),
                'edge_covers_p90_x'.$stress => $edge['declined'] ? null : $edge['cap'] >= $p90 * $stress,
                'median_would_credits' => $medianWould,
                'median_would_cap_over_cost' => $ratios === [] ? null : round($this->quantile($ratios, 0.50), 2),
                'would_minus_needed_x'.$stress => $medianWould === null ? null : round($medianWould - $needed($p90 * $stress), 2)];
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

    /**
     * Base columns, cost per stage, would-quote, incremental, then one cost_<purpose> and model_<purpose> pair for every purpose that
     * appears in any row (real purpose values, alphabetical). A column a row has no value for is empty.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function csvColumns(array $rows): array
    {
        $purposes = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['by_purpose'] ?? []) as $purpose) {
                $purposes[$purpose] = true;
            }
        }
        ksort($purposes);
        $dynamic = [];
        foreach (array_keys($purposes) as $purpose) {
            $dynamic[] = 'cost_'.$purpose;
            $dynamic[] = 'model_'.$purpose;
        }

        return [...self::COLUMNS, ...self::STAGE_COLUMNS, ...self::WOULD_COLUMNS, ...self::INCREMENTAL_COLUMNS, ...$dynamic];
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function csv(array $rows): string
    {
        $columns = $this->csvColumns($rows);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(function (string $column) use ($row) {
                if (str_starts_with($column, 'cost_') && ! in_array($column, [...self::COLUMNS, ...self::STAGE_COLUMNS], true)) {
                    return $row['by_purpose'][substr($column, 5)]['cost'] ?? '';
                }
                if (str_starts_with($column, 'model_')) {
                    return $row['by_purpose'][substr($column, 6)]['models'] ?? '';
                }

                return $row[$column] ?? '';
            }, $columns));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
