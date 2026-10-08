<?php

namespace Tests\Unit;

use App\Models\DocumentSourceSpan;
use App\Services\Intelligence\ProvenanceDiagnosticExtension;
use Tests\TestCase;

class IntelligenceProvenanceDiagnosticExtensionTest extends TestCase
{
    private function metric(string $id, string $label, string $value, string $quote,
        ?string $period = null, string $origin = 'unknown', ?array $typed = null,
        ?string $section = null, string $span = 'E005'): array
    {
        return ['identity' => $id, 'source_id' => 'kpi:'.$id, 'kind' => 'metric',
            'data' => ['label' => $label, 'value' => $value, 'quote' => $quote,
                'period' => $period, 'unit' => null],
            'typed' => ['value' => $typed, 'dates' => []],
            'provenance' => ['origin' => $origin, 'assertion' => $origin === 'document' ? 'stated' : 'unspecified'],
            'sources' => [['span_id' => $span, 'page' => 1, 'quote' => $quote,
                'extraction_version' => 'v1']], 'section' => $section,
            'span_type' => 'table_row', 'span_ordinal' => 5, 'page' => 1];
    }

    private function diagnosis(string $bucket, string $reason = 'value_not_in_quote',
        ?string $disagreement = null): array
    {
        return ['diagnostic_bucket' => $bucket, 'original_reason' => $reason,
            'disagreement' => $disagreement, 'source_id' => null, 'span' => null,
            'diagnostic_reason' => 'Synthetic diagnostic', 'supporting_spans' => []];
    }

    private function span(string $key, int $ordinal, int $start, string $line,
        string $type = 'table_row', int $page = 1): DocumentSourceSpan
    {
        return new DocumentSourceSpan(['span_key' => $key, 'ordinal' => $ordinal,
            'start_offset' => $start, 'end_offset' => $start + mb_strlen($line),
            'page' => $page, 'type' => $type, 'extraction_version' => 'v1']);
    }

    private function spans(array $lines): array
    {
        $text = '';
        $spans = [];
        foreach ($lines as $ordinal => [$type, $line, $page]) {
            $start = mb_strlen($text);
            $id = 'E'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            $spans[$id] = $this->span($id, $ordinal, $start, $line, $type, $page);
            $text .= $line."\n";
        }

        return [$spans, $text];
    }

    private function analyze(array $pairs, array $spans = [], string $text = '', ?array $records = null): array
    {
        return app(ProvenanceDiagnosticExtension::class)->analyze($records ?? array_column($pairs, 'record'),
            $pairs, $spans, $text, new \DateTimeImmutable('2026-10-08T00:00:00+00:00'));
    }

    public function test_unsupported_subtypes_are_exclusive_and_keep_exact_quotes(): void
    {
        $cases = [
            ['period', 'Funding', '$174 million', 'Funding | $174 million', '2024', 'period absent', 'period_only_missing'],
            ['value', 'Funding', '$10 million', 'Mental health services expanded.', null, 'value absent', 'value_missing'],
            ['semantic', 'Core Resources as percentage of total spending', '28%',
                '28% spent on humanitarian action', '2024', 'label/evidence semantic mismatch', 'semantic_mismatch'],
            ['unit', 'Funding', 'USD 16.1 million', 'Belgian Committee | 16.1', null,
                'value absent', 'unit_or_scale_missing'],
            ['other', 'Funding', 'unknown', 'Funding discussed.', null, 'other', 'other_unsupported'],
        ];
        $pairs = [];
        foreach ($cases as [$id, $label, $value, $quote, $period, $disagreement]) {
            $pairs[] = ['record' => $this->metric($id, $label, $value, $quote, $period),
                'diagnosis' => $this->diagnosis('genuinely_unsupported', disagreement: $disagreement)];
        }
        $report = $this->analyze($pairs);
        self::assertSame(array_fill_keys(['period_only_missing', 'value_missing', 'semantic_mismatch',
            'unit_or_scale_missing', 'other_unsupported'], 1),
            $report['genuinely_unsupported']['subtype_counts']);
        self::assertSame(array_column($cases, 3),
            array_column($report['genuinely_unsupported']['records'], 'cited_quote'));
    }

