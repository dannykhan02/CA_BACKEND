<?php

namespace Tests\Unit;

use App\Services\Comparisons\DeadlineDecision;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use App\Services\DocumentTextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Tier 1 only: synthetic boundary inputs, never real extraction acceptance. */
class ComparisonAcceptanceRecoveryGuardsTest extends TestCase
{
    public function test_all_frozen_fact_cases_retain_zero_false_substantive_results(): void
    {
        $path = base_path('tests/Fixtures/ComparisonEvaluation/cases.json');
        $cases = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $counts = ['cases' => 0, 'correct' => 0, 'false_substantive' => 0, 'missed_substantive' => 0];
        $results = [];
        foreach ($cases as $case) {
            if (($case['layer'] ?? null) === 'integration') {
                continue;
            }
            $snapshots = [];
            foreach (['a', 'b'] as $side) {
                $snapshot = [];
                foreach ($case['inputs'][$side] as $row) {
                    $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row['label'])));
                    $snapshot[$row['category'].':'.($row['category'] === 'kpis' ? 'label:' : '').$label][] = $row;
                }
                $snapshots[] = $snapshot;
            }
            $changes = app(DocumentComparisonService::class)->changes(...$snapshots);
            $outcomes = array_unique(array_column($changes, 'outcome'));
            $actual = $outcomes === [] ? 'equivalent' : (count($outcomes) === 1 ? reset($outcomes) : 'mixed');
            $substantive = fn ($s) => in_array($s, ['supported_change', 'potential_conflict', 'period_comparison']);
            $false = ! $substantive($case['expected']['outcome']) && count(array_filter($outcomes, $substantive)) > 0;
            $this->assertFalse($false, $case['id']);
            $counts['cases']++;
            $counts['correct'] += (int) ($actual === $case['expected']['outcome']);
            $counts['false_substantive'] += (int) $false;
            $counts['missed_substantive'] += (int) ($substantive($case['expected']['outcome']) && ! count(array_filter($outcomes, $substantive)));
            $results[] = ['id' => $case['id'], 'expected' => $case['expected']['outcome'], 'actual' => $actual];
        }
        $this->assertSame(55, $counts['cases']);
        require_once base_path('tests/Fixtures/ComparisonIdentity/run.php');
        $identity = evaluateComparisonIdentity();
        $this->assertSame(0, $identity['counts']['false_identity_matches']);
        file_put_contents(base_path('tests/Fixtures/ComparisonM3/recovery-frozen-regression.json'), json_encode([
            'tier' => 1, 'fixture_sha256' => hash_file('sha256', $path), 'counts' => $counts,
            'results' => $results, 'identity_counts' => $identity['counts'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function fact(string $side, string $date): array
    {
        $quote = 'Agreement X Section 7.2.D. Buyer term starts on '.$date.'.';

        return ['category' => 'deadlines', 'label' => 'Term start',
            'value' => ['description' => 'Term start', 'due_date' => $date, 'date_type' => 'explicit', 'relative_text' => null],
            'context' => ['responsible_party' => 'Buyer', 'obligation_reference' => 'Agreement X Section 7.2.D',
                'deadline_type' => 'obligation', 'identity' => ['key' => 'synthetic-reviewed-pair']],
            'source' => ['document_id' => $side, 'source_id' => 'deadline:1', 'intelligence_revision_id' => 'rev-'.$side,
                'evidence_ids' => ['ev-'.$side], 'evidence' => $quote,
                'spans' => [['quote' => $quote, 'start_offset' => 0, 'end_offset' => mb_strlen($quote)]]]];
    }

    public function test_section_number_is_only_a_candidate_until_reviewed(): void
    {
        $a = $this->fact('a', '2030-01-01');
        $b = $this->fact('b', '2029-01-01');
        unset($a['context']['identity'], $b['context']['identity']);
        $left = ['deadlines:term' => [$a]];
        $right = ['deadlines:term' => [$b]];
        $engine = app(IdentityCandidates::class);
        $context = $engine->build($left, $right);
        $this->assertCount(1, $context['candidates']);
        $this->assertSame('candidate', $context['candidates'][0]['state']);
        $this->assertSame('insufficient_evidence', app(DocumentComparisonService::class)->changes($left, $right, $context)[0]['outcome']);
        $key = $context['candidates'][0]['key'];
        $reviewed = $engine->build($left, $right, [$key => ['state' => 'confirmed', 'version' => 1]]);
        $this->assertSame('supported_change', app(DocumentComparisonService::class)->changes($left, $right, $reviewed)[0]['outcome']);
        foreach (['rejected', 'revoked'] as $state) {
            $blocked = $engine->build($left, $right, [$key => ['state' => $state, 'version' => 2]]);
            $this->assertSame('insufficient_evidence', app(DocumentComparisonService::class)->changes($left, $right, $blocked)[0]['outcome']);
        }
    }

    public static function additionalDates(): array
    {
        return [
            'unrelated clause' => ['Section 9: notices due 2028-06-30.'],
            'quoted old date' => ['Previously stated: starts on 2030-01-01.'],
            'embedded historical agreement' => ['EXHIBIT: ORIGINAL AGREEMENT Section 7.2.D starts on 2030-01-01.'],
            'conflicting candidate' => ['Alternative term starts on 2031-01-01.'],
            'multiple deadlines in replacement' => ['Replacement Section 7.2.D also ends on 2038-12-31.'],
        ];
    }

    #[DataProvider('additionalDates')]
    public function test_a_reviewed_fact_does_not_select_one_date_from_mixed_evidence(string $extra): void
    {
        $a = $this->fact('a', '2030-01-01');
        $b = $this->fact('b', '2029-01-01');
        $b['source']['spans'][] = ['quote' => $extra];
        $this->assertSame(['insufficient_evidence', 'deadline_date_source_mismatch'], app(DeadlineDecision::class)->decide($a, $b));
    }

    public function test_unrelated_contract_with_same_section_is_incompatible(): void
    {
        $a = $this->fact('a', '2030-01-01');
        $b = $this->fact('b', '2029-01-01');
        $b['context']['obligation_reference'] = 'Agreement Y Section 7.2.D';
        $this->assertSame(['not_comparable', 'obligation_reference_mismatch'], app(DeadlineDecision::class)->decide($a, $b));
    }

    public function test_unchanged_terms_do_not_become_a_change(): void
    {
        $this->assertSame(['equivalent', 'aligned_equal_deadline'], app(DeadlineDecision::class)->decide(
            $this->fact('a', '2030-01-01'), $this->fact('b', '2030-01-01')));
    }

    public function test_ambiguous_parties_cannot_be_cured_by_review(): void
    {
        $a = $this->fact('a', '2030-01-01');
        $b = $this->fact('b', '2029-01-01');
        $b['context']['ambiguous_source_context'] = true;
        $this->assertSame(['insufficient_evidence', 'ambiguous_source_context'], app(DeadlineDecision::class)->decide($a, $b));
        unset($b['context']['ambiguous_source_context']);
        $b['context']['responsible_party'] = null;
        $this->assertSame(['insufficient_evidence', 'unverified_provision_context'], app(DeadlineDecision::class)->decide($a, $b));
    }

    public function test_missing_structured_extraction_does_not_create_correspondence(): void
    {
        $this->assertSame([], app(IdentityCandidates::class)->build([], [])['candidates']);
        $this->assertSame([], app(DocumentComparisonService::class)->changes([], []));
    }

    public function test_a_missing_original_pdf_cannot_be_silently_replaced_with_text(): void
    {
        $this->expectException(\Throwable::class);
        app(DocumentTextExtractor::class)->extractPdfStructured(base_path('tests/Fixtures/ComparisonRecovery/sources/missing-original.pdf'));
    }
}
