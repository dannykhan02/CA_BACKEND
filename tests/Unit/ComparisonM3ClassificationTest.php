<?php

namespace Tests\Unit;

use App\Support\DeadlineClassification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComparisonM3ClassificationTest extends TestCase
{
    public static function types(): array
    {
        $cases = [];
        foreach (DeadlineClassification::OBLIGATIONS as $type) {
            $cases[$type] = [strtoupper(str_replace('_', '-', $type)), 'obligation'];
        }

        return $cases + ['followup' => ['action item', 'follow-up'], 'mere date' => ['date', 'dated event'],
            'unknown with date' => [null, 'dated event'], 'milestone' => ['milestone', 'dated event']];
    }

    #[DataProvider('types')]
    public function test_shared_classification_uses_extracted_type_not_label_or_date(?string $type, string $expected): void
    {
        $this->assertSame($expected, DeadlineClassification::classify($type, 'explicit', '2026-10-30'));
    }

    public function test_undated_obligation_is_retained_and_unknown_undated_record_is_not_promoted(): void
    {
        $this->assertSame('obligation', DeadlineClassification::classify('payment'));
        $this->assertSame('unclassified', DeadlineClassification::classify('unknown'));
    }
}
