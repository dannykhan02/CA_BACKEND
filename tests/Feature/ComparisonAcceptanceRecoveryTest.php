<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceDateRecognizer;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\PdfCategoricalCandidateCollector;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIdentityPair;
use Tests\TestCase;

/** Tier 2 diagnostic. No authored obligations, provider responses or Tier 3 claim. */
class ComparisonAcceptanceRecoveryTest extends TestCase
{
    use BuildsIdentityPair;
    use RefreshDatabase;

    /** Synthetic state boundary, separate from the real-byte diagnostic below. */
    public function test_missing_offsets_prevent_provision_review_in_the_production_projection(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $row = DocumentEvidence::where('document_id', $a->id)->sole();
        $sources = $row->sources;
        unset($sources[0]['start_offset'], $sources[0]['end_offset']);
        $row->update(['sources' => $sources]);
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $candidate = $this->getJson($path)->assertOk()->json('data.candidates.0');
        $this->assertFalse($candidate['reviewable']);
        $this->patchJson($path, ['key' => $candidate['key'], 'state' => 'confirmed', 'version' => 0,
            'reason' => 'Attempt to confirm without source offsets.'])->assertStatus(409);
        $this->assertEmpty(array_filter(app(DocumentComparisonService::class)->changes(
            app(DocumentComparisonService::class)->snapshot($a), app(DocumentComparisonService::class)->snapshot($b)),
            fn ($r) => $r['outcome'] === 'supported_change'));
    }

