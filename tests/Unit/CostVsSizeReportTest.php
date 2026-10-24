<?php

namespace Tests\Unit;

use App\Services\AiCredits\CostVsSizeReport;
use Tests\TestCase;

/** The pure part of docintel:cost-vs-size-report: bucketing, percentiles, and what the p90 means in credits and bands. */
class CostVsSizeReportTest extends TestCase
{
    private function row(int $tokens, float $cost, string $type = 'PDF'): array
    {
        return ['document_id' => 'doc-'.$tokens.'-'.$cost, 'file_type' => $type, 'pages' => 3, 'quote_tokens' => $tokens, 'local_estimate_tokens' => $tokens,
            'provider_tokens' => null, 'route' => 'normal', 'ocr_pages' => 0, 'total_cost_usd' => $cost, 'calls' => 4,
            'first_try_cost_usd' => $cost, 'first_try_calls' => 4, 'failed_or_retried_calls' => 0];
    }

    private function bucket(array $summary, string $name): array
    {
        return collect($summary)->firstWhere('bucket', $name);
    }

    public function test_tokens_fall_into_the_agreed_buckets_at_their_inclusive_upper_bounds(): void
    {
        $report = app(CostVsSizeReport::class);
        foreach ([[1, '0-1k'], [1000, '0-1k'], [1001, '1-2k'], [2000, '1-2k'], [2001, '2-4k'], [4000, '2-4k'], [4001, '4-6k'], [6000, '4-6k'],
            [6001, '6-10k'], [10000, '6-10k'], [10001, '10-20k'], [20000, '10-20k'], [20001, '20-30k'], [30000, '20-30k'],
            [30001, '30-60k'], [60000, '30-60k'], [60001, '60k+'], [900000, '60k+']] as [$tokens, $bucket]) {
            $this->assertSame($bucket, $report->bucket($tokens), (string) $tokens);
        }
    }

    public function test_quantiles_interpolate_like_the_credit_economics_report(): void
    {
        $report = app(CostVsSizeReport::class);
        $this->assertEqualsWithDelta(0.03, $report->quantile([0.10, 0.01, 0.04, 0.02, 0.03], 0.5), 1e-9);
        $this->assertEqualsWithDelta(0.076, $report->quantile([0.01, 0.02, 0.03, 0.04, 0.10], 0.9), 1e-9); // 0.04 + 0.6 x 0.06
        $this->assertSame(0.5, $report->quantile([0.5], 0.9));
    }

    public function test_bands_for_a_credit_amount_are_the_cheapest_band_with_at_least_that_many_credits(): void
    {
        $report = app(CostVsSizeReport::class);
        foreach ([[1, 'small'], [2, 'small'], [3, 'medium'], [6, 'medium'], [7, 'regular'], [10, 'regular'], [11, 'large'], [20, 'large'], [21, 'xlarge'],
            [35, 'xlarge'], [36, 'very_large'], [80, 'very_large'], [81, 'above_top_band']] as [$credits, $band]) {
            $this->assertSame($band, $report->bandFor($credits), (string) $credits);
        }
    }

