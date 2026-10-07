<?php

namespace Tests\Unit;

use App\Models\DocumentEvidence;
use App\Services\Intelligence\ProvenanceProjector;
use Tests\TestCase;

class IntelligenceAttributionMatcherTest extends TestCase
{
    private function provenance(string $quote, string $value): array
    {
        $row = new DocumentEvidence;
        $row->data = ['kind' => 'fact', 'label' => 'Claim', 'value' => $value,
            'quote' => $quote, 'period' => null, 'due_date' => null];

        return app(ProvenanceProjector::class)->project($row);
    }

    public function test_approved_roles_and_reported_values_come_from_config(): void
    {
        foreach ([
            ['The auditor noted revenue rose.', 'auditor', false],
            ['The Authority alleges revenue rose.', 'regulator', false],
            ['The supplier claims revenue rose.', 'counterparty', true],
            ['Analysts estimate revenue rose.', 'third_party', true],
        ] as [$quote, $role, $reported]) {
            $attribution = $this->provenance($quote, 'revenue rose')['attribution'];
            self::assertSame($role, $attribution['role']);
            self::assertSame($reported, $attribution['reported']);
        }
    }

    public function test_management_and_unmatched_patterns_remain_unattributed(): void
    {
        foreach (['Management believes revenue rose.', 'Revenue rose.'] as $quote) {
            self::assertSame('unattributed', $this->provenance($quote, 'revenue rose')['attribution']['role']);
        }
    }

    public function test_quoted_pattern_and_nearest_pattern_to_claim(): void
    {
        self::assertSame('quoted', $this->provenance('According to Ada, revenue rose.',
            'revenue rose')['attribution']['role']);
        self::assertSame('regulator', $this->provenance(
            'The auditor noted an earlier matter. The regulator stated revenue rose.',
            'revenue rose')['attribution']['role']);
    }

    public function test_unknown_origin_never_receives_attribution(): void
    {
        $result = $this->provenance('The auditor noted revenue rose.', 'unmatched paraphrase');
        self::assertSame('unknown', $result['origin']);
        self::assertSame('unattributed', $result['attribution']['role']);
    }
}
