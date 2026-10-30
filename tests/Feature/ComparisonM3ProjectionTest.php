<?php

namespace Tests\Feature;

use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Services\DocumentComparisonService;
use App\Services\Intelligence\PublishedIntelligenceProjection;
use App\Services\Intelligence\SourceReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsM3PublishedRecords;
use Tests\TestCase;

/** Tier A: authored persisted records, not genuine model output. */
class ComparisonM3ProjectionTest extends TestCase
{
    use BuildsM3PublishedRecords;
    use RefreshDatabase;

    public function test_published_obligation_is_one_source_observation_with_timeline_membership_and_risk_subject_survives(): void
    {
        [$doc, $revision, $deadline, $risk] = $this->documentWithPublishedRecords();
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $obligation = collect($facts)->firstWhere('source.source_id', 'deadline:'.$deadline->id);
        $this->assertSame('obligations', $obligation['category']);
        $this->assertSame(['obligations', 'deadlines'], $obligation['views']);
        $this->assertSame('Buyer', $obligation['context']['responsible_party']);
        $this->assertSame($revision->id, $obligation['source']['intelligence_revision_id']);
        $this->assertCount(1, array_filter($facts, fn ($f) => $f['source']['source_id'] === 'deadline:'.$deadline->id));
        $projectedRisk = collect($facts)->firstWhere('source.source_id', 'risk:'.$risk->id);
        $this->assertSame('Plant A', $projectedRisk['context']['subject']);
        $this->assertSame('operational', $projectedRisk['context']['risk_type']);
        $this->assertSame('high', $projectedRisk['value']['severity']);
        $this->assertSame('ai_extracted_classification', $projectedRisk['origins']['severity']);
        $this->assertSame('unknown', $projectedRisk['source']['location_kind']);
        Http::assertNothingSent();
    }

    public function test_unparseable_evidence_metric_visible_in_studio_is_not_silently_lost_by_matters(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $metric = collect($facts)->firstWhere('source.source_id', 'fact:service');
        $this->assertNotNull($metric);
        $this->assertSame('unavailable', $metric['value']['value']);
        $this->assertFalse($metric['context']['analytical_eligible']);
        $this->assertContains('invalid_numeric_value', $metric['limitations']);
        $studio = $this->getJson('/api/documents/'.$doc->id.'/intelligence')->assertOk();
        $this->assertSame(1, $studio->json('data.publishedIntelligence.counts.kpis'));
        $this->assertSame(1, $studio->json('data.publishedIntelligence.counts.obligations'));
        $this->assertSame('obligation', $studio->json('data.deadlines.0.classification'));
        $this->assertSame(1, $studio->json('data.publishedIntelligence.counts.risks'));
    }

    public function test_workflow_review_state_does_not_change_source_correspondence_binding(): void
    {
        [$doc, , $deadline, $risk] = $this->documentWithPublishedRecords();
        $service = app(DocumentComparisonService::class);
        $first = $service->snapshot($doc);
        $deadline->update(['status' => 'met']);
        $risk->update(['status' => 'closed']);
        $this->assertSame($first, $service->snapshot($doc));
    }

