<?php

namespace Tests\Unit;

use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Tier A, independently specified category outcomes. No extraction claim. */
class ComparisonM3CategoryTest extends TestCase
{
    public static function fact(string $category, string $side = 'a', array $replace = []): array
    {
        $quote = $category === 'risks' ? 'Plant A has supply exposure.' : 'Clause 4. Buyer shall pay within 45 days of invoice receipt.';

        return array_replace_recursive(['category' => $category, 'label' => $category === 'risks' ? 'Supply risk' : 'Payment',
            'context' => ['projection_version' => 'published-intelligence-m3-v1', 'subject' => 'Plant A', 'risk_type' => 'operational',
                'responsible_party' => 'Buyer', 'obligation_reference' => 'Clause 4', 'deadline_type' => 'obligation'],
            'value' => $category === 'risks' ? ['description' => 'Supply exposure', 'severity' => 'high']
                : ['description' => 'Buyer payment', 'date_type' => 'relative', 'relative_text' => 'within 45 days of invoice receipt', 'due_date' => null],
            'source' => ['document_id' => $side, 'source_id' => $category.':1', 'intelligence_revision_id' => 'revision-'.$side,
                'evidence_ids' => ['evidence-'.$side], 'spans' => [['quote' => $quote, 'start_offset' => 0, 'end_offset' => mb_strlen($quote)]], 'evidence' => $quote]], $replace);
    }

    public static function cases(): array
    {
        return [
            'equal unreviewed risks are not silently equivalent' => ['risks', [], 'candidate', 'potential_relationship'],
            'reviewed same risk and classification' => ['risks', [], 'confirmed', 'equivalent'],
            'reviewed extracted severity differs' => ['risks', ['value' => ['severity' => 'low']], 'confirmed', 'supported_change'],
            'unknown risk severity scale' => ['risks', ['value' => ['severity' => '7/10']], 'confirmed', 'not_comparable'],
            'different risk subjects' => ['risks', ['context' => ['subject' => 'Plant B']], 'confirmed', 'not_comparable'],
            'same risk different narrative is interpretation' => ['risks', ['value' => ['description' => 'New paraphrase']], 'confirmed', 'potential_relationship'],
            'missing risk evidence' => ['risks', ['source' => ['spans' => [], 'evidence' => '']], 'confirmed', 'insufficient_evidence'],
            'risk missing subject' => ['risks', ['context' => ['subject' => null]], 'confirmed', 'insufficient_evidence'],
            'risk rejected correspondence' => ['risks', [], 'rejected', 'insufficient_evidence'],
            'risk revoked correspondence' => ['risks', [], 'revoked', 'insufficient_evidence'],
            'obligation unreviewed' => ['obligations', [], 'candidate', 'potential_relationship'],
            'same obligation timing' => ['obligations', [], 'confirmed', 'equivalent'],
            'different prose same timing is qualified' => ['obligations', ['value' => ['description' => 'Different action wording']], 'confirmed', 'potential_relationship'],
            'same title different actor' => ['obligations', ['context' => ['responsible_party' => 'Seller']], 'confirmed', 'not_comparable'],
            'missing actor' => ['obligations', ['context' => ['responsible_party' => null]], 'confirmed', 'insufficient_evidence'],
            'different relative basis' => ['obligations', ['value' => ['relative_text' => 'within 45 days of delivery']], 'confirmed', 'not_comparable'],
        ];
    }

    #[DataProvider('cases')]
    public function test_category_decisions(string $category, array $right, string $state, string $expected): void
    {
        $a = self::fact($category);
        $b = self::fact($category, 'b', $right);
        $engine = app(IdentityCandidates::class);
        $before = [$category.':title' => [$a]];
        $after = [$category.':title' => [$b]];
        $initial = $engine->build($before, $after);
        $decisions = [];
        foreach ($initial['candidates'] as $candidate) {
            $decisions[$candidate['key']] = ['state' => $state, 'version' => 1, 'id' => 'authored-review'];
        }
        $context = $engine->build($before, $after, $decisions);
        $output = app(DocumentComparisonService::class)->changes($before, $after, $context);
        $this->assertCount(1, $output);
        $this->assertSame($expected, $output[0]['outcome']);
        if ($expected === 'supported_change') {
            $this->assertStringContainsString('classification', $output[0]['description']);
            $this->assertSame('confirmed', $output[0]['identity_basis']['state']);
        }
    }

