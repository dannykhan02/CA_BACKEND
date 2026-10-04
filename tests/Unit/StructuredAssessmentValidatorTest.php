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
        return (new ResponseValidator)->validateSummary($this->base() + $additional, [
            'risk:1', 'kpi:2', 'entity:3', 'deadline:4', 'risk:5',
        ]);
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

    public function test_assessment_text_at_351_characters_is_trimmed_to_a_word_boundary(): void
    {
        $text = str_repeat('word ', 70).'x';
        self::assertSame(351, mb_strlen($text));
        $normalized = $this->validate(['executive_assessment' => $this->assessment($text)]);
        self::assertSame(str_repeat('word ', 69).'word', $normalized['executive_assessment']['text']);
    }

    public function test_351_character_material_finding_explanation_is_normalized(): void
    {
        $text = str_repeat('a', 345).' final';
        self::assertSame(351, mb_strlen($text));
        $finding = $this->finding($text);
        $normalized = $this->validate(['material_findings' => [$finding]]);
        self::assertSame(str_repeat('a', 345), $normalized['material_findings'][0]['explanation']);
    }

    public function test_371_character_material_finding_explanation_is_normalized(): void
    {
        $text = str_repeat('word ', 70).'renewed warning here!';
        self::assertSame(371, mb_strlen($text));
        $normalized = $this->validate(['material_findings' => [$this->finding($text)]]);
        self::assertLessThanOrEqual(350, mb_strlen($normalized['material_findings'][0]['explanation']));
        self::assertStringEndsWith('word', $normalized['material_findings'][0]['explanation']);
    }

    private function finding(string $explanation): array
    {
        return ['title' => 'Debt increased', 'category' => 'financial', 'explanation' => $explanation,
            'why_it_matters' => 'Funding pressure.', 'severity' => 'high', 'basis' => 'explicit',
            'source_ids' => ['risk:1']];
    }

    public function test_overlong_structural_category_is_still_rejected(): void
    {
        $finding = $this->finding('Debt rose.');
        $finding['category'] = str_repeat('x', 351);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('material_findings[0].category: exceeds 350 characters');
        $this->validate(['material_findings' => [$finding]]);
    }

    public function test_normalized_prose_does_not_bypass_evidence_and_enum_checks(): void
    {
        $finding = $this->finding(str_repeat('word ', 70).'renewed warning here!');
        $finding['source_ids'] = ['risk:unknown'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('material_findings[0].source_ids[0]: unavailable source');
        $this->validate(['material_findings' => [$finding]]);
    }

    public function test_invalid_finding_source_still_fails_when_assessment_is_normalized_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = [];
        $finding = $this->finding('Debt rose.');
        $finding['source_ids'] = ['risk:unknown'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('material_findings[0].source_ids[0]: unavailable source');
        $this->validate(['executive_assessment' => $assessment, 'material_findings' => [$finding]]);
    }

    public function test_long_unbroken_prose_token_is_not_damaged(): void
    {
        $this->assertInvalidAssessment($this->assessment(str_repeat('a', 371)), 'no safe word boundary');
    }

    public function test_one_valid_assessment_source_id_is_accepted(): void
    {
        $assessment = $this->assessment();
        self::assertSame($assessment, $this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_four_valid_assessment_source_ids_are_accepted(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['risk:1', 'kpi:2', 'entity:3', 'deadline:4'];
        self::assertSame($assessment, $this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_empty_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = [];
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_missing_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        unset($assessment['source_ids']);
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_null_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = null;
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_unknown_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['risk:other'];
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_non_string_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = [123];
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_five_assessment_source_ids_normalize_the_assessment_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['risk:1', 'kpi:2', 'entity:3', 'deadline:4', 'risk:5'];
        self::assertNull($this->validate(['executive_assessment' => $assessment])['executive_assessment']);
    }

    public function test_assessment_source_with_unapproved_prefix_normalizes_to_null(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = ['other:6'];
        $summary = $this->base() + ['executive_assessment' => $assessment];
        self::assertNull((new ResponseValidator)->validateSummary($summary, ['other:6'])['executive_assessment']);
    }

    public function test_invalid_assessment_basis_still_fails_even_when_sources_are_missing(): void
    {
        $assessment = $this->assessment();
        $assessment['source_ids'] = [];
        $assessment['basis'] = 'certain';
        $this->assertInvalidAssessment($assessment, 'executive_assessment.basis: expected explicit or inferred');
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
