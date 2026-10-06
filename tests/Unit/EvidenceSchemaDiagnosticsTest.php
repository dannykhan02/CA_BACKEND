<?php

namespace Tests\Unit;

use App\Exceptions\AiProcessingException;
use App\Services\AI\Incremental\EvidenceSchema;
use PHPUnit\Framework\TestCase;

class EvidenceSchemaDiagnosticsTest extends TestCase
{
    private const SOURCE = 'PRIVATE SOURCE: Revenue increased to USD 10 in 2024.';

    private function record(array $changes = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Revenue', 'value' => '10', 'subject' => 'Company',
            'quote' => self::SOURCE, 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null,
            'confidence' => 0.95, 'aliases' => []], $changes);
    }

    public function test_all_valid_and_empty_responses_have_zero_rejections(): void
    {
        foreach ([[], [$this->record()]] as $records) {
            $result = EvidenceSchema::validate(['records' => $records], self::SOURCE);
            self::assertSame(count($records), $result['_validation']['records_returned']);
            self::assertSame(count($records), $result['_validation']['records_kept']);
            self::assertSame(0, $result['_validation']['records_dropped']);
            self::assertSame([], $result['_validation']['rejections']);
            self::assertSame([], $result['_validation']['rejection_reasons']);
        }
    }

    public function test_every_current_record_rule_has_one_stable_reason_and_reconciles(): void
    {
        $missing = $this->record();
        unset($missing['value']);
        $invalid = [
            [null, 'invalid_schema', 'malformed_record'],
            [$missing, 'invalid_schema', 'missing_required_field'],
            [$this->record(['confidence' => 'high']), 'invalid_schema', 'wrong_field_type'],
            [$this->record(['kind' => 'unknown']), 'invalid_schema', 'invalid_kind'],
            [$this->record(['confidence' => 1.5]), 'invalid_evidence', 'confidence_out_of_range'],
            [$this->record(['quote' => ' ']), 'invalid_evidence', 'blank_quote'],
            [$this->record(['label' => ' ']), 'invalid_evidence', 'blank_label'],
            [$this->record(['quote' => 'SECRET REJECTED QUOTE']), 'invalid_evidence', 'quote_not_found_in_source'],
            [$this->record(['date_type' => 'explicit']), 'invalid_date', 'explicit_date_missing_due_date'],
            [$this->record(['date_type' => 'explicit', 'due_date' => '2024/02/01']), 'invalid_date', 'due_date_wrong_format'],
            [$this->record(['date_type' => 'explicit', 'due_date' => '2024-02-30']), 'invalid_date', 'due_date_invalid_calendar_date'],
            [$this->record(['date_type' => 'relative', 'due_date' => '2024-02-01']), 'invalid_date', 'due_date_present_for_non_explicit_type'],
            [$this->record(['kind' => 'deadline']), 'invalid_date', 'invalid_deadline_date_type'],
        ];
        $result = EvidenceSchema::validate(['records' => [$this->record(), ...array_column($invalid, 0)]], self::SOURCE);
        $diagnostics = $result['_validation'];
        self::assertSame(count($invalid) + 1, $diagnostics['records_returned']);
        self::assertSame(1, $diagnostics['records_kept']);
        self::assertSame(count($invalid), $diagnostics['records_dropped']);
        self::assertSame($diagnostics['records_returned'], $diagnostics['records_kept'] + $diagnostics['records_dropped']);
        foreach ($invalid as [, $class, $reason]) {
            self::assertSame(1, $diagnostics['rejection_reasons'][$reason]);
        }
        foreach (['invalid_schema', 'invalid_date', 'invalid_evidence'] as $class) {
            $expected = count(array_filter($invalid, fn ($row) => $row[1] === $class));
            self::assertSame($expected, $diagnostics['rejections'][$class]);
            self::assertSame($expected, array_sum(array_intersect_key($diagnostics['rejection_reasons'],
                array_fill_keys(array_column(array_filter($invalid, fn ($row) => $row[1] === $class), 2), true))));
        }
        self::assertStringNotContainsString('PRIVATE SOURCE', json_encode($diagnostics));
        self::assertStringNotContainsString('SECRET REJECTED QUOTE', json_encode($diagnostics));
    }

    public function test_all_invalid_response_carries_full_distribution_without_values(): void
    {
        try {
            EvidenceSchema::validate(['records' => [
                $this->record(['quote' => 'SECRET REJECTED QUOTE']),
                $this->record(['date_type' => 'explicit']),
                $this->record(['kind' => 'unknown']),
            ]], self::SOURCE);
            self::fail('Expected all-invalid extraction to fail.');
        } catch (AiProcessingException $e) {
            self::assertSame('invalid_evidence', $e->classification);
            self::assertSame(3, $e->diagnostics['records_returned']);
            self::assertSame(0, $e->diagnostics['records_kept']);
            self::assertSame(3, $e->diagnostics['records_dropped']);
            self::assertSame(['invalid_schema' => 1, 'invalid_evidence' => 1, 'invalid_date' => 1], $e->diagnostics['rejections']);
            self::assertCount(3, $e->diagnostics['rejection_reasons']);
            self::assertStringNotContainsString('SECRET', json_encode($e->diagnostics));
        }
    }
}
