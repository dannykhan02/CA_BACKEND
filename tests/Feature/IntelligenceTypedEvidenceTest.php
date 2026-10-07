<?php

namespace Tests\Feature;

use App\Models\DocumentEvidence;
use App\Services\Intelligence\Values\TypedEvidenceProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceTypedEvidenceTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_existing_metric_fixture_projects_typed_value_without_rewriting_stored_evidence(): void
    {
        $document = $this->intelligenceDocument();
        $kpi = $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', '2024',
            ['metric_type' => 'actual']);
        $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->firstOrFail();

        $projected = app(TypedEvidenceProjector::class)->project($row);

        self::assertSame('12.4', $projected['typed']['value']['raw']);
        self::assertSame(12.4e9, $projected['typed']['value']['number']);
        self::assertSame(1e9, $projected['typed']['value']['scale']);
        self::assertSame('actual', $projected['typed']['value']['measure_status']);
        self::assertNull($row->fresh()->data['typed'] ?? null);
    }
}