    public function test_refresh_rows_old_pipeline_evidence_and_deleted_targets_cannot_leak_into_projection(): void
    {
        [$doc, , , $risk] = $this->documentWithPublishedRecords();
        $candidate = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
            'generation' => 2, 'state' => 'candidate', 'extracted_text' => 'Unpublished', 'extracted_text_hash' => hash('sha256', 'Unpublished')]);
        $doc->forceFill(['active_intelligence_revision_id' => $candidate->id, 'status' => 'Processing'])->save();
        DocumentRisk::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'intelligence_revision_id' => $candidate->id,
            'title' => 'Hidden new risk', 'description' => 'Hidden', 'evidence' => 'Unpublished', 'severity' => 'critical', 'confidence' => .9, 'prompt_version' => 'test']);
        DocumentEvidence::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'pipeline_key' => 'old-pipeline', 'identity' => hash('sha256', 'old'), 'kind' => 'metric', 'source_id' => 'fact:old', 'data' => ['label' => 'Old', 'value' => '90'], 'sources' => []]);
        $risk->delete();
        $raw = json_encode(app(DocumentComparisonService::class)->snapshot($doc));
        $this->assertStringNotContainsString('Hidden new risk', $raw);
        $this->assertStringNotContainsString('fact:old', $raw);
        $this->assertStringNotContainsString('Supply interruption exposure', $raw);
    }

    public function test_stale_source_hash_cannot_support_current_fields_even_when_words_repeat(): void
    {
        [$doc, , $deadline] = $this->documentWithPublishedRecords();
        $evidence = DocumentEvidence::where('source_id', 'deadline:'.$deadline->id)->firstOrFail();
        $evidence->update(['data' => $evidence->data + ['source_revision' => hash('sha256', 'older different source')]]);
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $fact = collect($facts)->firstWhere('source.source_id', 'deadline:'.$deadline->id);
        $this->assertEmpty($fact['source']['spans']);
        $this->assertNull($fact['context']['responsible_party']);
    }

    public function test_unknown_source_id_is_not_dropped_and_does_not_invent_a_typed_target(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        $evidence = DocumentEvidence::where('source_id', 'fact:service')->firstOrFail();
        $evidence->update(['source_id' => null]);
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $metric = collect($facts)->firstWhere('source.source_id', 'evidence:'.$evidence->id);
        $this->assertNotNull($metric);
        $this->assertSame('evidence', $metric['source']['record_type']);
        $this->getJson('/api/documents/'.$doc->id.'/sources/'.urlencode('evidence:'.$evidence->id))->assertOk()->assertJsonPath('data.state', 'CURRENT');
    }

    public function test_projection_queries_do_not_grow_with_more_same_document_findings(): void
    {
        [$doc, $revision] = $this->documentWithPublishedRecords();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        app(DocumentComparisonService::class)->snapshot($doc->fresh());
        $first = count($queries);
        foreach (range(1, 20) as $i) {
            DocumentRisk::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'intelligence_revision_id' => $revision->id,
                'title' => 'Risk '.$i, 'description' => 'Authored risk '.$i, 'evidence' => 'Plant A', 'severity' => 'low', 'confidence' => .8, 'prompt_version' => 'test']);
        }
        $queries = [];
        app(DocumentComparisonService::class)->snapshot($doc->fresh());
        $this->assertSame($first, count($queries));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "document_evidence"')));
    }

    public function test_deadline_only_and_risk_without_obligations_keep_category_boundaries(): void
    {
        [$doc, , $deadline, $risk, $entity] = $this->documentWithPublishedRecords();
        DocumentEvidence::where('kind', 'metric')->delete();
        $deadline->delete();
        $entity->delete();
        $read = app(PublishedIntelligenceProjection::class)->read($doc);
        $this->assertSame(0, $read['coverage']['counts']['kpis']);
        $this->assertSame(0, $read['coverage']['counts']['obligations']);
        $this->assertSame(1, $read['coverage']['counts']['risks']);
        $risk->delete();
        $deadline = DocumentDeadline::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
            'intelligence_revision_id' => $doc->published_intelligence_revision_id, 'title' => 'Payment mentioned on a historical date',
            'deadline_type' => 'date', 'description' => 'A dated occurrence, no required action', 'date_type' => 'explicit',
            'due_date' => '2026-01-01', 'evidence' => 'A date', 'confidence' => .9, 'prompt_version' => 'test']);
        $read = app(PublishedIntelligenceProjection::class)->read($doc);
        $this->assertSame(1, $read['coverage']['counts']['deadlines']);
        $this->assertSame(0, $read['coverage']['counts']['obligations']);
        $this->assertSame('dated event', array_values($read['facts'])[0][0]['context']['classification']);
    }

    public function test_unpublished_first_analysis_is_empty_and_published_partial_coverage_is_qualified(): void
    {
        [$doc, $revision] = $this->documentWithPublishedRecords(partial: true);
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        foreach ($facts as $fact) {
            $this->assertContains('partial_analysis', $fact['limitations']);
        }
        $doc->forceFill(['published_intelligence_revision_id' => null, 'active_intelligence_revision_id' => $revision->id, 'status' => 'Processing'])->save();
        $this->assertSame([], app(DocumentComparisonService::class)->snapshot($doc));
    }

    public function test_duplicate_evidence_for_one_source_does_not_duplicate_the_observation_or_assert_compatibility(): void
    {
        [$doc, , , $risk] = $this->documentWithPublishedRecords();
        $evidence = DocumentEvidence::where('source_id', 'risk:'.$risk->id)->firstOrFail();
        $copy = $evidence->replicate();
        $copy->identity = hash('sha256', 'duplicate-risk-source');
        $copy->save();
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $risks = array_values(array_filter($facts, fn ($row) => $row['category'] === 'risks'));
        $this->assertCount(1, $risks);
        $this->assertTrue($risks[0]['context']['ambiguous_source_context']);
        $this->assertContains('multiple_evidence_records_for_source', $risks[0]['limitations']);
    }

    public function test_deleted_kpi_target_does_not_survive_as_a_metric_in_studio_or_matters(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        $kpi = DocumentKpi::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
            'intelligence_revision_id' => $doc->published_intelligence_revision_id, 'label' => 'Deleted service level', 'value' => 'unavailable']);
        $entry = DocumentEvidence::where('source_id', 'fact:service')->firstOrFail()->replicate();
        $entry->source_id = 'kpi:'.$kpi->id;
        $entry->identity = hash('sha256', $entry->source_id);
        $entry->save();
        $kpi->delete();
        $read = app(PublishedIntelligenceProjection::class)->read($doc);
        $this->assertSame(1, $read['metrics']['stats']['metric_findings']);
        $this->assertSame(1, $read['coverage']['excluded']['missing_revision_bound_source_target']);
        $this->assertSame(1, $read['coverage']['counts']['kpis']);
    }

    public function test_legacy_nullable_workspace_kpi_is_retained_without_claiming_a_published_revision(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        $doc->forceFill(['published_intelligence_revision_id' => null, 'intelligence_generation' => 0])->save();
        DocumentKpi::create(['workspace_id' => null, 'document_id' => $doc->id,
            'intelligence_revision_id' => null, 'label' => 'Legacy revenue', 'value' => '12', 'unit' => 'KES', 'period' => '2026']);
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $legacy = collect($facts)->firstWhere('label', 'Legacy revenue');
        $this->assertNotNull($legacy);
        $this->assertNull($legacy['source']['intelligence_revision_id']);
        $this->assertFalse($legacy['context']['analytical_eligible']);
    }

    public function test_shared_evidence_preload_resolves_the_same_source_without_a_second_evidence_fetch(): void
    {
        [$doc, , , $risk] = $this->documentWithPublishedRecords();
        $read = app(PublishedIntelligenceProjection::class)->read($doc);
        $resolver = app(SourceReferenceResolver::class);
        $sourceId = 'risk:'.$risk->id;
        $expected = $resolver->resolve($read['document'], $sourceId);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $actual = $resolver->resolve($read['document'], $sourceId, $read['evidenceRecords']->where('source_id', $sourceId));
        $this->assertSame($expected, $actual);
        $this->assertSame('CURRENT', $actual['state']);
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'from "document_evidence"')));
    }

    public function test_structured_measurements_survive_the_snapshot_json_boundary_without_type_drift(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        DocumentKpi::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
            'intelligence_revision_id' => $doc->published_intelligence_revision_id, 'label' => 'Stable magnitude', 'value' => '12.0', 'unit' => 'KES', 'period' => '2026']);
        $facts = array_merge(...array_values(app(DocumentComparisonService::class)->snapshot($doc)));
        $fact = collect($facts)->firstWhere('label', 'Stable magnitude');
        $this->assertSame($fact, json_decode(json_encode($fact, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }
}
