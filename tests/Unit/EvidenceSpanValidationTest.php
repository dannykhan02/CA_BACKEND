<?php

namespace Tests\Unit;

use App\Exceptions\AiProcessingException;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\EvidenceSpanSet;
use App\Services\AI\Incremental\SourceSpanBuilder;
use Tests\TestCase;

class EvidenceSpanValidationTest extends TestCase
{
    private const SOURCE = "CONFIDENTIAL BOARD PAPER\n\n"
        ."The Bank approved thirty-seven sovereign operations during the 2024 financial year.\n\n"
        ."Total commitments reached $12.4 billion, compared with $10.1 billion in 2023.\n\n"
        ."Private-sector financing increased substantially against a demanding external backdrop.\n\n"
        ."Repayment of the senior facility is due on 2025-03-31 under the amended agreement.\n\n"
        ."A further unrelated observation about procurement appears much later in the paper.\n\n"
        ."Yet another distant paragraph discusses the staff retirement plan in some detail.\n\n"
        .'And a final distant paragraph closes the annexes with administrative remarks here.';

    private string $version = 'abc123';

    private function spans(): EvidenceSpanSet
    {
        return new EvidenceSpanSet($this->version, self::SOURCE,
            app(SourceSpanBuilder::class)->build(self::SOURCE, 7));
    }

    private function record(array $changes = []): array
    {
        return array_replace(['kind' => 'metric', 'label' => 'Total commitments', 'value' => '$12.4 billion',
            'subject' => 'The Bank', 'reference' => '', 'entity_type' => null, 'unit' => 'USD',
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'period' => '2024', 'date_type' => null, 'due_date' => null, 'severity' => null,
            'confidence' => 0.95, 'aliases' => [], 'evidence_ids' => ['E003']], $changes);
    }

    private function validate(array $records, ?EvidenceSpanSet $spans = null, ?string $expected = null): array
    {
        return EvidenceSchema::validate(['records' => $records], self::SOURCE,
            $spans ?? $this->spans(), $expected ?? $this->version);
    }

    /** @return array{0:string,1:string} classification and reason of the single rejection */
    private function rejection(array $record, ?EvidenceSpanSet $spans = null, ?string $expected = null): array
    {
        try {
            $this->validate([$record], $spans, $expected);
            self::fail('Expected the record to be rejected.');
        } catch (AiProcessingException $e) {
            return [$e->classification, array_key_first($e->diagnostics['rejection_reasons'] ?? [])];
        }
    }

    public function test_span_mode_schema_asks_for_ids_and_never_for_a_quote(): void
    {
        $fields = EvidenceSchema::extraction(EvidenceGrounding::SPANS)['properties']['records']['items']['properties'];

        self::assertArrayHasKey('evidence_ids', $fields);
        self::assertArrayNotHasKey('quote', $fields);
        self::assertSame(1, $fields['evidence_ids']['minItems']);
        self::assertSame(EvidenceSchema::maxEvidenceIds(), $fields['evidence_ids']['maxItems']);

        $legacy = EvidenceSchema::extraction(EvidenceGrounding::LEGACY)['properties']['records']['items']['properties'];
        self::assertArrayHasKey('quote', $legacy);
        self::assertArrayNotHasKey('evidence_ids', $legacy);
    }

    public function test_span_mode_instructions_forbid_quoting_and_inventing_ids(): void
    {
        $instructions = EvidenceSchema::instructions(EvidenceGrounding::SPANS);

        self::assertStringContainsString('evidence_ids', $instructions);
        self::assertStringContainsString('Never invent, guess, renumber or extrapolate an identifier', $instructions);
        self::assertStringContainsString('the application retrieves the exact source text', $instructions);
        // Task 20: the date contract is stated explicitly, consistent with the existing validator.
        self::assertStringContainsString('complete calendar date in YYYY-MM-DD', $instructions);
        self::assertStringContainsString('FY2025', $instructions);
    }

