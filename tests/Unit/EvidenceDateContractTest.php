<?php

namespace Tests\Unit;

use App\Exceptions\AiProcessingException;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\SourceSpanBuilder;
use Tests\TestCase;

class EvidenceDateContractTest extends TestCase
{
    private function record(array $changes = []): array
    {
        return array_replace(['kind' => 'fact', 'label' => 'Finding', 'value' => 'Grounded observation',
            'subject' => '', 'reference' => '', 'entity_type' => null, 'unit' => null,
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => null, 'date_type' => null, 'due_date' => null, 'severity' => null,
            'confidence' => 0.9, 'aliases' => [], 'evidence_ids' => ['E001']], $changes);
    }

    private function validate(string $source, array $records): array
    {
        $version = 'date-contract';
        $spans = new EvidenceSpanSet($version, $source, app(SourceSpanBuilder::class)->build($source, 1));

        return EvidenceSchema::validate(['records' => $records], $source, $spans, $version);
    }

    private function rejected(string $source, array $record): array
    {
        try {
            $this->validate($source, [$record]);
            self::fail('Expected an invalid date record.');
        } catch (AiProcessingException $e) {
            self::assertSame('invalid_date', $e->classification);

            return $e->diagnostics;
        }
    }

    public function test_span_prompt_states_complete_partial_and_relative_examples_without_invented_precision(): void
    {
        $prompt = EvidenceSchema::instructions(EvidenceGrounding::SPANS);
        foreach (['31 March 2025', '2025-03-31', 'March 2025', '2025', 'FY2025', 'Q3 2026',
            'second quarter of 2025', 'within 30 days after execution', 'date_type=null, due_date=null',
            'date_type="relative", due_date=null', 'Never invent a missing day, month or year'] as $phrase) {
            self::assertStringContainsString($phrase, $prompt);
        }
        self::assertStringNotContainsString('record it with date_type relative or inferred', $prompt);
    }

    public function test_provider_schema_constrains_date_type_with_supported_keywords(): void
    {
        foreach ([EvidenceGrounding::SPANS, EvidenceGrounding::LEGACY] as $mode) {
            $schema = EvidenceSchema::extraction($mode);
            $date = $schema['properties']['records']['items']['properties']['date_type'];
            self::assertArrayNotHasKey('type', $date);
            self::assertSame(['anyOf' => [
                ['type' => 'string', 'enum' => ['explicit', 'relative', 'inferred']],
                ['type' => 'null'],
            ]], $date);
            self::assertNotContains('calendar', $date['anyOf'][0]['enum']);
            $this->assertSupportedSchema($schema);
        }
    }

    private function assertSupportedSchema(array $schema): void
    {
        foreach ($schema as $key => $value) {
            self::assertContains($key, ['type', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'enum', 'anyOf',
                'records', 'kind', 'label', 'value', 'subject', 'quote', 'reference', 'evidence_ids', 'entity_type', 'unit',
                'period', 'date_type', 'due_date', 'severity', 'metric_type', 'value_basis', 'aggregation',
                'quantity_kind', 'confidence', 'aliases']);
            if ($key === 'minItems') {
                self::assertContains($value, [0, 1]);
            }
            if ($key === 'anyOf') {
                foreach ($value as $branch) {
                    $this->assertSupportedSchema($branch);
                }
            } elseif (is_array($value) && ! array_is_list($value)) {
                $this->assertSupportedSchema($value);
            }
        }
    }

