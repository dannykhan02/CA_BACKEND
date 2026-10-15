<?php

namespace Tests\Unit;

use App\Services\Intelligence\CoverageStateBuilder;
use PHPUnit\Framework\TestCase;

class IntelligenceCoverageStagesTest extends TestCase
{
    private function pipeline(array $coverage = []): array
    {
        return ['route' => 'incremental', 'synthesis' => 'completed', 'coverage' => $coverage + [
            'evidence_total' => 3, 'evidence_omitted' => 0, 'unresolved_references' => 0,
            'failed_chunks' => 0, 'total_chunks' => 2, 'dropped_records' => 0,
            'saturated_chunks' => 0, 'comprehensive' => true, 'source_text' => 'full',
            'synthesis_level' => 0, 'warning' => null,
        ]];
    }

    public function test_stage_ledger_uses_only_observable_facts_and_keeps_unknown_numbers_null(): void
    {
        $result = (new CoverageStateBuilder)->build($this->pipeline(), true);

        self::assertSame('complete', $result['state']);
        self::assertSame(['ingestion', 'extraction', 'synthesis'], array_keys($result['stages']));
        self::assertSame('unknown', $result['stages']['ingestion']['status']);
        self::assertSame(['ingested_bytes', 'failed_items'], $result['stages']['ingestion']['unknown_facts']);
        self::assertNull($result['stages']['ingestion']['ingested_bytes']);
        self::assertNull($result['stages']['ingestion']['failed_items']);
        self::assertSame('complete', $result['stages']['extraction']['status']);
        self::assertSame('complete', $result['stages']['synthesis']['status']);
        self::assertIsInt($result['failed_chunks']);
        self::assertIsInt($result['evidence_total']);
        self::assertArrayNotHasKey('review', $result['stages']);
    }

    public function test_missing_required_extraction_fact_prevents_top_level_complete_without_faking_zero(): void
    {
        $pipeline = $this->pipeline();
        unset($pipeline['coverage']['failed_chunks']);
        $result = (new CoverageStateBuilder)->build($pipeline, true);

        self::assertSame('bounded', $result['state']);
        self::assertContains('failed_chunks_unknown', $result['reasons']);
        self::assertSame(0, $result['failed_chunks']); // V1 field retains its existing integer fallback.
        self::assertNull($result['stages']['extraction']['failed_chunks']);
        self::assertContains('failed_chunks', $result['stages']['extraction']['unknown_facts']);
        self::assertSame('unknown', $result['stages']['extraction']['status']);
    }

    public function test_stage_precedence_is_failure_then_unavailable_then_bounded_then_unknown(): void
    {
        $builder = new CoverageStateBuilder;
        $failed = $builder->build($this->pipeline(['failed_chunks' => 1, 'total_chunks' => 0]), true);
        self::assertSame('partial', $failed['stages']['extraction']['status']);
        $unavailable = $builder->build($this->pipeline(['total_chunks' => 0]), true);
        self::assertSame('unavailable', $unavailable['stages']['extraction']['status']);
        $bounded = $builder->build($this->pipeline(['evidence_omitted' => 1]), true);
        self::assertSame('bounded', $bounded['stages']['synthesis']['status']);
        $pipeline = $this->pipeline();
        unset($pipeline['coverage']['synthesis_level']);
        self::assertSame('unknown', $builder->build($pipeline, true)['stages']['synthesis']['status']);
    }

    public function test_hierarchical_presentation_selectivity_does_not_mark_evidence_coverage_partial(): void
    {
        $coverage = ['evidence_total' => 799, 'evidence_omitted' => 0,
            'synthesis_considered' => 799, 'synthesis_uncovered' => 0,
            'presentation_selected' => 42, 'presentation_omitted' => 757,
            'source_text' => 'omitted', 'synthesis_level' => 3];
        $result = (new CoverageStateBuilder)->build($this->pipeline($coverage), true);
        self::assertSame('complete', $result['state']);
        self::assertSame('complete', $result['stages']['synthesis']['status']);
        self::assertSame(757, $result['presentation_omitted']);

        $partial = (new CoverageStateBuilder)->build($this->pipeline([
            ...$coverage, 'evidence_omitted' => 8, 'synthesis_uncovered' => 8,
            'synthesis_considered' => 791, 'comprehensive' => false,
        ]), true);
        self::assertSame('partial', $partial['state']);
        self::assertSame('partial', $partial['stages']['synthesis']['status']);
    }

    public function test_completed_synthesis_with_missing_summary_uses_persisted_coverage(): void
    {
        $builder = new CoverageStateBuilder;
        $partial = $builder->build($this->pipeline(['evidence_omitted' => 36,
            'synthesis_considered' => 667, 'synthesis_uncovered' => 36,
            'comprehensive' => false]), false);
        self::assertSame('partial', $partial['stages']['synthesis']['status']);
        self::assertContains('summary_artifact', $partial['stages']['synthesis']['unknown_facts']);

        $complete = $builder->build($this->pipeline(['synthesis_considered' => 703,
            'synthesis_uncovered' => 0]), false);
        self::assertSame('unknown', $complete['stages']['synthesis']['status']);
        $unfinished = $this->pipeline();
        $unfinished['synthesis'] = 'failed';
        self::assertSame('unavailable', $builder->build($unfinished, false)['stages']['synthesis']['status']);
    }
}