    public function test_a_valid_id_is_accepted_and_docintel_resolves_the_exact_source_text(): void
    {
        $result = $this->validate([$this->record()]);
        $record = $result['records'][0];

        self::assertSame(['E003'], $record['evidence_ids']);
        self::assertCount(1, $record['evidence']);
        self::assertSame('E003', $record['evidence'][0]['span_id']);
        self::assertSame('Total commitments reached $12.4 billion, compared with $10.1 billion in 2023.',
            $record['evidence'][0]['text']);
        // The resolved text is literally the document's own characters at those offsets.
        self::assertSame($record['evidence'][0]['text'], mb_substr(self::SOURCE,
            $record['evidence'][0]['start_offset'],
            $record['evidence'][0]['end_offset'] - $record['evidence'][0]['start_offset']));
        self::assertIsInt($record['evidence'][0]['page']);
        self::assertSame(EvidenceGrounding::SPANS, $result['_validation']['evidence_grounding_mode']);
    }

    public function test_the_compatibility_quote_is_derived_by_docintel_and_never_taken_from_the_model(): void
    {
        // The model sends a wrong quote alongside valid IDs. It is ignored: the stored quote is
        // the resolved span text, not what the model wrote.
        $record = $this->validate([$this->record(['quote' => 'A QUOTE THE MODEL MADE UP'])])['records'][0];

        self::assertStringNotContainsString('MADE UP', $record['quote']);
        self::assertSame($record['evidence'][0]['text'], $record['quote']);
        self::assertStringContainsString($record['quote'], self::SOURCE);
    }

    public function test_multiple_valid_ids_are_kept_separately_in_source_order(): void
    {
        $record = $this->validate([$this->record(['evidence_ids' => ['E004', 'E003']])])['records'][0];

        self::assertSame(['E003', 'E004'], $record['evidence_ids']);
        self::assertSame(['E003', 'E004'], array_column($record['evidence'], 'span_id'));
        // Combined only for the compatibility representation; internally the spans stay separate.
        self::assertSame(implode("\n\n", array_column($record['evidence'], 'text')), $record['quote']);
    }

    public function test_duplicate_and_differently_cased_ids_are_normalised_rather_than_rejected(): void
    {
        $record = $this->validate([$this->record(['evidence_ids' => ['E003', 'e003', ' E003 ']])])['records'][0];

        self::assertSame(['E003'], $record['evidence_ids']);
        self::assertCount(1, $record['evidence']);
    }

    public function test_an_unknown_id_is_rejected(): void
    {
        self::assertSame(['invalid_evidence', 'unknown_evidence_id'],
            $this->rejection($this->record(['evidence_ids' => ['E999']])));
    }

    public function test_an_invented_id_shape_is_rejected(): void
    {
        foreach ([['QUOTE-1'], ['E'], ['42'], ['E003a'], ['']] as $ids) {
            [$class, $reason] = $this->rejection($this->record(['evidence_ids' => $ids]));
            self::assertSame('invalid_evidence', $class);
            self::assertContains($reason, ['unknown_evidence_id', 'missing_evidence_ids']);
        }
    }

    public function test_an_id_from_another_chunk_is_rejected_even_though_it_exists(): void
    {
        $spans = $this->spans();
        // The chunk the model was given covers only the first three spans.
        $chunk = $spans->forRange(0, $spans->all()[2]['end_offset']);

        self::assertTrue($chunk->knownToDocument('E005'));
        self::assertFalse($chunk->has('E005'));
        self::assertSame(['invalid_evidence', 'evidence_id_outside_chunk'],
            $this->rejection($this->record(['evidence_ids' => ['E005']]), $chunk));
        // An ID inside the chunk still works.
        self::assertCount(1, $this->validate([$this->record(['evidence_ids' => ['E003']])], $chunk)['records']);
    }

    public function test_ids_from_a_different_extraction_version_are_rejected(): void
    {
        self::assertSame(['invalid_evidence', 'evidence_id_wrong_extraction_version'],
            $this->rejection($this->record(), null, 'a-different-extraction-version'));
    }