    public function test_the_summary_gives_count_median_p90_max_and_the_credits_the_p90_needs(): void
    {
        $rows = [
            $this->row(2100, 0.01), $this->row(2500, 0.02), $this->row(3000, 0.03), $this->row(3500, 0.04), $this->row(3900, 0.10), // 2-4k
            $this->row(500, 0.005), $this->row(800, 0.015),                                                                          // 0-1k
            $this->row(70000, 1.0), $this->row(120000, 2.0),                                                                         // 60k+
        ];
        $summary = app(CostVsSizeReport::class)->summary($rows);
        $this->assertSame(['0-1k', '1-2k', '2-4k', '4-6k', '6-10k', '10-20k', '20-30k', '30-60k', '60k+'], array_column($summary, 'bucket'));

        $mid = $this->bucket($summary, '2-4k');
        $this->assertSame(5, $mid['documents']);
        $this->assertEqualsWithDelta(0.03, $mid['median_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.076, $mid['p90_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $mid['max_usd'], 1e-9);
        // 0.076 / 0.027 = 2.8 -> 3 credits -> medium (6); x1.25 = 0.095 -> 3.5 -> 4 credits -> medium.
        $this->assertSame([3, 'medium', 4, 'medium'], [$mid['credits_for_p90'], $mid['band_for_p90'], $mid['credits_for_p90_x1.25'], $mid['band_for_p90_x1.25']]);
        // What the quote gives a 3,000-token document today: small, cap 0.054, which pays neither the worst (0.10) nor p90 x 1.25 (0.095).
        $this->assertSame([3000, 'small', 0.054, false, false], [$mid['median_tokens'], $mid['edge_band'], $mid['edge_cap_usd'], $mid['edge_covers_max'], $mid['edge_covers_p90_x1.25']]);

        $tiny = $this->bucket($summary, '0-1k');
        $this->assertEqualsWithDelta(0.014, $tiny['p90_usd'], 1e-9); // 0.005 + 0.9 x 0.01 = 0.014
        $this->assertSame([1, 'small', 1, 'small'], [$tiny['credits_for_p90'], $tiny['band_for_p90'], $tiny['credits_for_p90_x1.25'], $tiny['band_for_p90_x1.25']]);
        $this->assertSame(['small', 0.054, true, true], [$tiny['edge_band'], $tiny['edge_cap_usd'], $tiny['edge_covers_max'], $tiny['edge_covers_p90_x1.25']]);

        $large = $this->bucket($summary, '60k+');
        // p90 = 1.9 -> 71 credits -> very_large; x1.25 = 2.375 -> 88 credits -> above every band.
        $this->assertSame([71, 'very_large', 88, 'above_top_band'], [$large['credits_for_p90'], $large['band_for_p90'], $large['credits_for_p90_x1.25'], $large['band_for_p90_x1.25']]);
        // Median size 95,000 tokens is very_large (cap 2.16): it pays the worst document (2.0) but not p90 x 1.25 (2.375).
        $this->assertSame(['very_large', 2.16, true, false], [$large['edge_band'], $large['edge_cap_usd'], $large['edge_covers_max'], $large['edge_covers_p90_x1.25']]);
    }

    public function test_an_empty_bucket_reports_no_numbers_rather_than_zeros(): void
    {
        $empty = $this->bucket(app(CostVsSizeReport::class)->summary([$this->row(500, 0.01)]), '20-30k');
        $this->assertSame(0, $empty['documents']);
        $this->assertNull($empty['p90_usd']);
        $this->assertNull($empty['band_for_p90']);
    }

    public function test_the_csv_has_one_header_and_one_line_per_document_with_the_agreed_columns(): void
    {
        $report = app(CostVsSizeReport::class);
        $csv = $report->csv([$this->row(2100, 0.0123)]);
        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);
        $this->assertSame(['document_id', 'file_type', 'pages', 'quote_tokens', 'local_estimate_tokens', 'provider_tokens', 'route', 'ocr_pages',
            'total_cost_usd', 'calls', 'first_try_cost_usd', 'first_try_calls', 'failed_or_retried_calls'], array_slice($header, 0, 13));
        $this->assertSame(['stage_extraction_usd', 'stage_synthesis_usd', 'stage_ocr_usd', 'stage_chart_vision_usd', 'stage_other_usd', 'largest_request_usd',
            'largest_request_purpose', 'would_tokens', 'token_source', 'would_band', 'would_credits', 'would_cap_usd', 'synthesis_floor_usd', 'would_route',
            'reservation_floor_usd', 'real_synthesis_cost_usd', 'floor_over_real'], array_slice($header, 13));
        $this->assertCount(2, $lines);
        $values = str_getcsv($lines[1]);
        $this->assertCount(count($header), $values);
        $this->assertSame(['PDF', '3', '2100', '2100', '', 'normal', '0', '0.0123', '4', '0.0123', '4', '0'], array_slice($values, 1, 12));
        $this->assertSame(array_fill(0, count($header) - 13, ''), array_slice($values, 13), 'columns a row has no value for are empty');
    }

    public function test_stages_follow_the_configured_model_roles_and_unknown_purposes_stay_other(): void
    {
        $report = app(CostVsSizeReport::class);
        foreach ([['entities', 'extraction'], ['insights', 'extraction'], ['risks', 'extraction'], ['deadlines', 'extraction'], ['document_type', 'extraction'],
            ['kpi_identity', 'extraction'], ['document_summary', 'synthesis'], ['brief_synthesis', 'synthesis'], ['ocr', 'ocr'], ['chart_vision', 'chart_vision'],
            ['verification', 'other'], ['something_new', 'other']] as [$purpose, $stage]) {
            $this->assertSame($stage, $report->stageOf($purpose), $purpose);
        }
    }

