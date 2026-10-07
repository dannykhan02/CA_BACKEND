<?php

namespace Tests\Unit;

use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\ProactiveChunkRisk;
use App\Services\AI\Incremental\SourceSpanBuilder;
use Tests\TestCase;

class ProactiveChunkRiskTest extends TestCase
{
    public function test_large_narrative_with_output_headroom_is_not_split(): void
    {
        $text = str_repeat('The report describes governance and community work in ordinary prose. ', 150);
        $risk = app(ProactiveChunkRisk::class)->assess($text, 12000);
        self::assertFalse($risk['split']);
        self::assertLessThan($risk['safe_output_tokens'], $risk['expected_output_tokens']);
    }

    public function test_dense_numeric_table_crosses_output_threshold(): void
    {
        $text = str_repeat("Revenue | 2024 | 2023 | 2022\n", 160);
        $risk = app(ProactiveChunkRisk::class)->assess($text, 12000);
        self::assertTrue($risk['split']);
        self::assertSame(160, $risk['table_rows']);
        self::assertGreaterThan($risk['safe_output_tokens'], $risk['expected_output_tokens']);
    }

    public function test_minimum_size_prevents_split_even_with_output_pressure(): void
    {
        config(['document_intelligence.minimum_split_chars' => 2000]);
        $risk = app(ProactiveChunkRisk::class)->assess(str_repeat("Revenue | 2024 | 2023\n", 80), 20000);
        self::assertFalse($risk['split']);
    }

    public function test_structural_density_uses_ninety_percent_output_guard_band(): void
    {
        config(['document_intelligence.minimum_split_chars' => 10]);
        $risk = app(ProactiveChunkRisk::class)->assess(str_repeat("Revenue | 2024 | 2023\n", 48), 1000);
        self::assertTrue($risk['split']);
        self::assertFalse($risk['output_saturation_risk']);
        self::assertGreaterThanOrEqual((int) floor($risk['safe_output_tokens'] * 0.9), $risk['expected_output_tokens']);
    }

    public function test_span_split_keeps_every_id_once_and_prefers_heading(): void
    {
        $text = "1. FINANCIAL REVIEW\n\n".str_repeat("Revenue | 2024 | 2023\n", 20)
            ."\n2. GOVERNANCE REVIEW\n\n".str_repeat("Controls | 2024 | 2023\n", 20);
        $spans = new EvidenceSpanSet('test', $text, app(SourceSpanBuilder::class)->build($text));
        $children = app(ChunkPlanner::class)->splitAtBoundary($text, 0, mb_strlen($text), 100, $spans, 1);
        self::assertCount(2, $children);
        self::assertSame($children[0]['end_offset'], $children[1]['start_offset']);
        self::assertSame($spans->keys(), [...$spans->forRange($children[0]['start_offset'], $children[0]['end_offset'])->keys(),
            ...$spans->forRange($children[1]['start_offset'], $children[1]['end_offset'])->keys()]);
        self::assertSame(hash('sha256', mb_substr($text, 0, $children[0]['end_offset'])), $children[0]['input_hash']);
    }

    public function test_legacy_split_prefers_section_boundary_and_preserves_text(): void
    {
        $text = str_repeat("Revenue | 2024 | 2023\n", 15)."\n2. GOVERNANCE REVIEW\n\n"
            .str_repeat("Controls | 2024 | 2023\n", 15);
        $children = app(ChunkPlanner::class)->splitAtBoundary($text, 0, mb_strlen($text), 100, null, 1);
        self::assertCount(2, $children);
        self::assertStringStartsWith('2. GOVERNANCE REVIEW', ltrim(mb_substr($text, $children[1]['start_offset'])));
        self::assertSame(mb_strlen($text), $children[1]['end_offset']);
        self::assertSame($children[0]['end_offset'], $children[1]['start_offset']);
    }
}
