<?php

namespace Tests\Feature;

use App\Models\DocumentEntity;
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

    public function test_entity_reference_requires_matching_entity_in_same_document_and_workspace(): void
    {
        // Added to verify that a persisted, scoped entity record is required before emitting an entity ID.
        $document = $this->intelligenceDocument();
        $otherDocument = $this->intelligenceDocument('Other report.pdf');
        $kpi = $this->metricFinding($document, 'Agency lending', '12.4', 'USD billion', '2024',
            ['subject' => 'Agency', 'quote' => 'Agency lending: 12.4']);
        $row = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->firstOrFail();

        $otherEntity = DocumentEntity::create([
            'workspace_id' => $otherDocument->workspace_id,
            'document_id' => $otherDocument->id,
            'entity_type' => 'organization',
            'value' => 'Agency',
            'normalized_value' => 'agency',
            'confidence' => 0.9,
            'prompt_version' => 'test',
        ]);
        self::assertNull(app(TypedEvidenceProjector::class)->project($row)['typed']['value']['entity_ref']['id']);

        $entity = DocumentEntity::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'entity_type' => 'organization',
            'value' => 'Agency',
            'normalized_value' => 'agency',
            'confidence' => 0.9,
            'prompt_version' => 'test',
        ]);
        self::assertSame('entity:'.$entity->id,
            app(TypedEvidenceProjector::class)->project($row)['typed']['value']['entity_ref']['id']);
        self::assertNotSame('entity:'.$otherEntity->id,
            app(TypedEvidenceProjector::class)->project($row)['typed']['value']['entity_ref']['id']);
    }
}
