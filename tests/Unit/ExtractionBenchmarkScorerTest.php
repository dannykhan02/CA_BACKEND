<?php

namespace Tests\Unit;

use App\Services\AI\ExtractionBenchmarkScorer;
use Tests\TestCase;

class ExtractionBenchmarkScorerTest extends TestCase
{
    private function manifest(): array
    {
        return ['models' => ['claude-haiku-4-5-20251001', 'claude-haiku-5-5', 'claude-sonnet-5-5'],
            'cases' => [['id' => 'synthetic-case', 'gold' => [[
                'id' => 'complaint-ceiling', 'value' => '90', 'unit' => 'cases', 'period' => 'Q3 2026',
                'subject' => 'Northstar', 'value_basis' => 'target', 'comparison_operator' => '<=',
                'source_ids' => ['E001', 'E002'],
            ]], 'runs' => [
                'claude-haiku-4-5-20251001' => ['status' => 'success', 'duration_ms' => 1200,
                    'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
                    'candidates' => [[
                        'reviewed_match_id' => 'complaint-ceiling', 'value' => '90', 'unit' => 'cases',
                        'period' => 'Q2 2026', 'subject' => 'Northstar', 'value_basis' => 'target',
                        'source_ids' => ['E001'], 'analytical_eligible' => true,
                    ]]],
                'claude-haiku-5-5' => ['status' => 'success', 'duration_ms' => 900,
                    'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
                    'candidates' => [[
                        'reviewed_match_id' => 'complaint-ceiling', 'value' => '90', 'unit' => 'cases',
                        'period' => 'Q3 2026', 'subject' => 'Northstar', 'value_basis' => 'target',
                        'comparison_operator' => '<=',
                        'source_ids' => ['E002', 'E001'], 'analytical_eligible' => true,
                    ]]],
            ]]]];
    }

    public function test_identical_gold_scores_false_verification_source_accuracy_cost_and_pending_model(): void
    {
        $result = app(ExtractionBenchmarkScorer::class)->score($this->manifest());
        $old = $result['models']['claude-haiku-4-5-20251001'];
        $candidate = $result['models']['claude-haiku-5-5'];
        $sonnet = $result['models']['claude-sonnet-5-5'];
        self::assertSame(1, $old['false_verified']);
        self::assertSame(0, $old['field_correct']['period']);
        self::assertSame(0, $old['field_correct']['comparison_operator']);
        self::assertEqualsWithDelta(0.0, $old['field_accuracy']['period'], 0.000001);
        self::assertEqualsWithDelta(0.0, $old['source_reference_accuracy'], 0.000001);
        self::assertEqualsWithDelta(1.0, $old['false_verification_rate'], 0.000001);
        self::assertSame(0, $candidate['false_verified']);
        self::assertSame(1, $candidate['field_correct']['comparison_operator']);
        self::assertEqualsWithDelta(1.0, $candidate['completeness'], 0.000001);
        self::assertEqualsWithDelta(1.0, $candidate['source_reference_accuracy'], 0.000001);
        self::assertEqualsWithDelta(900.0, $candidate['mean_latency_ms'], 0.000001);
        self::assertSame(0.00015, $candidate['estimated_cost_usd']);
        self::assertSame(1, $sonnet['pending_cases']);
        self::assertNull($sonnet['completeness']);
        self::assertNull($sonnet['estimated_cost_usd']);
    }

    public function test_cost_is_unavailable_when_a_successful_recording_has_no_usage(): void
    {
        $manifest = $this->manifest();
        unset($manifest['cases'][0]['runs']['claude-haiku-5-5']['usage']);
        $candidate = app(ExtractionBenchmarkScorer::class)->score($manifest)['models']['claude-haiku-5-5'];

        self::assertSame(1, $candidate['successful_runs']);
        self::assertSame(1, $candidate['unpriced_runs']);
        self::assertNull($candidate['estimated_cost_usd']);
        self::assertEqualsWithDelta(1.0, $candidate['completeness'], 0.000001);
    }

    public function test_cli_reads_local_recordings_without_provider_calls(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docintel-model-eval-');
        try {
            file_put_contents($path, json_encode($this->manifest(), JSON_THROW_ON_ERROR));
            $this->artisan('docintel:evaluate-extraction', ['manifest' => $path])
                ->expectsOutputToContain('claude-haiku-5-5')->assertExitCode(0);
        } finally {
            unlink($path);
        }
    }
}