    public function test_unique_contiguous_header_rule_and_selector_projection_are_diagnostic_only(): void
    {
        [$spans, $text] = $this->spans([
            1 => ['heading', 'Funding section', 1],
            2 => ['table_row', 'Values | USD millions', 1],
            3 => ['table_row', 'Committee A | 10.0', 1],
            4 => ['table_row', 'Committee B | 12.0', 1],
            5 => ['table_row', 'Belgian Committee | 16.1', 1],
        ]);
        $existing = $this->metric('existing', 'Total funding', 'USD 5 million',
            'Total funding USD 5 million', origin: 'document',
            typed: ['type' => 'money', 'unit_kind' => 'currency', 'currency' => 'USD',
                'number' => 5e6, 'scale' => 1e6, 'raw' => 'USD 5 million']);
        $header = $this->metric('header', 'Committee funding', 'USD 16.1 million',
            'Belgian Committee | 16.1', section: 'E001');
        $numeric = $this->metric('numeric', 'Total funding', 'USD 1.9 million',
            'Total funding reached USD 1.9M');
        $wording = $this->metric('wording', 'Total funding',
            'USD 2 million through a funding programme', 'USD 2 million was provided');
        $pairs = [
            ['record' => $header, 'diagnosis' => $this->diagnosis('ambiguous')],
            ['record' => $numeric, 'diagnosis' => $this->diagnosis('numeric_equivalent')],
            ['record' => $wording, 'diagnosis' => $this->diagnosis('extraction_wording_mismatch')],
        ];
        $report = $this->analyze($pairs, $spans, $text, [$existing, $header, $numeric, $wording]);
        $row = $report['ambiguous']['records'][0];
        self::assertSame('header_context_present_but_unassociated', $row['classification']);
        self::assertTrue($row['deterministic_rule']['supported']);
        self::assertSame(['E002'], $row['deterministic_rule']['candidate_span_ids']);
        self::assertSame(1, $report['candidate_rules'][0]['records_recovered']);
        self::assertSame(1, $report['projection']['current']['key_figure_eligible_count']);
        self::assertSame(3, $report['projection']['conservative']['key_figure_eligible_count']);
        self::assertSame(4, $report['projection']['broader_hypothetical']['key_figure_eligible_count']);
        self::assertSame(3, $report['projection']['conservative']['document_origin']);
        self::assertSame(4, $report['projection']['broader_hypothetical']['document_origin']);
    }

    public function test_untied_context_and_unpreserved_context_stay_unrecoverable(): void
    {
        [$spans, $text] = $this->spans([
            1 => ['heading', 'Funding section', 1],
            2 => ['prose', 'Another programme was discussed.', 1],
            3 => ['table_row', 'Unrelated | 10.0', 1],
            4 => ['prose', 'Another section starts.', 1],
            5 => ['table_row', 'Funding | $174 million', 1],
            6 => ['prose', 'A separate statement mentions 2024.', 1],
        ]);
        $untied = $this->metric('untied', 'Funding', '$174 million',
            'Funding | $174 million', '2024', section: 'E001');
        $notPreserved = $this->metric('missing', 'Funding', '$174 million',
            'Funding | $174 million', '2025', section: 'E001');
        $report = $this->analyze([
            ['record' => $untied, 'diagnosis' => $this->diagnosis('ambiguous', 'period_not_in_quote')],
            ['record' => $notPreserved, 'diagnosis' => $this->diagnosis('ambiguous', 'period_not_in_quote')],
        ], $spans, $text);
        self::assertSame('context_elsewhere_untied', $report['ambiguous']['records'][0]['classification']);
        self::assertSame('context_not_preserved', $report['ambiguous']['records'][1]['classification']);
        self::assertSame([], $report['candidate_rules']);
        self::assertFalse($report['structural_spans']['explicit_table_or_group_id']);
        self::assertFalse($report['structural_spans']['parent_or_header_reference']);
        self::assertGreaterThan(0, $report['structural_spans']['local_type_occurrences']['prose']);
        self::assertSame(0, $report['structural_spans']['local_type_occurrences']['section']);
        self::assertSame(0, $report['structural_spans']['local_type_occurrences']['sentence']);
    }

    public function test_incomplete_header_cannot_recover_missing_scale(): void
    {
        [$spans, $text] = $this->spans([
            1 => ['heading', 'Funding section', 1],
            2 => ['table_row', 'Values | USD', 1],
            3 => ['table_row', 'Committee A | 10.0', 1],
            4 => ['table_row', 'Committee B | 12.0', 1],
            5 => ['table_row', 'Belgian Committee | 16.1', 1],
        ]);
        $record = $this->metric('header', 'Committee funding', 'USD 16.1 million',
            'Belgian Committee | 16.1', section: 'E001');
        $report = $this->analyze([['record' => $record, 'diagnosis' => $this->diagnosis('ambiguous')]],
            $spans, $text);
        self::assertSame('header_context_present_but_unassociated',
            $report['ambiguous']['records'][0]['classification']);
        self::assertFalse($report['ambiguous']['records'][0]['deterministic_rule']['supported']);
        self::assertSame(0, $report['candidate_rules'][0]['records_recovered']);
        self::assertSame(0, $report['projection']['conservative']['document_origin']);
    }

    public function test_eligible_count_uses_selector_without_its_six_item_display_cap(): void
    {
        $records = [];
        foreach (range(1, 7) as $amount) {
            $records[] = $this->metric((string) $amount, 'Funding '.$amount,
                'USD '.$amount.' million', 'Funding USD '.$amount.' million',
                origin: 'document', typed: ['type' => 'money', 'unit_kind' => 'currency',
                    'currency' => 'USD', 'number' => $amount * 1e6, 'scale' => 1e6,
                    'raw' => 'USD '.$amount.' million']);
        }
        $report = $this->analyze([], records: $records);
        self::assertSame(7, $report['projection']['current']['key_figure_eligible_count']);
        self::assertCount(6, $report['projection']['current']['selected_source_ids_capped_at_six']);
    }
}