    public function test_original_contract_bytes_expose_the_missing_structured_extraction_gate(): void
    {
        config(['document_intelligence.pdf_table_structure' => true,
            'document_intelligence.pdf_basic_table_structure' => true,
            'document_intelligence.revision_v1' => false]);
        Bus::fake();
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $this->actingAs($user, 'sanctum');
        $docs = $snapshots = $reports = [];
        $hashes = ['1936-cooper-eagle-agmt.pdf' => 'e582a25b8356403a974a21b561c034f261623fa47eb2a393c0f9d4f6fed11bbc',
            '1936-cooper-eagle-amendagmt1.pdf' => '2df9f12ace53e31030944155eadde508e13bb00b0e0ed35c4e961f392227804d'];
        foreach ($hashes as $file => $hash) {
            $path = base_path('tests/Fixtures/ComparisonRecovery/sources/'.$file);
            $this->assertSame($hash, hash_file('sha256', $path));
            $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => $file, 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Public',
                'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026,
                'pages' => count(array_filter($source->nodes, fn ($n) => $n['type'] === 'page')), 'file_hash' => $hash]);
            $pipeline = ['route' => 'incremental', 'key' => 'incremental:recovery-'.$doc->id];
            $doc->forceFill(['ai_pipeline' => $pipeline])->save();
            app(StructuredExtractionStore::class)->publish($doc, $source);
            // Merge consumes only existing application checkpoints. None are invented here.
            $merge = app(EvidenceMerger::class)->merge($doc);
            $financial = app(PdfSourceCandidateCollector::class)->collect($doc);
            $categorical = app(PdfCategoricalCandidateCollector::class)->collect($doc);
            $set = app(TextRoleProvenanceProducer::class)->publish($doc);
            $verification = app(IncrementalMetricFieldVerifier::class)->verify($doc, $set);
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'generation' => 1, 'state' => 'published', 'extracted_text' => $source->text,
                'extracted_text_hash' => hash('sha256', $source->text), 'ai_pipeline' => $pipeline, 'provenance_set_id' => $set]);
            $doc->forceFill(['published_intelligence_revision_id' => $revision->id,
                'intelligence_generation' => 1, 'status' => 'Ready'])->save();
            // Explicit Tier 2 scaffolding: these are not completed provider extraction runs.
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                    'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
            }
            $snapshot = app(DocumentComparisonService::class)->snapshot($doc->fresh());
            $pages = [];
            foreach ($source->nodes as $node) {
                if ($node['type'] !== 'page') {
                    continue;
                }
                $text = $node['text'] ?? '';
                $dates = EvidenceDateRecognizer::datesIn($text);
                $pages[] = ['page' => $node['page'], 'start_offset' => $node['start'] ?? null,
                    'end_offset' => $node['end'] ?? null, 'text' => $text, 'recognized_dates' => $dates];
                if (isset($node['start'], $node['end'])) {
                    $this->assertSame($text, mb_substr($source->text, $node['start'], $node['end'] - $node['start']));
                }
            }
            $this->assertStringContainsString('EAGLE SPRINGS SOLAR', $source->text);
            $this->assertStringContainsString('COOPER INDEPENDENT SCHOOL DISTRICT', $source->text);
            $this->assertSame(0, $merge['accepted_records']);
            $this->assertSame(0, $doc->deadlines()->count());
            $this->assertEmpty(array_filter(array_merge([], ...array_values($snapshot)), fn ($f) => $f['category'] === 'deadlines'));
            $reports[] = ['file' => $file, 'sha256' => $hash, 'document_id' => $doc->id, 'revision_id' => $revision->id,
                'parser_version' => $source->parserVersion, 'capabilities' => $source->capabilities,
                'text_sha256' => hash('sha256', $source->text), 'text_characters' => mb_strlen($source->text),
                'node_types' => array_count_values(array_column($source->nodes, 'type')),
                'merge' => $merge, 'financial_collector' => $financial, 'categorical_collector' => $categorical,
                'field_verification' => $verification,
                'evidence_kinds' => DocumentEvidence::where('document_id', $doc->id)->get()->countBy('kind')->all(),
                'deadline_count' => $doc->deadlines()->count(), 'snapshot' => $snapshot, 'pages' => $pages];
            $docs[] = $doc->fresh();
            $snapshots[] = $snapshot;
        }
        // Assertions are independently annotated observations, never extraction inputs.
        foreach ([0 => [8 => ['2028-01-01', '2037-12-31', '2042-12-31']],
            1 => [2 => ['2026-01-01', '2035-12-31', '2040-12-31'],
                16 => ['2028-01-01', '2037-12-31', '2042-12-31']]] as $index => $expectedPages) {
            foreach ($expectedPages as $page => $dates) {
                $actual = array_column($reports[$index]['pages'][$page - 1]['recognized_dates'], 'date');
                foreach ($dates as $date) {
                    $this->assertContains($date, $actual, $reports[$index]['file'].' page '.$page);
                }
            }
        }
        // The last 41 amendment pages reproduce the entire original's parser text.
        // No date scanner may silently treat those older provisions as the replacement.
        $this->assertCount(41, $reports[0]['pages']);
        $this->assertCount(49, $reports[1]['pages']);
        foreach ($reports[0]['pages'] as $index => $page) {
            $this->assertSame($page['text'], $reports[1]['pages'][$index + 8]['text']);
        }
        $identity = app(IdentityCandidates::class)->build(...$snapshots);
        $this->assertEmpty(array_filter($identity['candidates'], fn ($c) => $c['kind'] === 'provision'));
        $service = app(DocumentComparisonService::class);
        $comparison = $service->create($user, $docs[0]->id, $docs[1]->id);
        (new CompareDocumentsJob($comparison->id, $comparison->dispatch_token))->handle($service);
        $comparison->refresh();
        $this->assertSame('completed', $comparison->status);
        $this->assertEmpty(array_filter($comparison->changes, fn ($r) => in_array($r['outcome'], ['supported_change', 'potential_conflict', 'period_comparison'])));
        $saved = $comparison->changes;
        $metadata = $comparison->metadata;
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertOk()->assertJsonPath('data.revision_status', 'current');
        $this->getJson('/api/document-comparisons/'.$comparison->id.'/identity')->assertOk()->assertJsonCount(0, 'data.candidates');
        // Duplicate/delayed delivery preserves historical output and credits/provider state.
        (new CompareDocumentsJob($comparison->id, $comparison->dispatch_token))->handle($service);
        $this->assertSame($saved, $comparison->fresh()->changes);
        $this->assertSame($metadata, $comparison->fresh()->metadata);
        $this->assertNull($comparison->fresh()->provider_started_at);
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $docs[0]->workspace_id, 'document_id' => $docs[0]->id,
            'generation' => 2, 'state' => 'published', 'extracted_text' => 'Freshness simulation only',
            'extracted_text_hash' => hash('sha256', 'Freshness simulation only')]);
        $docs[0]->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertOk()->assertJsonPath('data.revision_status', 'outdated');
        $this->assertSame($metadata, $comparison->fresh()->metadata);
        $this->assertSame($saved, $comparison->fresh()->changes);
        $docs[1]->forceFill(['uploaded_by' => User::factory()->create()->id])->save();
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertNotFound();
        $this->getJson('/api/document-comparisons/'.$comparison->id.'/identity')->assertNotFound();
        Http::assertNothingSent();
        file_put_contents(base_path('tests/Fixtures/ComparisonRecovery/actual-pipeline.json'), json_encode([
            'tier' => 2, 'verdict' => 'blocked_missing_structured_contract_extraction',
            'publication' => 'Locally constructed pointers and stage records, not Tier 3 ingestion',
            'documents' => $reports, 'identity' => $identity, 'comparison_id' => $comparison->id,
            'changes' => $saved, 'metadata' => $metadata,
            'workflow' => ['authorized_reload' => true, 'duplicate_delivery_immutable' => true,
                'new_revision_outdated' => true, 'historical_snapshot_immutable' => true, 'lost_access_404' => true,
                'provider_http_requests' => 0, 'positive_result' => false],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
