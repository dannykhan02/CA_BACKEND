<?php

namespace Tests\Unit;

use App\Services\DocumentComparisonService;
use App\Services\Intelligence\CrossDocumentNumericDecision;
use App\Services\Intelligence\PeriodParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Tier 1 only: explicit source/context contract, not extraction-quality evidence. */
class ComparisonM2B1PeriodTest extends TestCase
{
    public static function scenarios(): array
    {
        return [
            'annual performance' => [[], [], 'period_comparison'],
            'ambiguous dollar versus USD' => [['unit' => '$ million'], [], 'not_comparable'],
            'ambiguous dollars on both sides' => [['unit' => '$ million'], ['unit' => '$ million'], 'insufficient_evidence'],
            'per share amounts excluded' => [['unit' => 'USD per share'], ['unit' => 'USD per share'], 'insufficient_evidence'],
            'same period difference' => [[], ['period' => '2024-01-01 to 2024-12-31'], 'supported_change'],
            'overlapping comparative agreement' => [[], ['period' => '2024-01-01 to 2024-12-31', 'value' => '100'], 'equivalent'],
            'different fiscal boundaries' => [[], ['period' => '2025-07-01 to 2026-06-30'], 'not_comparable'],
            'annual versus quarter' => [[], ['period' => '2025-01-01 to 2025-03-31'], 'not_comparable'],
            'flow versus stock' => [[], ['measurement_nature' => 'stock'], 'not_comparable'],
            'consolidated versus standalone' => [[], ['reporting_scope' => 'standalone'], 'not_comparable'],
            'forecast versus actual' => [[], ['metric_status' => 'forecast'], 'not_comparable'],
            'both forecast unsupported' => [['metric_status' => 'forecast'], ['metric_status' => 'forecast'], 'insufficient_evidence'],
            'different entity' => [[], ['owner' => 'other-owner'], 'not_comparable'],
            'different currency' => [[], ['unit' => 'KES million'], 'not_comparable'],
            'zero denominator' => [['value' => '0'], [], 'period_comparison'],
            'negative baseline' => [['value' => '-10'], [], 'period_comparison'],
            'negative target' => [[], ['value' => '-10'], 'period_comparison'],
            'missing owner' => [[], ['owner' => null], 'insufficient_evidence'],
            'unknown owners not identities' => [['owner' => 'unknown'], ['owner' => 'unknown'], 'insufficient_evidence'],
            'unknown aggregation' => [['aggregation' => 'unknown'], ['aggregation' => 'unknown'], 'insufficient_evidence'],
            'non GAAP definition unsupported' => [['accounting_basis' => 'non-gaap'], ['accounting_basis' => 'non-gaap'], 'insufficient_evidence'],
            'missing period' => [[], ['period' => null], 'insufficient_evidence'],
            'annual labels no boundaries' => [['period' => 'FY2024'], ['period' => 'FY2025'], 'not_comparable'],
            'Saturday fiscal starts unknown' => [['period' => 'Three Months Ended March 29, 2025'], ['period' => 'Three Months Ended March 28, 2026'], 'not_comparable'],
            'missing accounting basis' => [[], ['accounting_basis' => null], 'insufficient_evidence'],
            'accounting mismatch' => [[], ['accounting_basis' => 'ifrs'], 'not_comparable'],
            'unproved accounting basis' => [[], ['proof_basis' => 'non-gaap'], 'insufficient_evidence'],
            'unknown stock flow' => [[], ['measurement_nature' => null], 'insufficient_evidence'],
            'missing evidence' => [[], ['evidence' => ''], 'insufficient_evidence'],
            'unpublished source' => [[], ['revision' => null], 'insufficient_evidence'],
            'missing span' => [[], ['spans' => []], 'insufficient_evidence'],
            'invalid period passage' => [[], ['proof_period' => 'FY2025'], 'insufficient_evidence'],
            'ineligible parseable value' => [[], ['analytical_eligible' => false], 'insufficient_evidence'],
            'different concept' => [[], ['concept_id' => 'label:revenue'], 'not_comparable'],
            'unknown reporting scope' => [[], ['reporting_scope' => null], 'insufficient_evidence'],
            'different value basis' => [[], ['value_basis' => 'budget'], 'not_comparable'],
            'adjacent quarters' => [['period' => '2025-01-01 to 2025-03-31'], ['period' => '2025-04-01 to 2025-06-30'], 'period_comparison'],
            'stock point comparison' => [['period' => 'As of 2024-12-31', 'measurement_nature' => 'stock'], ['period' => 'As of 2025-12-31', 'measurement_nature' => 'stock'], 'period_comparison'],
            'stock using flow interval' => [['measurement_nature' => 'stock'], ['measurement_nature' => 'stock'], 'not_comparable'],
            'same unit different scale' => [['value' => '1', 'unit' => 'USD million'], ['period' => '2024-01-01 to 2024-12-31', 'value' => '1,000,000', 'unit' => 'USD'], 'equivalent'],
        ];
    }

