<?php

namespace Tests\Unit;

use App\Services\Intelligence\Values\ValueParser;
use Tests\TestCase;

class IntelligenceSharedDateRecognizerTest extends TestCase
{
    public function test_typed_dates_use_the_validators_unambiguous_grounded_forms(): void
    {
        $parser = app(ValueParser::class);
        foreach ([
            '2025/03/14' => '2025-03-14',
            '14.03.2025' => '2025-03-14',
            '14/03/2025' => '2025-03-14',
            '1st March 2025' => '2025-03-01',
            'Mar. 14, 2025' => '2025-03-14',
        ] as $raw => $iso) {
            $record = ['kind' => 'deadline', 'value' => 'Payment is due',
                'date_type' => 'explicit', 'due_date' => $iso];
            $date = $parser->parse($record, ['Payment is due on '.$raw.'.'])['dates']['due_date'] ?? null;
            self::assertSame($raw, $date['raw'] ?? null, $raw);
            self::assertSame($iso, $date['date'] ?? null, $raw);
        }

        $ambiguous = $parser->parse(['kind' => 'deadline', 'date_type' => 'explicit',
            'due_date' => '2025-04-03'], ['Payment is due on 03/04/2025.']);
        self::assertArrayNotHasKey('due_date', $ambiguous['dates']);
    }
}