    public function test_more_ids_than_the_configured_maximum_are_rejected(): void
    {
        config(['document_intelligence.max_evidence_ids' => 2]);

        self::assertSame(['invalid_evidence', 'too_many_evidence_ids'],
            $this->rejection($this->record(['evidence_ids' => ['E002', 'E003', 'E004']])));
        self::assertCount(1, $this->validate([$this->record(['evidence_ids' => ['E002', 'E003']])])['records']);
    }

    public function test_missing_or_wrongly_typed_ids_are_rejected_with_their_own_reasons(): void
    {
        $missing = $this->record();
        unset($missing['evidence_ids']);
        self::assertSame(['invalid_evidence', 'missing_evidence_ids'], $this->rejection($missing));
        self::assertSame(['invalid_evidence', 'missing_evidence_ids'],
            $this->rejection($this->record(['evidence_ids' => []])));

        foreach (['E003', 3, ['E003' => true], [['E003']], [3]] as $wrong) {
            self::assertSame(['invalid_evidence', 'evidence_ids_wrong_type'],
                $this->rejection($this->record(['evidence_ids' => $wrong])));
        }
    }

    public function test_distant_span_combinations_are_rejected_when_locality_is_enabled(): void
    {
        config(['document_intelligence.evidence_span_locality' => 2]);
        self::assertSame(['invalid_evidence', 'invalid_evidence_span_combination'],
            $this->rejection($this->record(['evidence_ids' => ['E002', 'E008']])));
        // Neighbouring spans, such as a table row label and its values, remain allowed.
        self::assertCount(1, $this->validate([$this->record(['evidence_ids' => ['E002', 'E003']])])['records']);

        config(['document_intelligence.evidence_span_locality' => 0]);
        self::assertCount(1, $this->validate([$this->record(['evidence_ids' => ['E002', 'E008']])])['records']);
    }

    public function test_schema_confidence_and_date_rules_are_all_still_enforced_in_span_mode(): void
    {
        $missing = $this->record();
        unset($missing['value']);
        $cases = [
            [$missing, 'invalid_schema', 'missing_required_field'],
            [$this->record(['confidence' => 'high']), 'invalid_schema', 'wrong_field_type'],
            [$this->record(['kind' => 'unknown']), 'invalid_schema', 'invalid_kind'],
            [$this->record(['confidence' => 1.5]), 'invalid_evidence', 'confidence_out_of_range'],
            [$this->record(['label' => ' ']), 'invalid_evidence', 'blank_label'],
            [$this->record(['date_type' => 'explicit']), 'invalid_date', 'explicit_date_missing_due_date'],
            [$this->record(['date_type' => 'explicit', 'due_date' => '2024/02/01']), 'invalid_date', 'due_date_wrong_format'],
            [$this->record(['date_type' => 'explicit', 'due_date' => '2024-02-30']), 'invalid_date', 'due_date_invalid_calendar_date'],
            [$this->record(['date_type' => 'relative', 'due_date' => '2024-02-01']), 'invalid_date', 'due_date_present_for_non_explicit_type'],
            [$this->record(['kind' => 'deadline']), 'invalid_date', 'invalid_deadline_date_type'],
        ];
        foreach ($cases as [$record, $class, $reason]) {
            self::assertSame([$class, $reason], $this->rejection($record), $reason.' was not enforced');
        }
    }

    public function test_a_wrong_quote_alone_no_longer_rejects_anything_in_span_mode(): void
    {
        // The whole point of the refactor: formatting of reproduced text cannot fail grounding.
        $result = $this->validate([
            $this->record(['quote' => "Total  commitments\u{00A0}reached $12.4 billion"]),
            $this->record(['evidence_ids' => ['E002']]),
        ]);

        self::assertSame(2, $result['_validation']['records_kept']);
        self::assertSame([], $result['_validation']['rejections']);
        self::assertArrayNotHasKey('quote_not_found_in_source', $result['_validation']['rejection_reasons']);
    }

