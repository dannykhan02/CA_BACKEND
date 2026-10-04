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

    private function assessment(mixed $text = 'Financial pressure rose.'): array
    {
        return ['text' => $text, 'basis' => 'inferred', 'source_ids' => ['risk:1']];
    }

    private function validate(array $additional): array
    {
        return (new ResponseValidator)->validateSummary($this->base() + $additional, ['risk:1', 'kpi:2']);
    }

    private function assertInvalidAssessment(array $assessment, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->validate(['executive_assessment' => $assessment]);
    }

    public function test_valid_executive_assessment_and_basis_are_accepted(): void
    {
        $assessment = $this->assessment();
        self::assertSame($assessment, $this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_null_executive_assessment_is_accepted(): void
    {
        self::assertNull($this->validate(['executive_assessment' => null])['executive_assessment']);
    }

    public function test_missing_assessment_text_is_rejected_with_field_path(): void
    {
        $assessment = $this->assessment();
        unset($assessment['text']);
        $this->assertInvalidAssessment($assessment, 'executive_assessment.text: missing');
    }

    public function test_null_assessment_text_is_rejected_with_type(): void
    {
        $this->assertInvalidAssessment($this->assessment(null), 'executive_assessment.text: expected string, got null');
    }

    public function test_non_string_assessment_text_is_rejected_with_type(): void
    {
        $this->assertInvalidAssessment($this->assessment(['text' => 'Wrong shape']), 'executive_assessment.text: expected string, got array');
    }

    public function test_empty_assessment_text_is_rejected(): void
    {
        $this->assertInvalidAssessment($this->assessment('  '), 'executive_assessment.text: must not be empty');
    }

    public function test_assessment_text_at_350_character_boundary_is_accepted(): void
    {
        $text = str_repeat('a', 350);
        self::assertSame($text, $this->validate(['executive_assessment' => $this->assessment($text)])['executive_assessment']['text']);
    }

    public function test_assessment_text_over_350_character_boundary_is_rejected(): void
    {
        $this->assertInvalidAssessment($this->assessment(str_repeat('a', 351)), 'executive_assessment.text: exceeds 350 characters (received 351)');
    }

    public function test_production_style_completed_response_with_overlong_assessment_reports_schema_reason(): void
    {
        // The production payload is not retained. This reproduces one shape that
        // reaches the reported exception after successful JSON parsing.
        $this->assertInvalidAssessment($this->assessment(str_repeat('a', 480)), 'executive_assessment.text: exceeds 350 characters (received 480)');
    }

    public function test_valid_source_ids_are_accepted(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['risk:1', 'kpi:2'];
        self::assertSame($assessment, $this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_unknown_source_ids_are_rejected(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['risk:other'];
        $this->assertInvalidAssessment($assessment, 'executive_assessment.source_ids[0]: unavailable source');
    }

    public function test_invalid_evidence_basis_is_rejected(): void
    {
        $assessment = $this->assessment();
        $assessment['basis'] = 'certain';
        $this->assertInvalidAssessment($assessment, 'executive_assessment.basis: expected explicit or inferred');
    }

    public function test_complete_valid_v2_summary_is_accepted(): void
    {
        $summary = $this->base() + [
            'executive_assessment' => $this->assessment(),
            'material_findings' => [[
                'title' => 'Debt increased', 'category' => 'financial', 'explanation' => 'Debt rose.',
                'why_it_matters' => 'It may constrain funding.', 'severity' => 'high',
                'basis' => 'explicit', 'source_ids' => ['risk:1'],
            ]],
            'trends' => [[
                'observation' => 'Debt rose while cash fell.', 'significance' => 'Liquidity is under pressure.',
                'basis' => 'inferred', 'source_ids' => ['risk:1', 'kpi:2'],
            ]],
            'tensions' => [],
            'questions' => [[
                'question' => 'What is the funding plan?', 'reason' => 'Debt increased.',
                'basis' => 'inferred', 'source_ids' => ['risk:1'],
            ]],
        ];
        self::assertSame($summary, (new ResponseValidator)->validateSummary($summary, ['risk:1', 'kpi:2']));
    }

    public function test_oversized_structured_list_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid structured assessment list: questions');
        $this->validate([
            'questions' => array_fill(0, 4, ['question' => 'What changed?', 'reason' => 'Unclear.', 'source_ids' => ['risk:1']]),
        ]);
    }

    public function test_legacy_summary_remains_readable(): void
    {
        self::assertSame($this->base(), (new ResponseValidator)->validateSummary($this->base()));
    }
}
