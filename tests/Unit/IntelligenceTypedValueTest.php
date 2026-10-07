<?php

namespace Tests\Unit;

use App\Services\Intelligence\Values\ValueParser;
use Tests\TestCase;

class IntelligenceTypedValueTest extends TestCase
{
    public function test_grounded_trillion_metric_retains_source_scale_and_stored_measure_status(): void
    {
        $typed = app(ValueParser::class)->parse([
            'kind' => 'metric', 'label' => 'Assets', 'value' => '2.1 trillion', 'unit' => 'USD',
            'subject' => 'Group', 'metric_type' => 'target', 'value_basis' => 'total',
        ], ['Assets: 2.1 trillion USD for Group.']);

        self::assertSame('money', $typed['value']['type']);
        self::assertSame(1e12, $typed['value']['scale']);
        self::assertSame(2.1e12, $typed['value']['number']);
        self::assertSame('target', $typed['value']['measure_status']);
        self::assertSame(['id' => null, 'text' => 'Group'], $typed['value']['entity_ref']);
        self::assertSame('values.v1', $typed['value']['parser_version']);
    }

    public function test_confirmed_entity_id_is_only_added_when_supplied_as_confirmed(): void
    {
        $record = ['kind' => 'metric', 'label' => 'Revenue', 'value' => '50%', 'unit' => '%',
            'subject' => 'Agency', 'value_basis' => 'forecast'];
        $without = app(ValueParser::class)->parse($record, ['Agency revenue was 50%.']);
        $with = app(ValueParser::class)->parse($record, ['Agency revenue was 50%.'], 'entity:confirmed');

        self::assertNull($without['value']['entity_ref']['id']);
        self::assertSame('entity:confirmed', $with['value']['entity_ref']['id']);
        self::assertSame('forecast', $with['value']['measure_status']);
    }

    public function test_period_only_obligation_is_a_period_resolved_due_date_without_calendar_date(): void
    {
        $typed = app(ValueParser::class)->parse([
            'kind' => 'obligation', 'value' => 'Payment is due in Q3 2026', 'period' => 'Q3 2026',
            'date_type' => null, 'due_date' => null,
        ], ['Payment is due in Q3 2026.']);

        self::assertSame('period', $typed['dates']['due_date']['type']);
        self::assertSame('period', $typed['dates']['due_date']['resolution']);
        self::assertSame('Q3 2026', $typed['dates']['due_date']['period']['text']);
        self::assertNull($typed['dates']['due_date']['date']);
    }

    public function test_complete_date_uses_a_verbatim_source_phrase(): void
    {
        $typed = app(ValueParser::class)->parse([
            'kind' => 'deadline', 'value' => 'Payment due', 'date_type' => 'explicit',
            'due_date' => '2025-03-31',
        ], ['Payment due on 31 March 2025.']);

        self::assertSame('31 March 2025', $typed['dates']['due_date']['raw']);
        self::assertSame('2025-03-31', $typed['dates']['due_date']['date']);
    }

    public function test_ungrounded_value_is_not_typed_and_fiscal_year_is_not_anchored(): void
    {
        $typed = app(ValueParser::class)->parse([
            'kind' => 'metric', 'value' => '4 trillion', 'unit' => 'USD', 'period' => 'FY2025',
        ], ['The stated period was FY2025.']);

        self::assertNull($typed['value']);
        self::assertFalse($typed['dates']['period_covered']['period']['anchored']);
        self::assertNull($typed['dates']['period_covered']['date']);
    }
}
