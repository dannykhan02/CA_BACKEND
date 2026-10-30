<?php

namespace Tests\Unit;

use App\Services\Comparisons\IdentityCandidates;
use App\Services\Comparisons\SourceIdentityIdentifier;
use App\Services\DocumentComparisonService;
use App\Services\Intelligence\CrossDocumentNumericDecision;
use Tests\TestCase;

class ComparisonIdentityTest extends TestCase
{
    public function test_separate_labelled_identity_suite(): void
    {
        require_once __DIR__.'/../Fixtures/ComparisonIdentity/run.php';
        $report = evaluateComparisonIdentity();
        foreach ($report['results'] as $result) {
            $this->assertSame($result['expected'], $result['actual'], $result['id']);
            $this->assertSame($result['expected_identity'], $result['identity'], $result['id']);
        }
        $this->assertSame(0, $report['counts']['false_substantive_rows']);
        $this->assertSame(0, $report['counts']['false_identity_matches']);
    }

    private function fact(string $document, string $owner = 'Example Ltd'): array
    {
        return ['category' => 'kpis', 'label' => 'Revenue', 'value' => ['value' => '100', 'unit' => 'KES', 'period' => '2025'],
            'context' => ['concept_id' => 'definition:revenue', 'reported_owner' => $owner, 'owner' => null,
                'subject' => 'consolidated', 'value_basis' => 'actual', 'aggregation' => 'total',
                'verification' => 'source_verified', 'analytical_eligible' => true],
            'source' => ['document_id' => $document, 'intelligence_revision_id' => 'rev-'.$document,
                'source_id' => 'kpi:1', 'insight_id' => 1, 'evidence' => $owner.' reports consolidated revenue 100 KES in 2025.']];
    }

    public function test_name_candidate_never_establishes_owner_identity_and_confirmation_does_not_bypass_period(): void
    {
        $engine = app(IdentityCandidates::class);
        $a = $this->fact('a');
        $b = $this->fact('b', 'Example Limited');
        $b['value']['value'] = '200';
        $context = $engine->build(['kpis:x' => [$a]], ['kpis:x' => [$b]]);
        $this->assertSame('candidate', $context['candidates'][0]['state']);
        $key = $context['candidates'][0]['key'];
        $reviewed = $engine->build(['kpis:x' => [$a]], ['kpis:x' => [$b]], [$key => ['state' => 'confirmed', 'version' => 1, 'id' => 'link']]);
        [$left, $right] = $engine->apply(['kpis:x' => [$a]], ['kpis:x' => [$b]], $reviewed);
        $this->assertSame('supported_change', app(DocumentComparisonService::class)->changes($left, $right)[0]['outcome']);
        $right[array_key_first($right)][0]['value']['period'] = '2026';
        $this->assertSame('not_comparable', app(DocumentComparisonService::class)->changes($left, $right)[0]['outcome']);
    }

    public function test_same_deadline_label_does_not_hide_different_parties_or_obligations(): void
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/ComparisonEvaluation/cases.json'), true);
        foreach (array_filter($cases, fn ($c) => in_array($c['id'], ['c07', 'c09'])) as $case) {
            $this->assertSame('not_comparable', app(DocumentComparisonService::class)->changes(
                ['deadlines:x' => $case['inputs']['a']], ['deadlines:x' => $case['inputs']['b']])[0]['outcome'] ?? 'equivalent', $case['id']);
        }
    }

    public function test_explicit_review_can_select_one_duplicate_while_rejecting_the_other_without_global_merging(): void
    {
        $a = $this->fact('a');
        $b = $this->fact('b');
        $other = $b;
        $b['value']['value'] = '200';
        $other['source']['source_id'] = 'kpi:2';
        $before = ['kpis:x' => [$a]];
        $after = ['kpis:x' => [$b, $other]];
        $engine = app(IdentityCandidates::class);
        $initial = $engine->build($before, $after);
        $decisions = [];
        foreach ($initial['candidates'] as $candidate) {
            $decisions[$candidate['key']] = ['state' => $candidate['b']['source']['source_id'] === 'kpi:1' ? 'confirmed' : 'rejected', 'version' => 1, 'id' => 'review'];
        }
        $changes = app(DocumentComparisonService::class)->changes($before, $after, $engine->build($before, $after, $decisions));
        $this->assertSame(1, count(array_filter($changes, fn ($c) => $c['outcome'] === 'supported_change')));
        $this->assertSame(1, count(array_filter($changes, fn ($c) => $c['outcome'] === 'only_b')));
    }

    public function test_candidate_bounds_abstain_instead_of_matching_a_truncated_bucket(): void
    {
        $a = $this->fact('a');
        $many = [];
        for ($i = 0; $i < 100; $i++) {
            $b = $this->fact('b');
            $b['source']['source_id'] = 'kpi:'.$i;
            $many[] = $b;
        }
        $context = app(IdentityCandidates::class)->build(['x' => [$a]], ['x' => $many]);
        $this->assertTrue($context['truncated']);
        $this->assertEmpty($context['candidates']);
        $this->assertEmpty(app(IdentityCandidates::class)->usage(['x' => [$a]], ['x' => $many], $context));
    }

    public function test_reviewed_owner_does_not_erase_consolidation_scope_or_missing_scope(): void
    {
        $a = $this->fact('a');
        $b = $this->fact('b');
        $a['context']['owner'] = $b['context']['owner'] = 'reviewed-owner';
        $a['context']['reporting_scope'] = 'consolidated';
        $b['context']['reporting_scope'] = 'standalone';
        $decision = app(CrossDocumentNumericDecision::class);
        $this->assertSame(['not_comparable', 'reporting_scope_mismatch'], $decision->decide($a, $b));
        $a['context']['reporting_scope'] = $b['context']['reporting_scope'] = null;
        $this->assertSame(['insufficient_evidence', 'unknown_reporting_scope'], $decision->decide($a, $b));
    }

    public function test_identifier_reader_requires_exact_named_binding_and_valid_check_digits(): void
    {
        $reader = app(SourceIdentityIdentifier::class);
        $valid = '5493001KJTIIGC8Y1R12';
        $this->assertNotNull($reader->forName('Example Ltd', [['quote' => "Example Ltd (LEI: {$valid})"]]));
        $this->assertNull($reader->forName('Example Ltd', [['quote' => "Lender Ltd (LEI: {$valid}); Example Ltd reports revenue."]]));
        $this->assertNull($reader->forName('Example Ltd', [['quote' => 'Example Ltd (LEI: 5493001KJTIIGC8Y1R13)']]));
    }
}
