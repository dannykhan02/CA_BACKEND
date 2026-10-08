<?php

namespace Tests\Unit;

use App\Models\DocumentSourceSpan;
use App\Services\Intelligence\ProvenanceDiagnostic;
use Tests\TestCase;

class IntelligenceProvenanceDiagnosticTest extends TestCase
{
    private function record(string $label, string $value, string $quote, ?string $period = null,
        ?string $unit = null, ?string $section = null): array
    {
        return ['source_id' => 'kpi:synthetic', 'kind' => 'metric', 'page' => 1,
            'data' => ['label' => $label, 'value' => $value, 'quote' => $quote, 'period' => $period,
                'unit' => $unit, 'due_date' => null], 'section' => $section,
            'sources' => [['span_id' => 'E001', 'page' => 1, 'quote' => $quote, 'extraction_version' => 'v1']]];
    }

    private function span(string $id, int $ordinal, int $start, string $text, string $type = 'table_row'): DocumentSourceSpan
    {
        return new DocumentSourceSpan(['span_key' => $id, 'ordinal' => $ordinal, 'page' => 1,
            'start_offset' => $start, 'end_offset' => $start + mb_strlen($text),
            'type' => $type, 'extraction_version' => 'v1']);
    }

    private function diagnose(array $record, array $context = []): array
    {
        $text = $record['data']['quote'];
        $spans = ['E001' => $this->span('E001', 1, 0, $text)];
        foreach ($context as $ordinal => [$kind, $line]) {
            $start = mb_strlen($text) + 1;
            $text .= "\n".$line;
            $id = 'E'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            $spans[$id] = $this->span($id, $ordinal, $start, $line, $kind);
        }

        return app(ProvenanceDiagnostic::class)->classify($record, $spans, $text);
    }

    public function test_equivalent_scale_notation_is_numeric_equivalent(): void
    {
        $result = $this->diagnose($this->record('Funding', '$1.9M', 'Funding reached $1.9 million.'));
        self::assertSame('value_not_in_quote', $result['original_reason']);
        self::assertSame('numeric_equivalent', $result['diagnostic_bucket']);
    }

    public function test_table_header_supplies_currency_and_scale_for_row_value(): void
    {
        $record = $this->record('Committee funding', 'USD 16.1 million',
            'Belgian Committee for UNICEF | 16.1');
        $result = $this->diagnose($record, [2 => ['table_row', 'Committee | USD millions']]);
        self::assertSame('surrounding_context_supported', $result['diagnostic_bucket']);
        self::assertSame('unit', $result['missing_component']);
        self::assertSame('E002', $result['supporting_spans'][0]['id']);
        self::assertSame('same_table_context', $result['supporting_spans'][0]['relationship']);
    }

    public function test_bounded_heading_supplies_missing_period(): void
    {
        $record = $this->record('Funding', '$174 million', 'Funding | $174 million', '2024', section: 'E002');
        $result = $this->diagnose($record, [2 => ['heading', '2024 Funding']]);
        self::assertSame('period_not_in_quote', $result['original_reason']);
        self::assertSame('surrounding_context_supported', $result['diagnostic_bucket']);
        self::assertSame('period', $result['missing_component']);
        self::assertSame('heading', $result['supporting_spans'][0]['relationship']);
    }

    public function test_generated_value_wording_is_not_treated_as_numeric_notation(): void
    {
        $result = $this->diagnose($this->record('Cases',
            'over 20,000 cases through digital management system',
            'reaching over 20,000 cases through a digital management system'));
        self::assertSame('extraction_wording_mismatch', $result['diagnostic_bucket']);
    }

    public function test_mislabeled_percentage_and_absent_money_are_unsupported(): void
    {
        $mismatch = $this->diagnose($this->record('Core Resources as percentage of total spending',
            '28%', '28% spent on humanitarian action', '2024'));
        self::assertSame('genuinely_unsupported', $mismatch['diagnostic_bucket']);
        self::assertSame('label/evidence semantic mismatch', $mismatch['disagreement']);
        $absent = $this->diagnose($this->record('Mental health and psychosocial well-being funding',
            '$10 million', 'Mental health services were expanded.'));
        self::assertSame('genuinely_unsupported', $absent['diagnostic_bucket']);
        self::assertSame('value absent', $absent['disagreement']);
    }

    public function test_insufficient_evidence_stays_ambiguous(): void
    {
        $result = $this->diagnose($this->record('Impact', 'substantial impact', 'Impact was substantial.'));
        self::assertSame('ambiguous', $result['diagnostic_bucket']);
    }

    public function test_numeric_match_does_not_hide_a_second_missing_period(): void
    {
        $result = $this->diagnose($this->record('Funding', '$1.9M',
            'Funding reached $1.9 million.', '2024'));
        self::assertSame('value_not_in_quote', $result['original_reason']);
        self::assertSame('ambiguous', $result['diagnostic_bucket']);
    }

    public function test_context_beyond_two_ordinals_is_not_used(): void
    {
        $record = $this->record('Funding', '$174 million', 'Funding | $174 million', '2024');
        $result = $this->diagnose($record, [4 => ['table_row', '2024']]);
        self::assertNotSame('surrounding_context_supported', $result['diagnostic_bucket']);
        self::assertSame([], $result['supporting_spans']);
    }

    public function test_adjacent_data_row_is_not_treated_as_a_period_header(): void
    {
        $record = $this->record('Funding', '$174 million', 'Funding | $174 million', '2024');
        $result = $this->diagnose($record, [2 => ['table_row', 'Other programme | $25 million | 2024']]);
        self::assertNotSame('surrounding_context_supported', $result['diagnostic_bucket']);
    }

    public function test_different_extraction_version_cannot_supply_context(): void
    {
        $record = $this->record('Funding', '$174 million', 'Funding | $174 million', '2024', section: 'E002');
        $text = "Funding | $174 million\n2024 Funding";
        $cited = $this->span('E001', 1, 0, 'Funding | $174 million');
        $cited->extraction_version = 'older-version';
        $heading = $this->span('E002', 2, mb_strlen('Funding | $174 million') + 1,
            '2024 Funding', 'heading');
        $result = app(ProvenanceDiagnostic::class)->classify($record,
            ['E001' => $cited, 'E002' => $heading], $text);
        self::assertSame('ambiguous', $result['diagnostic_bucket']);
        self::assertSame([], $result['supporting_spans']);
    }
}
