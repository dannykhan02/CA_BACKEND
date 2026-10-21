<?php

namespace Tests\Unit;

use Tests\Support\NorthstarGoldScorer;
use Tests\TestCase;

class NorthstarGoldScorerTest extends TestCase
{
    private function fixtures(): array
    {
        $gold = json_decode(file_get_contents(base_path('tests/Fixtures/intelligence-v2/northstar-source-gold.json')),
            true, flags: JSON_THROW_ON_ERROR);
        $recorded = json_decode(file_get_contents(base_path('tests/Fixtures/intelligence-v2/northstar-v9-candidates-sanitized.json')),
            true, flags: JSON_THROW_ON_ERROR);

        return [$gold['cases'][0]['gold'], $recorded['candidates']];
    }

    public function test_archived_v9_contract_scores_nested_period_without_inflating_manual_matches(): void
    {
        [$gold, $candidates] = $this->fixtures();
        $score = (new NorthstarGoldScorer)->score($gold, $candidates);
        self::assertSame(4, $score['exact_gold_matches']);
        self::assertSame([4, 5, 6], $score['unmatched_candidate_indexes_for_review']);
        self::assertSame(['revenue-q3', 'costs-q3', 'profit-q3', 'margin-q3'],
            array_values(array_column(array_filter($score['gold_coverage'],
                fn ($row) => $row['candidate_indexes'] !== []), 'gold_id')));
        // The same operating-cost number in a separate breakdown table is not a second match.
        self::assertSame([], collect($score['gold_coverage'])->firstWhere('gold_id', 'cost-total-q3')['candidate_indexes']);
    }

    public function test_period_subject_basis_value_and_source_location_remain_strict(): void
    {
        [$gold, $candidates] = $this->fixtures();
        $original = $candidates[0];
        foreach (['period' => 'Q2 2026', 'scope' => 'Another company', 'value_basis' => 'target'] as $field => $bad) {
            $mutated = $original;
            $mutated['candidate']['identity'][$field] = $bad;
            self::assertSame([], collect((new NorthstarGoldScorer)->score($gold, [$mutated])['gold_coverage'])
                ->firstWhere('gold_id', 'revenue-q3')['candidate_indexes']);
        }
        $mutated = $original;
        $mutated['candidate']['value'] = '52.0';
        self::assertSame(0, (new NorthstarGoldScorer)->score($gold, [$mutated])['exact_gold_matches']);
        $mutated = $original;
        $mutated['source_context']['value_span_id']['text'] = 'Another row 42.0 46.0 51.0';
        self::assertSame(0, (new NorthstarGoldScorer)->score($gold, [$mutated])['exact_gold_matches']);
    }

    public function test_sanitized_recording_and_retained_live_diagnostics_score_equivalently_when_available(): void
    {
        $path = base_path('storage/framework/testing/docintel-v9-evaluation/'
            .'live-20261021-135636-3d989a1dc905.json');
        if (! is_file($path)) {
            self::markTestSkipped('Restricted live diagnostics are not present in this checkout.');
        }
        [$gold, $recorded] = $this->fixtures();
        $live = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $scorer = new NorthstarGoldScorer;
        $a = $scorer->score($gold, $recorded);
        $b = $scorer->score($gold, $live['candidates']);
        self::assertSame($a['exact_gold_matches'], $b['exact_gold_matches']);
        self::assertSame(array_column($a['gold_coverage'], 'candidate_indexes'),
            array_column($b['gold_coverage'], 'candidate_indexes'));
        self::assertSame($a['unmatched_candidate_indexes_for_review'],
            $b['unmatched_candidate_indexes_for_review']);
    }
}
