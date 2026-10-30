<?php

namespace Tests\Unit;

use App\Services\DocumentComparisonService;
use Tests\TestCase;

class CrossDocumentSafetyTest extends TestCase
{
    public function test_labelled_numeric_safety_cases_never_emit_an_incompatible_substantive_change(): void
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/ComparisonEvaluation/cases.json'), true);
        $service = app(DocumentComparisonService::class);
        foreach ($cases as $case) {
            if (($case['layer'] ?? null) === 'integration' || ($case['inputs']['a'][0]['category'] ?? '') !== 'kpis') {
                continue;
            }
            $snapshot = function ($rows) {
                $result = [];
                foreach ($rows as $row) {
                    $result['kpis:label:'.mb_strtolower($row['label'])][] = $row;
                }

                return $result;
            };
            $changes = $service->changes($snapshot($case['inputs']['a']), $snapshot($case['inputs']['b']));
            if (in_array($case['expected']['outcome'], ['not_comparable', 'insufficient_evidence'], true)) {
                $this->assertEmpty(array_filter($changes, fn ($row) => in_array($row['outcome'], ['supported_change', 'potential_conflict', 'period_comparison'], true)), $case['id']);
            }
            if ($case['expected']['outcome'] === 'supported_change') {
                $this->assertSame('supported_change', $changes[0]['outcome'] ?? null, $case['id']);
            }
            if (in_array($case['id'], ['n01', 'n02', 'n03', 'n04', 'n07', 'n08', 'n09', 'n10'], true)) {
                $this->assertEmpty($changes, $case['id']);
            }
        }
    }
    public function test_unknown_currency_prefix_is_not_discarded_in_favor_of_a_known_unit(): void
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/ComparisonEvaluation/cases.json'), true);
        $a = $cases[0]['inputs']['a'][0];
        $b = $a; $b['value']['value'] = 'XYZ 12000001';
        $decision = app(\App\Services\Intelligence\CrossDocumentNumericDecision::class)->decide($a, $b);
        $this->assertSame(['insufficient_evidence', 'invalid_numeric'], $decision);
    }

}