    public static function fact(array $override = [], bool $b = false): array
    {
        $value = ['value' => $override['value'] ?? ($b ? '125' : '100'), 'unit' => $override['unit'] ?? 'USD million',
            'period' => array_key_exists('period', $override) ? $override['period'] : ($b ? '2025-01-01 to 2025-12-31' : '2024-01-01 to 2024-12-31')];
        $context = array_replace(['owner' => 'established:test-owner', 'subject' => 'group', 'concept_id' => 'label:operating income',
            'reporting_scope' => 'consolidated', 'measurement_nature' => 'flow', 'value_basis' => 'actual', 'aggregation' => 'total',
            'metric_status' => 'actual', 'accounting_basis' => 'gaap', 'analytical_eligible' => true, 'verification' => 'source_verified'],
            array_intersect_key($override, array_flip(['owner', 'subject', 'concept_id', 'reporting_scope', 'measurement_nature', 'value_basis', 'aggregation', 'metric_status', 'accounting_basis', 'analytical_eligible', 'verification'])));
        $text = 'Consolidated '.($override['proof_basis'] ?? $context['accounting_basis']).' Operating income '.$value['value'].' '.$value['unit'].' '.($override['proof_period'] ?? $value['period']);

        return ['category' => 'kpis', 'label' => 'Operating income', 'value' => $value, 'context' => $context,
            'source' => ['document_id' => $b ? 'b' : 'a', 'source_id' => $b ? 'metric-b' : 'metric-a',
                'intelligence_revision_id' => array_key_exists('revision', $override) ? $override['revision'] : 'published-test-revision',
                'evidence_ids' => ['test-evidence'], 'spans' => $override['spans'] ?? [['quote' => $text]],
                'evidence' => $override['evidence'] ?? $text, 'location_kind' => 'unknown']];
    }

    #[DataProvider('scenarios')]
    public function test_typed_period_safety(array $a, array $b, string $expected): void
    {
        $result = app(CrossDocumentNumericDecision::class)->decide(self::fact($a), self::fact($b, true));
        $this->assertSame($expected, $result[0]);
        if ($expected === 'period_comparison') {
            $this->assertSame('explicit-financial-period-m2b1-v1', $result[2]['rule_id']);
            $this->assertSame('different_periods', $result[2]['relationship']);
            if (in_array($a['value'] ?? null, ['0', '-10'], true) || ($b['value'] ?? null) === '-10') {
                $this->assertNull($result[2]['relative_percent']);
            }
        }
    }

    public function test_exact_arithmetic_and_persistable_explanations(): void
    {
        $a = self::fact(['value' => '9007199254740993.01', 'unit' => 'USD']);
        $b = self::fact(['value' => '9007199254740994.02', 'unit' => 'USD'], true);
        $changes = app(DocumentComparisonService::class)->changes(['kpis:label:operating income' => [$a]], ['kpis:label:operating income' => [$b]]);
        $this->assertSame('1.01', $changes[0]['interpretation']['absolute_difference']);
        $this->assertSame($a, $changes[0]['before'][0]);
        $this->assertSame($b, $changes[0]['after'][0]);
        $this->assertStringContainsString('does not establish a correction', $changes[0]['description']);
        $this->assertSame('0.000000', $changes[0]['interpretation']['relative_percent']);
    }

    public function test_duplicate_conflicts_still_abstain_and_identical_duplicates_do_not_multiply_changes(): void
    {
        $a = self::fact();
        $b = self::fact([], true);
        $key = 'kpis:label:operating income';
        $service = app(DocumentComparisonService::class);
        $this->assertCount(1, $service->changes([$key => [$a, $a]], [$key => [$b]]));
        $conflict = self::fact(['value' => '101']);
        $changes = $service->changes([$key => [$a, $conflict]], [$key => [$b]]);
        $this->assertSame('insufficient_evidence', $changes[0]['outcome']);
        $this->assertSame(['ambiguous_candidates'], $changes[0]['reasons']);
    }

    public function test_invalid_or_partial_dates_cannot_supply_explicit_bounds(): void
    {
        foreach (['2025-02-30 to 2026-02-28', '2025-12-31 to 2025-01-01', 'FY2025', '2025-01-02 to 2025-12-31', 'As of 2025-02-29'] as $text) {
            $this->assertNull(app(PeriodParser::class)->explicitInterval($text));
        }
    }
}
