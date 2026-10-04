<?php

namespace Tests\Unit;

use App\Services\AI\ResponseValidator;
use PHPUnit\Framework\TestCase;

class StructuredAssessmentValidatorTest extends TestCase
{
    private function base(): array
    {
        return [
            'executive_summary' => 'Revenue rose while debt increased.',
            'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
            'important_entities' => [], 'recommended_attention' => [],
        ];
    }

    public function test_bounded_grounded_assessment_is_accepted(): void
    {
        $result = (new ResponseValidator())->validateSummary($this->base() + [
            'executive_assessment' => ['text' => 'Financial pressure rose.', 'source_ids' => ['risk:1']],
            'material_findings' => [[
                'title' => 'Debt increased', 'category' => 'financial', 'explanation' => 'Debt rose.',
                'why_it_matters' => 'It may constrain funding.', 'severity' => 'high', 'source_ids' => ['risk:1'],
            ]],
            'trends' => [], 'tensions' => [], 'questions' => [],
        ], ['risk:1']);
        self::assertCount(1, $result['material_findings']);
    }

    public function test_unavailable_evidence_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ResponseValidator())->validateSummary($this->base() + [
            'executive_assessment' => ['text' => 'Unsupported claim.', 'source_ids' => ['risk:other']],
        ], ['risk:1']);
    }

    public function test_oversized_structured_list_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ResponseValidator())->validateSummary($this->base() + [
            'questions' => array_fill(0, 4, ['question' => 'What changed?', 'reason' => 'Unclear.', 'source_ids' => ['risk:1']]),
        ], ['risk:1']);
    }

    public function test_invalid_evidence_basis_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ResponseValidator())->validateSummary($this->base() + [
            'executive_assessment' => ['text' => 'Debt rose.', 'basis' => 'certain', 'source_ids' => ['risk:1']],
        ], ['risk:1']);
    }

    public function test_legacy_summary_remains_readable(): void
    {
        self::assertSame($this->base(), (new ResponseValidator())->validateSummary($this->base()));
    }
}