    public function test_application_date_types_remain_accepted_where_allowed(): void
    {
        $cases = [
            ['Payment is due on 31 March 2025.', ['kind' => 'deadline', 'date_type' => 'explicit', 'due_date' => '2025-03-31']],
            ['Submit within 30 days after execution.', ['kind' => 'deadline', 'date_type' => 'relative']],
            ['An obligation may arise from the agreement.', ['kind' => 'obligation', 'date_type' => 'inferred']],
            ['Annual lending in FY2025 was substantial.', ['kind' => 'fact', 'date_type' => null, 'period' => 'FY2025']],
        ];
        foreach ($cases as [$source, $changes]) {
            $result = $this->validate($source, [$this->record($changes)]);
            self::assertSame($changes['date_type'], $result['records'][0]['date_type']);
            self::assertSame($changes['due_date'] ?? null, $result['records'][0]['due_date']);
            self::assertSame(0, $result['_validation']['records_dropped']);
            self::assertSame(0, $result['_validation']['records_date_metadata_sanitized']);
        }
    }

    public function test_application_still_rejects_a_non_string_non_null_date_type(): void
    {
        $result = $this->validate('Annual lending in FY2025 was substantial.', [
            $this->record(), $this->record(['date_type' => 42]),
        ]);
        self::assertSame(1, $result['_validation']['records_kept']);
        self::assertSame(['invalid_schema' => 1], $result['_validation']['rejections']);
        self::assertSame(['wrong_field_type' => 1], $result['_validation']['rejection_reasons']);
    }

    public function test_complete_date_and_relative_deadline_keep_their_semantics(): void
    {
        $complete = $this->validate('The payment is due on 31 March 2025.', [
            $this->record(['kind' => 'deadline', 'value' => 'Payment due', 'date_type' => 'explicit', 'due_date' => '2025-03-31']),
        ])['records'][0];
        self::assertSame('explicit', $complete['date_type']);
        self::assertSame('2025-03-31', $complete['due_date']);

        $relative = $this->validate('Submit within 30 days after execution.', [
            $this->record(['kind' => 'obligation', 'value' => 'Submit within 30 days after execution',
                'date_type' => 'relative']),
        ])['records'][0];
        self::assertSame('relative', $relative['date_type']);
        self::assertNull($relative['due_date']);
        self::assertStringContainsString('within 30 days after execution', $relative['value']);
    }

    public function test_partial_periods_never_acquire_a_fabricated_due_date(): void
    {
        foreach (['March 2025', '2025', 'FY2025', 'Q3 2026', 'second quarter of 2025'] as $period) {
            $result = $this->validate("Annual lending for {$period} was $12.4 billion.", [
                $this->record(['kind' => 'metric', 'label' => 'Annual lending', 'value' => '$12.4 billion',
                    'period' => $period, 'date_type' => 'explicit']),
            ]);
            $record = $result['records'][0];
            self::assertNull($record['date_type']);
            self::assertNull($record['due_date']);
            self::assertSame($period, $record['period']);
            self::assertSame('$12.4 billion', $record['value']);
            self::assertSame(1, $result['_validation']['date_metadata_sanitized']['partial_period_preserved']);

            $fabricated = $this->validate("Annual lending for {$period} was $12.4 billion.", [
                $this->record(['kind' => 'metric', 'period' => $period, 'date_type' => 'explicit',
                    'due_date' => $period === 'Q3 2026' ? '2026-07-01' : '2025-03-01']),
            ]);
            self::assertNull($fabricated['records'][0]['date_type']);
            self::assertNull($fabricated['records'][0]['due_date']);
            self::assertSame(1, $fabricated['_validation']['date_metadata_sanitized']['unsupported_explicit_date_removed']);
        }
    }

