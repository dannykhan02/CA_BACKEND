<?php

namespace Tests\Unit;

use App\Models\DocumentEvidence;
use App\Services\Intelligence\ProvenanceProjector;
use App\Services\Intelligence\Values\TypedEvidenceProjector;
use Tests\TestCase;

class IntelligenceProvenanceTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function row(array $overrides = []): DocumentEvidence
    {
        $row = new DocumentEvidence;
        $row->data = array_replace([
            'kind' => 'fact', 'label' => 'Annual lending', 'value' => '$12.4 billion',
            'subject' => '', 'quote' => 'Annual lending was $12.4 billion in FY2024.',
            'reference' => '', 'confidence' => 0.9, 'aliases' => [], 'entity_type' => null,
            'unit' => null, 'period' => 'FY2024', 'date_type' => null, 'due_date' => null,
            'severity' => null, 'metric_type' => null, 'value_basis' => null,
            'aggregation' => null, 'quantity_kind' => null,
        ], $overrides);

        return $row;
    }

    public function test_nonempty_normalized_value_and_period_establish_document_origin(): void
    {
        $projector = app(ProvenanceProjector::class);
        $provenance = $projector->project($this->row());

        self::assertSame('document', $provenance['origin']);
        self::assertSame('stated', $provenance['assertion']);
        self::assertSame('explicit', $projector->legacyBasis($provenance));
    }

    public function test_empty_or_unmatched_value_never_establishes_document_origin(): void
    {
        $projector = app(ProvenanceProjector::class);
        foreach (['', '  ', 'approximately $12.4 billion'] as $value) {
            $provenance = $projector->project($this->row(['value' => $value]));
            self::assertSame('unknown', $provenance['origin']);
            self::assertSame('unspecified', $provenance['assertion']);
            self::assertSame('unattributed', $provenance['attribution']['role']);
            self::assertSame('inferred', $projector->legacyBasis($provenance));
        }
    }

    public function test_unmatched_period_and_unsupported_explicit_date_keep_origin_unknown(): void
    {
        $projector = app(ProvenanceProjector::class);
        self::assertSame('unknown', $projector->project($this->row(['period' => 'FY2025']))['origin']);
        self::assertSame('unknown', $projector->project($this->row([
            'kind' => 'deadline', 'value' => 'Payment', 'period' => null,
            'quote' => 'Payment was due on 31 March 2025.', 'date_type' => 'explicit',
            'due_date' => '2025-04-01',
        ]))['origin']);
        self::assertSame('document', $projector->project($this->row([
            'kind' => 'deadline', 'value' => 'Payment', 'period' => null,
            'quote' => 'Payment was due on 31 March 2025.', 'date_type' => 'explicit',
            'due_date' => '2025-03-31',
        ]))['origin']);
    }

    public function test_typed_read_projection_adds_provenance_without_mutating_record(): void
    {
        $row = $this->row(['value' => 'unsupported paraphrase']);
        $projected = app(TypedEvidenceProjector::class)->project($row);

        self::assertSame('unknown', $projected['provenance']['origin']);
        self::assertSame('unspecified', $projected['provenance']['assertion']);
        self::assertNull($row->data['provenance'] ?? null);
    }
}