    public function test_the_csv_adds_a_cost_and_a_model_column_for_every_real_purpose_in_alphabetical_order(): void
    {
        $report = app(CostVsSizeReport::class);
        $a = $this->row(2100, 0.05) + ['by_purpose' => [
            'entities' => ['cost' => 0.03, 'calls' => 2, 'largest' => 0.02, 'models' => 'claude-haiku-4-5-20251001|claude-sonnet-5-5'],
            'document_summary' => ['cost' => 0.02, 'calls' => 1, 'largest' => 0.02, 'models' => 'claude-sonnet-5-5']]];
        $b = $this->row(900, 0.004) + ['by_purpose' => ['ocr' => ['cost' => 0.004, 'calls' => 1, 'largest' => 0.004, 'models' => 'claude-haiku-4-5-20251001']]];

        $lines = array_map('str_getcsv', explode("\n", trim($report->csv([$a, $b]))));
        $header = $lines[0];
        $this->assertSame(['cost_document_summary', 'model_document_summary', 'cost_entities', 'model_entities', 'cost_ocr', 'model_ocr'], array_slice($header, -6));
        $first = array_combine($header, $lines[1]);
        $second = array_combine($header, $lines[2]);
        $this->assertSame(['0.02', 'claude-sonnet-5-5', '0.03', 'claude-haiku-4-5-20251001|claude-sonnet-5-5', '', ''], [$first['cost_document_summary'], $first['model_document_summary'],
            $first['cost_entities'], $first['model_entities'], $first['cost_ocr'], $first['model_ocr']]);
        $this->assertSame(['', '', '', '', '0.004', 'claude-haiku-4-5-20251001'], [$second['cost_document_summary'], $second['model_document_summary'],
            $second['cost_entities'], $second['model_entities'], $second['cost_ocr'], $second['model_ocr']]);
    }

    public function test_the_summary_shows_what_the_quote_would_charge_against_what_the_p90_needs(): void
    {
        $would = fn (int $tokens, float $cost, int $credits, float $cap) => $this->row($tokens, $cost) + ['would_credits' => $credits, 'would_cap_usd' => $cap];
        $summary = app(CostVsSizeReport::class)->summary([
            // 2-4k: three documents quoted 2 credits (cap 0.054) that cost 0.01, 0.04 and 0.10.
            $would(2100, 0.01, 2, 0.054), $would(3000, 0.04, 2, 0.054), $would(3900, 0.10, 2, 0.054),
            // 0-1k: two documents quoted 2 credits that cost almost nothing.
            $would(500, 0.005, 2, 0.054), $would(800, 0.015, 2, 0.054),
        ]);

        $mid = $this->bucket($summary, '2-4k');
        // p90 = 0.04 + 0.8 x 0.06 = 0.088; x1.25 = 0.11 -> 4.07 -> 5 credits; the quote charges 2: it undercharges by 3.
        $this->assertSame([2.0, 3.0 * -1.0], [$mid['median_would_credits'], $mid['would_minus_needed_x1.25']]);
        // Cap over cost per document: 5.4, 1.35, 0.54 -> median 1.35.
        $this->assertEqualsWithDelta(1.35, $mid['median_would_cap_over_cost'], 1e-9);

        $low = $this->bucket($summary, '0-1k');
        // p90 = 0.014; x1.25 = 0.0175 -> 1 credit; the quote charges 2: +1. Cap over cost 10.8 and 3.6 -> median 7.2.
        $this->assertSame([2.0, 1.0], [$low['median_would_credits'], $low['would_minus_needed_x1.25']]);
        $this->assertEqualsWithDelta(7.2, $low['median_would_cap_over_cost'], 1e-9);
    }

    public function test_the_would_quote_summary_columns_are_empty_when_the_rows_have_no_would_quote(): void
    {
        $bucket = $this->bucket(app(CostVsSizeReport::class)->summary([$this->row(500, 0.01)]), '0-1k');
        $this->assertNull($bucket['median_would_credits']);
        $this->assertNull($bucket['median_would_cap_over_cost']);
        $this->assertNull($bucket['would_minus_needed_x1.25']);
    }
}
