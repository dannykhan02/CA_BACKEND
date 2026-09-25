<?php

namespace Tests\Unit;

use App\Services\Kpis\KpiIdentityProfile;
use App\Services\Kpis\KpiLabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KpiIdentityProfileTest extends TestCase
{
    #[DataProvider('differentMetrics')]
    public function test_material_differences_never_compare_as_same(array $a, array $b): void
    {
        $profiles = new KpiIdentityProfile(new KpiLabelNormalizer);
        $this->assertNotSame('same', $profiles->relationship($profiles->make($a), $profiles->make($b)));
    }

    public static function differentMetrics(): array
    {
        return [
            'scope' => [['label' => 'Internal Charter Performance'], ['label' => 'External Charter Performance']],
            'change' => [['label' => 'Monthly Service Fee', 'unit' => 'KES'], ['label' => 'Monthly service fee increase', 'unit' => 'KES']],
            'count and percentage' => [['label' => 'Tickets Closed', 'unit' => 'tickets'], ['label' => 'Tickets Closed', 'unit' => '%']],
            'count and rate' => [['label' => 'Incidents', 'identity' => ['metric_type' => 'count']], ['label' => 'Incidents', 'identity' => ['metric_type' => 'rate']]],
            'duration and percentage' => [['label' => 'Resolution', 'unit' => 'days'], ['label' => 'Resolution', 'unit' => '%']],
            'total and average' => [['label' => 'Total Revenue', 'unit' => 'KES'], ['label' => 'Average Revenue', 'unit' => 'KES']],
            'actual and target' => [['label' => 'Service Availability', 'unit' => '%'], ['label' => 'Service Availability Target', 'unit' => '%']],
            'currencies' => [['label' => 'Revenue', 'unit' => 'KES'], ['label' => 'Revenue', 'unit' => 'USD']],
            'pending populations' => [['label' => 'External Charter Pending Tickets'], ['label' => 'External Charter Pending Tickets Out of Timelines']],
            'unknown scope is not wildcard' => [['label' => 'Performance'], ['label' => 'Performance', 'identity' => ['scope' => 'external']]],
            'unexplained qualifiers' => [
                ['label' => 'Kenya Sales', 'identity' => ['concept' => 'sales', 'scope' => 'company']],
                ['label' => 'Uganda Sales', 'identity' => ['concept' => 'sales', 'scope' => 'company']],
            ],
            'arbitrary year is significant' => [['label' => 'ISO 2025'], ['label' => 'ISO 2026']],
            'annual weighting' => [['label' => 'External Service Charter Average Performance FY 2025/2026'], ['label' => 'External Service Charter Ticket-Weighted Annual Performance']],
        ];
    }

    public function test_reporting_periods_are_separate_when_structured_semantics_support_identity(): void
    {
        $profiles = new KpiIdentityProfile(new KpiLabelNormalizer);
        $a = $profiles->make(['label' => 'Revenue Q1 FY 2025/2026', 'identity' => ['concept' => 'revenue', 'scope' => 'company'], 'unit' => 'KES']);
        $b = $profiles->make(['label' => 'Revenue Q2 FY 2025/2026', 'identity' => ['concept' => 'revenue', 'scope' => 'company'], 'unit' => 'KES']);
        $this->assertSame('same', $profiles->relationship($a, $b));
        $this->assertSame('Q1 FY 2025/2026', $a['period']);
        $this->assertSame('Q2 FY 2025/2026', $b['period']);
    }

    public function test_contradictory_metadata_cannot_override_source_label(): void
    {
        $profiles = new KpiIdentityProfile(new KpiLabelNormalizer);
        foreach ([
            ['label' => 'Internal Charter Performance', 'identity' => ['scope' => 'external']],
            ['label' => 'Monthly service fee increase', 'identity' => ['quantity_kind' => 'absolute']],
            ['label' => 'Average Revenue', 'identity' => ['aggregation' => 'total']],
            ['label' => 'Revenue Target', 'identity' => ['value_basis' => 'actual']],
            ['label' => 'Actual Revenue', 'identity' => ['value_basis' => 'target']],
            ['label' => 'Internal Charter Performance', 'identity' => ['concept' => 'external charter performance']],
            ['label' => 'Closure Percentage', 'identity' => ['metric_type' => 'count']],
            ['label' => 'Ticket Count', 'identity' => ['metric_type' => 'percentage']],
        ] as $input) {
            $this->assertFalse($profiles->make($input)['valid']);
        }
    }

    public function test_lookup_normalization_preserves_measurement_symbols_and_meaningful_words(): void
    {
        $normalizer = new KpiLabelNormalizer;
        $this->assertSame('internal charter performance', $normalizer->normalize('  Internal — Charter: PERFORMANCE '));
        $this->assertNotSame($normalizer->normalize('Tickets'), $normalizer->normalize('Tickets (%)'));
        $this->assertStringContainsString('increase', $normalizer->normalize('Monthly service fee increase'));
        $this->assertNotSame($normalizer->normalize('A+B'), $normalizer->normalize('A B'));
    }

    public function test_percentage_rate_wording_is_not_an_explicit_type_contradiction(): void
    {
        $profiles = new KpiIdentityProfile(new KpiLabelNormalizer);
        $profile = $profiles->make(['label' => 'Late Payment Interest Rate', 'unit' => '% per month', 'identity' => ['metric_type' => 'percentage']]);
        $this->assertTrue($profile['valid']);
        $this->assertSame('percentage', $profile['metric_type']);
    }
}