    public function test_diagnostics_reconcile_and_never_contain_source_text(): void
    {
        $invalid = [
            $this->record(['evidence_ids' => ['E999']]),
            $this->record(['evidence_ids' => ['SECRET-ID-FROM-MODEL']]),
            $this->record(['evidence_ids' => 'E003']),
            $this->record(['date_type' => 'explicit']),
        ];
        $result = $this->validate([$this->record(), ...$invalid]);
        $diagnostics = $result['_validation'];

        self::assertSame(5, $diagnostics['records_returned']);
        self::assertSame(1, $diagnostics['records_kept']);
        self::assertSame(4, $diagnostics['records_dropped']);
        self::assertSame($diagnostics['records_returned'], $diagnostics['records_kept'] + $diagnostics['records_dropped']);
        self::assertSame(2, $diagnostics['rejection_reasons']['unknown_evidence_id']);
        self::assertSame(1, $diagnostics['rejection_reasons']['evidence_ids_wrong_type']);
        self::assertSame(1, $diagnostics['rejection_reasons']['explicit_date_missing_due_date']);
        self::assertSame(3, $diagnostics['rejections']['invalid_evidence']);
        self::assertSame(1, $diagnostics['rejections']['invalid_date']);
        self::assertSame(array_sum($diagnostics['rejections']), array_sum($diagnostics['rejection_reasons']));

        $encoded = json_encode($diagnostics);
        self::assertStringNotContainsString('CONFIDENTIAL', $encoded);
        self::assertStringNotContainsString('12.4', $encoded);
        self::assertStringNotContainsString('SECRET-ID-FROM-MODEL', $encoded);
    }

    public function test_an_all_invalid_span_response_fails_the_chunk_with_full_diagnostics(): void
    {
        try {
            $this->validate([$this->record(['evidence_ids' => ['E999']]), $this->record(['evidence_ids' => ['E998']])]);
            self::fail('Expected an all-invalid response to fail.');
        } catch (AiProcessingException $e) {
            self::assertSame('invalid_evidence', $e->classification);
            self::assertSame(2, $e->diagnostics['records_returned']);
            self::assertSame(0, $e->diagnostics['records_kept']);
            self::assertSame(2, $e->diagnostics['rejection_reasons']['unknown_evidence_id']);
            self::assertSame(EvidenceGrounding::SPANS, $e->diagnostics['evidence_grounding_mode']);
        }
    }

    public function test_an_empty_span_response_is_still_a_valid_nothing_found(): void
    {
        $result = $this->validate([]);

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['_validation']['records_returned']);
        self::assertSame(EvidenceGrounding::SPANS, $result['_validation']['evidence_grounding_mode']);
    }

    public function test_legacy_quote_validation_is_untouched_when_no_spans_are_supplied(): void
    {
        $legacy = ['kind' => 'fact', 'label' => 'Commitments', 'value' => 'grew', 'subject' => '',
            'quote' => 'The Bank approved thirty-seven sovereign operations during the 2024 financial year.',
            'reference' => '', 'entity_type' => null, 'unit' => null, 'metric_type' => null,
            'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null, 'period' => null,
            'date_type' => null, 'due_date' => null, 'severity' => null, 'confidence' => 0.9, 'aliases' => []];

        $result = EvidenceSchema::validate(['records' => [$legacy]], self::SOURCE);
        self::assertSame(1, $result['_validation']['records_kept']);
        self::assertSame(EvidenceGrounding::LEGACY, $result['_validation']['evidence_grounding_mode']);

        try {
            EvidenceSchema::validate(['records' => [['...' => '', ...$legacy, 'quote' => 'Not in the source at all.']]], self::SOURCE);
            self::fail('Expected a quote mismatch to fail in legacy mode.');
        } catch (AiProcessingException $e) {
            self::assertSame(1, $e->diagnostics['rejection_reasons']['quote_not_found_in_source']);
        }
    }
}
