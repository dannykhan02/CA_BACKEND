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
        foreach ([[1, 'tiny'], [2, 'small'], [3, 'medium'], [10, 'medium'], [11, 'large'], [30, 'large'], [31, 'very_large'], [80, 'very_large'], [81, 'above_top_band']] as [$credits, $band]) {
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
        // 0.076 / 0.027 = 2.8 -> 3 credits -> medium; x1.25 = 0.095 -> 3.5 -> 4 credits -> medium.
        $this->assertSame([3, 'medium', 4, 'medium'], [$mid['credits_for_p90'], $mid['band_for_p90'], $mid['credits_for_p90_x1.25'], $mid['band_for_p90_x1.25']]);
        // The current gate at the bucket's median size (3,000 tokens): 0.05 + 3 x 0.006 = 0.068, which small (0.054) cannot pay.
        $this->assertSame([3000, 0.068, 'medium'], [$mid['median_tokens'], $mid['gate_usd'], $mid['gate_band']]);

        $tiny = $this->bucket($summary, '0-1k');
        $this->assertEqualsWithDelta(0.014, $tiny['p90_usd'], 1e-9); // 0.005 + 0.9 x 0.01 = 0.014
        $this->assertSame([1, 'tiny', 1, 'tiny'], [$tiny['credits_for_p90'], $tiny['band_for_p90'], $tiny['credits_for_p90_x1.25'], $tiny['band_for_p90_x1.25']]);
        $this->assertSame('small', $tiny['gate_band'], 'the gate lifts a tiny document that the measured cost says fits one credit');

        $large = $this->bucket($summary, '60k+');
        // p90 = 1.9 -> 71 credits -> very_large; x1.25 = 2.375 -> 88 credits -> above every band.
        $this->assertSame([71, 'very_large', 88, 'above_top_band'], [$large['credits_for_p90'], $large['band_for_p90'], $large['credits_for_p90_x1.25'], $large['band_for_p90_x1.25']]);
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
        $csv = app(CostVsSizeReport::class)->csv([$this->row(2100, 0.0123)]);
        $lines = explode("\n", trim($csv));
        $this->assertSame(['document_id', 'file_type', 'pages', 'quote_tokens', 'local_estimate_tokens', 'provider_tokens', 'route', 'ocr_pages',
            'total_cost_usd', 'calls', 'first_try_cost_usd', 'first_try_calls', 'failed_or_retried_calls'], str_getcsv($lines[0]));
        $this->assertCount(2, $lines);
        $this->assertSame(['PDF', '3', '2100', '2100', '', 'normal', '0', '0.0123', '4', '0.0123', '4', '0'], array_slice(str_getcsv($lines[1]), 1));
    }
}
