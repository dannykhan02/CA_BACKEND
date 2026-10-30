<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tier 2: real bytes, deterministic supported extraction/proof, no fresh model execution. */
class ComparisonM2B1RealContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_public_financial_content_reaches_snapshot_without_invented_extraction_rows(): void
    {
        config(['document_intelligence.pdf_table_structure' => true, 'document_intelligence.revision_v1' => false]);
        Http::preventStrayRequests();
        Bus::fake();
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $snapshots = [];
        $documents = [];
        $extractions = [];
        $docs = [];
        foreach (['apple_fy25_q2_original.pdf', 'ComparisonM2B1/sources/apple-fy26-q2.pdf', 'ComparisonM2B1/sources/apple-fy24-q2.pdf'] as $index => $file) {
            $path = base_path('tests/Fixtures/'.$file);
            $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => $file, 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Public',
                'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026, 'pages' => 3, 'file_hash' => hash_file('sha256', $path)]);
            $pipeline = ['route' => 'incremental', 'key' => 'incremental:real-m2b1-'.$index];
            $doc->forceFill(['ai_pipeline' => $pipeline])->save();
            app(StructuredExtractionStore::class)->publish($doc, $source);
            $doc->refresh();
            $collected = app(PdfSourceCandidateCollector::class)->collect($doc);
            $set = app(TextRoleProvenanceProducer::class)->publish($doc);
            $verified = app(IncrementalMetricFieldVerifier::class)->verify($doc, $set);
            $rejections = DocumentEvidence::where('document_id', $doc->id)->where('pipeline_key', $pipeline['key'])
                ->where('kind', 'metric')->get()->groupBy(fn ($row) => $row->data['grounding_rejection'] ?? 'accepted')
                ->map(fn ($rows) => $rows->count())->all();
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'generation' => 1, 'state' => 'published', 'extracted_text' => $source->text, 'extracted_text_hash' => hash('sha256', $source->text),
                'ai_pipeline' => $pipeline, 'provenance_set_id' => $set]);
            $doc->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 1, 'status' => 'Ready'])->save();
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                    'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
            }
            $docs[] = $doc->fresh();
            $snapshot = app(DocumentComparisonService::class)->snapshot($doc->fresh());
            $facts = array_merge(...array_values($snapshot));
            $eligible = array_values(array_filter($facts, fn ($f) => $f['context']['analytical_eligible']));
            // Coverage is measured, not assumed for every source.
            foreach ($eligible as $fact) {
                $this->assertNotEmpty($fact['source']['evidence_ids']);
                $this->assertNotEmpty($fact['source']['spans']);
                $this->assertNull($fact['context']['owner']);
            }
            // Independent source review: these values are printed in the supplied PDFs.
            $target = $index === 2 ? '27,900' : '29,589';
            $this->assertNotEmpty(array_filter($facts, fn ($f) => $f['label'] === 'Operating income' && $f['value']['value'] === $target));
            $snapshots[] = $snapshot;
            $extractions[] = ['file' => $file, 'file_sha256' => $doc->file_hash,
                'extracted_text_sha256' => hash('sha256', $source->text), 'parser_version' => $source->parserVersion,
                'facts' => array_map(fn ($fact) => ['source_record_id' => $fact['source']['source_id'],
                    'label' => $fact['label'], 'value' => $fact['value'], 'context' => $fact['context'],
                    'parsed_measurement' => $this->parsedMeasurement($fact['value']),
                    'source_locations' => array_map(fn ($span) => ['start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
                        'page' => $span['page'] ?? null, 'quote_sha256' => hash('sha256', $span['quote'])], $fact['source']['spans'])], $facts)];
            $documents[] = ['file' => $file, 'sha256' => $doc->file_hash, 'parser_version' => $source->parserVersion,
                'collected' => $collected, 'field_verification' => $verified, 'field_rejections' => $rejections,
                'snapshot_facts' => count($facts), 'eligible_facts' => count($eligible),
                'eligible_evidence_coverage' => count(array_filter($eligible, fn ($f) => ! empty($f['source']['spans']) && ! empty($f['source']['evidence_ids']))),
                'reporting_owners' => array_values(array_unique(array_column(array_column($eligible, 'context'), 'reported_owner')))];
        }
        $identity = app(IdentityCandidates::class)->build($snapshots[0], $snapshots[1]);
        $changes = app(DocumentComparisonService::class)->changes($snapshots[0], $snapshots[1], $identity);
        $selected = collect($identity['candidates'])->first(fn ($c) => $c['a']['label'] === 'Operating income'
            && $c['b']['label'] === 'Operating income'
            && $c['a']['value']['period'] === 'Three Months Ended March 29, 2025'
            && $c['b']['value']['period'] === 'Three Months Ended March 29, 2025');
        $this->assertNotNull($selected);
        $comparison = app(DocumentComparisonService::class)->create($user, $docs[0]->id, $docs[1]->id);
        $this->actingAs($user, 'sanctum')->patchJson('/api/document-comparisons/'.$comparison->id.'/identity',
            ['key' => $selected['key'], 'state' => 'confirmed', 'version' => 0,
                'reason' => 'Inspected both original Apple statements: same issuer and the same operating-income quarter ending March 29, 2025.'])->assertOk();
        $reviewed = app(ComparisonIdentityService::class)->context($user, $docs[0]->id, $docs[1]->id, $snapshots[0], $snapshots[1]);
        $reviewedChanges = app(DocumentComparisonService::class)->changes($snapshots[0], $snapshots[1], $reviewed);
        $selectedRow = collect($reviewedChanges)->first(fn ($row) => count($row['before']) === 1
            && $row['before'][0]['source']['source_id'] === $selected['a']['source']['source_id']);
        $this->assertSame('insufficient_evidence', $selectedRow['outcome']);
        $this->assertSame(['unknown_accounting_basis'], $selectedRow['reasons']);
        $this->assertSame('consolidated', $selectedRow['before'][0]['context']['reporting_scope']);
        $this->assertSame('total', $selectedRow['before'][0]['context']['aggregation']);
        $this->assertSame('flow', $selectedRow['before'][0]['context']['measurement_nature']);
        $this->assertEmpty(array_filter($reviewedChanges, fn ($row) => in_array($row['outcome'], ['supported_change', 'period_comparison', 'potential_conflict'])));
        // Execute and reload the actual stored deterministic job, still Tier 2 publication scaffolding.
        $saved = app(DocumentComparisonService::class)->create($user, $docs[0]->id, $docs[1]->id);
        (new CompareDocumentsJob($saved->id, $saved->dispatch_token))->handle(app(DocumentComparisonService::class));
        $saved->refresh();
        $this->assertSame('completed', $saved->status);
        $this->assertSame($reviewedChanges, $saved->changes);
        $this->getJson('/api/document-comparisons/'.$saved->id)->assertOk()->assertJsonPath('data.revision_status', 'current');
        $pinned = $saved->metadata;
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $docs[0]->workspace_id, 'document_id' => $docs[0]->id,
            'generation' => 2, 'state' => 'published', 'extracted_text' => 'Isolated revision-freshness simulation',
            'extracted_text_hash' => hash('sha256', 'Isolated revision-freshness simulation')]);
        $docs[0]->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        $this->getJson('/api/document-comparisons/'.$saved->id)->assertOk()->assertJsonPath('data.revision_status', 'outdated');
        $this->assertSame($pinned, $saved->fresh()->metadata);
        $this->assertSame($reviewedChanges, $saved->fresh()->changes);
        $this->assertNull($saved->provider_started_at);
        $report = ['tier' => 2, 'method' => 'Original PDFs through existing deterministic extraction/proof; local publication pointers; no model calls',
            'documents' => $documents, 'identity_truncated' => $identity['truncated'],
            'candidate_count' => count($identity['candidates']), 'selected_candidate' => $selected,
            'unreviewed_outcomes' => array_count_values(array_column($changes, 'outcome')),
            'unreviewed_changes' => $changes,
            'reviewed_changes' => $reviewedChanges, 'reviewed_outcomes' => array_count_values(array_column($reviewedChanges, 'outcome'))];
        Http::assertNothingSent();
        $phase = 'current'; // Baseline artifacts are frozen; this test never rewrites them.
        file_put_contents(base_path('tests/Fixtures/ComparisonM3/pdf-'.$phase.'-real.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents(base_path('tests/Fixtures/ComparisonM3/pdf-'.$phase.'-extraction.json'), json_encode($extractions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function parsedMeasurement(array $value): ?array
    {
        $measurement = app(MeasurementParser::class)->parse($value['value'], $value['unit']);

        return $measurement === null ? null : ['exact_value' => $measurement->exactMagnitude,
            'currency' => $measurement->currency, 'scale' => $measurement->scale, 'unit' => $measurement->canonicalUnit,
            'kind' => $measurement->kind, 'family' => $measurement->family];
    }
}