    public function test_corresponding_obligation_uses_existing_timing_rule_once_despite_timeline_view(): void
    {
        $a = self::fact('obligations');
        $b = self::fact('obligations', 'b', ['value' => ['relative_text' => 'within 30 days of invoice receipt'],
            'source' => ['evidence' => 'Clause 4. Buyer shall pay within 30 days of invoice receipt.',
                'spans' => [['quote' => 'Clause 4. Buyer shall pay within 30 days of invoice receipt.']]]]);
        $a['views'] = $b['views'] = ['obligations', 'deadlines'];
        $before = ['obligations:payment' => [$a]];
        $after = ['obligations:payment' => [$b]];
        $engine = app(IdentityCandidates::class);
        $key = $engine->build($before, $after)['candidates'][0]['key'];
        $context = $engine->build($before, $after, [$key => ['state' => 'confirmed', 'version' => 1, 'id' => 'authored']]);
        $output = app(DocumentComparisonService::class)->changes($before, $after, $context);
        $this->assertCount(1, $output);
        $this->assertSame('supported_change', $output[0]['outcome']);
        $this->assertSame(['timing'], $output[0]['compared_fields']);
        $this->assertSame('obligations', $output[0]['category']);
    }

    public function test_multiple_risk_candidates_never_select_first_or_cross_multiply_assertions(): void
    {
        $a = self::fact('risks');
        $b = self::fact('risks', 'b');
        $b2 = self::fact('risks', 'b', ['source' => ['source_id' => 'risks:2'], 'value' => ['severity' => 'low']]);
        $before = ['risks:supply' => [$a]];
        $after = ['risks:supply' => [$b, $b2]];
        $context = app(IdentityCandidates::class)->build($before, $after);
        $this->assertCount(2, $context['candidates']);
        $output = app(DocumentComparisonService::class)->changes($before, $after, $context);
        $this->assertSame(['insufficient_evidence'], array_column($output, 'outcome'));
    }

    public function test_identical_ineligible_m3_kpis_are_visible_as_insufficient_instead_of_silent_equality(): void
    {
        $row = ['category' => 'kpis', 'label' => 'Revenue', 'value' => ['value' => '12', 'unit' => null, 'period' => null],
            'context' => ['projection_version' => 'published-intelligence-m3-v1', 'analytical_eligible' => false], 'source' => ['intelligence_revision_id' => 'published-test']];
        $out = app(DocumentComparisonService::class)->changes(['kpis:x' => [$row]], ['kpis:x' => [$row]]);
        $this->assertSame('insufficient_evidence', $out[0]['outcome']);
    }

    public function test_entity_correspondence_and_extracted_roles_do_not_merge_similar_names(): void
    {
        $a = self::fact('entities', 'a', ['label' => 'organization', 'value' => ['value' => 'Acme'], 'context' => ['entity_type' => 'organization', 'role' => 'supplier']]);
        $b = self::fact('entities', 'b', ['label' => 'organization', 'value' => ['value' => 'Acme'], 'context' => ['entity_type' => 'organization', 'role' => 'customer']]);
        $engine = app(IdentityCandidates::class);
        $before = ['entities:organization' => [$a]];
        $after = ['entities:organization' => [$b]];
        $key = $engine->build($before, $after)['candidates'][0]['key'];
        $out = app(DocumentComparisonService::class)->changes($before, $after, $engine->build($before, $after, [$key => ['state' => 'confirmed', 'version' => 1]]));
        $this->assertSame('supported_change', $out[0]['outcome']);
        $this->assertStringContainsString('extracted role', $out[0]['description']);
        $b['value']['value'] = 'Acme Ventures';
        $this->assertEmpty($engine->build($before, ['entities:organization' => [$b]])['candidates']);
    }

    public function test_summary_wording_and_unrelated_event_dates_never_become_substantive_changes(): void
    {
        foreach (['summaries', 'other_findings'] as $category) {
            $a = self::fact($category);
            $b = self::fact($category, 'b', ['value' => ['description' => 'An omitted fact or a new narrative conclusion']]);
            $out = app(DocumentComparisonService::class)->changes([$category => [$a]], [$category => [$b]]);
            $this->assertSame('insufficient_evidence', $out[0]['outcome']);
        }
    }

    public function test_ambiguous_source_context_blocks_even_otherwise_compatible_kpi_values(): void
    {
        $a = ComparisonM2B1PeriodTest::fact();
        $a['context'] += ['projection_version' => 'published-intelligence-m3-v1', 'ambiguous_source_context' => true];
        $b = ComparisonM2B1PeriodTest::fact([], true);
        $b['context']['projection_version'] = 'published-intelligence-m3-v1';
        $out = app(DocumentComparisonService::class)->changes(['kpis:income' => [$a]], ['kpis:income' => [$b]]);
        $this->assertSame('insufficient_evidence', $out[0]['outcome']);
    }
}