    public function test_noncritical_optional_dates_are_sanitized_after_grounding(): void
    {
        $result = $this->validate('Annual lending in FY2024 was $12.4 billion.', [
            $this->record(['kind' => 'fact', 'value' => '$12.4 billion', 'period' => 'FY2024', 'date_type' => 'explicit']),
            $this->record(['kind' => 'metric', 'value' => '$12.4 billion', 'period' => 'FY2024',
                'date_type' => null, 'due_date' => '2024-01-01']),
        ]);
        self::assertCount(2, $result['records']);
        self::assertSame('$12.4 billion', $result['records'][0]['value']);
        self::assertSame('Annual lending in FY2024 was $12.4 billion.', $result['records'][0]['quote']);
        self::assertSame(['E001'], $result['records'][0]['evidence_ids']);
        self::assertNull($result['records'][0]['date_type']);
        self::assertNull($result['records'][1]['due_date']);
        self::assertSame(2, $result['_validation']['records_date_metadata_sanitized']);
        self::assertSame(0, $result['_validation']['records_dropped']);
        self::assertSame(1, $result['_validation']['date_metadata_sanitized']['explicit_without_due_date_noncritical']);
        self::assertSame(1, $result['_validation']['date_metadata_sanitized']['nonexplicit_due_date_removed']);
        self::assertSame(2, $result['_validation']['date_metadata_sanitized']['partial_period_preserved']);
    }

    public function test_unsupported_types_and_malformed_or_calendar_invalid_dates_remain_rejected(): void
    {
        $source = 'Payment due 31 March 2025.';
        foreach ([
            [$this->record(['kind' => 'deadline', 'date_type' => 'explicit']), 'explicit_date_missing_due_date'],
            [$this->record(['kind' => 'obligation', 'date_type' => 'calendar']), 'invalid_deadline_date_type'],
            [$this->record(['date_type' => 'calendar']), 'invalid_date_type'],
            [$this->record(['kind' => 'deadline', 'date_type' => 'explicit', 'due_date' => '2025/03/31']), 'due_date_wrong_format'],
            [$this->record(['kind' => 'deadline', 'date_type' => 'explicit', 'due_date' => '2025-02-30']), 'due_date_invalid_calendar_date'],
            [$this->record(['date_type' => 'explicit', 'due_date' => '2025-02-30']), 'due_date_invalid_calendar_date'],
            [$this->record(['kind' => 'deadline', 'date_type' => 'explicit', 'due_date' => '2025-03-01']), 'explicit_date_not_in_evidence'],
        ] as [$record, $reason]) {
            $diagnostics = $this->rejected($source, $record);
            self::assertSame(1, $diagnostics['rejection_reasons'][$reason]);
            self::assertSame(1, $diagnostics['date_rejection_reasons_by_kind'][$reason][$record['kind']]);
        }
    }

    public function test_date_diagnostics_reconcile_by_kind_and_contain_no_source_text(): void
    {
        $source = 'PRIVATE BOARD PAPER: Annual lending in FY2024 was $12.4 billion.';
        $result = $this->validate($source, [
            $this->record(['kind' => 'fact', 'date_type' => 'explicit', 'period' => 'FY2024']),
            $this->record(['kind' => 'metric', 'date_type' => 'explicit', 'period' => 'FY2024']),
            $this->record(['kind' => 'deadline', 'date_type' => 'explicit']),
            $this->record(['kind' => 'obligation', 'date_type' => 'calendar']),
        ]);
        $d = $result['_validation'];
        self::assertSame(4, $d['records_returned']);
        self::assertSame(2, $d['records_kept']);
        self::assertSame(2, $d['records_dropped']);
        self::assertSame(2, $d['records_date_metadata_sanitized']);
        self::assertSame(['fact' => 1, 'metric' => 1], $d['date_metadata_sanitized_by_kind']['explicit_without_due_date_noncritical']);
        self::assertSame(['deadline' => 1], $d['date_rejection_reasons_by_kind']['explicit_date_missing_due_date']);
        self::assertSame(['obligation' => 1], $d['date_rejection_reasons_by_kind']['invalid_deadline_date_type']);
        self::assertSame(array_sum($d['rejection_reasons']), array_sum(array_map('array_sum', $d['date_rejection_reasons_by_kind'])));
        self::assertStringNotContainsString('PRIVATE BOARD PAPER', json_encode($d));
        self::assertStringNotContainsString('$12.4 billion', json_encode($d));
    }
}
