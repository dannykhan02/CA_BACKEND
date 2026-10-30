<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\ComparisonM3BaselineService;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsM3PublishedRecords;
use Tests\TestCase;

/** Tier A persisted authored records, exercising the actual projection, review, job and API. */
class ComparisonM3PersistenceTest extends TestCase
{
    use BuildsM3PublishedRecords, RefreshDatabase;

    public function test_categories_reuse_published_records_through_review_storage_source_inspection_and_history(): void
    {
        Bus::fake();
        [$a] = $this->documentWithPublishedRecords();
        $user = User::findOrFail($a->uploaded_by);
        [$b] = $this->documentWithPublishedRecords($user, '30', 'low');
        $service = app(DocumentComparisonService::class);
        $initial = $service->create($user, $a->id, $b->id);
        $saved = $initial->metadata;
        $this->assertCount(3, $saved['identity_context']['candidates']);
        $this->assertSame('identity-m3-v1', $saved['identity_context']['version']);
        (new CompareDocumentsJob($initial->id, $initial->dispatch_token))->handle($service);
        $unreviewed = $initial->fresh()->changes;
        $this->assertSame(0, count(array_filter($unreviewed, fn ($r) => $r['outcome'] === 'supported_change')));
        foreach ($saved['identity_context']['candidates'] as $candidate) {
            if (in_array($candidate['kind'], ['risk', 'provision'])) {
                $this->assertTrue($candidate['reviewable']);
                app(ComparisonIdentityService::class)->decide($user, $initial, $candidate['key'], 'confirmed', 0, 'Reviewed these exact authored source occurrences.');
            }
        }
        $reviewed = $service->create($user, $a->id, $b->id);
        $this->assertNotSame($initial->id, $reviewed->id);
        $this->assertSame($reviewed->id, $service->create($user, $a->id, $b->id)->id);
        (new CompareDocumentsJob($reviewed->id, $reviewed->dispatch_token))->handle($service);
        $reviewed->refresh();
        $this->assertSame('completed', $reviewed->status);
        $supported = array_values(array_filter($reviewed->changes, fn ($r) => $r['outcome'] === 'supported_change'));
        $this->assertEqualsCanonicalizing(['obligations', 'risks'], array_column($supported, 'category'));
        foreach ($supported as $row) {
            $this->assertSame('confirmed', $row['identity_basis']['state']);
            foreach (['before', 'after'] as $side) {
                $this->assertCount(1, $row[$side]);
                $source = $row[$side][0]['source'];
                $this->assertNotEmpty($source['evidence_ids']);
                $this->assertNotEmpty($source['spans']);
                $this->getJson('/api/documents/'.$source['document_id'].'/sources/'.urlencode($source['source_id']))->assertOk()->assertJsonPath('data.state', 'CURRENT');
            }
        }
        $history = $reviewed->changes;
        (new CompareDocumentsJob($reviewed->id, $reviewed->dispatch_token))->handle($service);
        $this->assertSame($history, $reviewed->fresh()->changes);
        $this->getJson('/api/document-comparisons/'.$reviewed->id)->assertOk()->assertJsonPath('data.algorithm_version', 'structured-m3-v1');
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $a->workspace_id, 'document_id' => $a->id, 'generation' => 2,
            'state' => 'published', 'extracted_text' => 'Later authored publication', 'extracted_text_hash' => hash('sha256', 'Later authored publication')]);
        $a->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        $this->getJson('/api/document-comparisons/'.$reviewed->id)->assertOk()->assertJsonPath('data.revision_status', 'outdated');
        $this->assertSame($history, $reviewed->fresh()->changes);
        $this->assertSame($saved, $initial->fresh()->metadata);
        $other = User::factory()->create();
        $b->forceFill(['uploaded_by' => $other->id])->save();
        $this->getJson('/api/document-comparisons/'.$reviewed->id)->assertNotFound();
        $this->getJson('/api/document-comparisons/'.$reviewed->id.'/identity')->assertNotFound();
        $this->getJson('/api/documents/'.$b->id.'/sources/'.urlencode($supported[0]['after'][0]['source']['source_id']))->assertForbidden();
        Http::assertNothingSent();
        file_put_contents(base_path('tests/Fixtures/ComparisonM3/persisted-authored.json'), json_encode([
            'tier' => 'A', 'note' => 'Authored structured records; no genuine completed-analysis acceptance claim.',
            'candidate_count' => count($saved['identity_context']['candidates']), 'applied_identity_count' => count($reviewed->metadata['identity_usage']),
            'unreviewed_outcomes' => array_count_values(array_column($unreviewed, 'outcome')),
            'reviewed_outcomes' => array_count_values(array_column($history, 'outcome')), 'supported_categories' => array_column($supported, 'category'),
            'supported_grounded_sides' => 4, 'expected_supported_grounded_sides' => 4,
            'duplicate_delivery_immutable' => true, 'history_immutable' => true, 'both_source_authorization' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_baseline_and_shared_projection_coverage_are_measured_on_the_same_published_rows(): void
    {
        [$doc] = $this->documentWithPublishedRecords();
        require_once base_path('tests/Fixtures/ComparisonM3/baseline-engine.php');
        $before = app(ComparisonM3BaselineService::class)->snapshot($doc);
        $after = app(DocumentComparisonService::class)->snapshot($doc);
        $count = function ($snapshot) {
            return array_count_values(array_column(array_merge(...array_values($snapshot)), 'category'));
        };
        $this->assertSame(['deadlines' => 1, 'entities' => 1, 'risks' => 1], $count($before));
        $this->assertSame(['entities' => 1, 'kpis' => 1, 'obligations' => 1, 'risks' => 1], $count($after));
        $studio = $this->getJson('/api/documents/'.$doc->id.'/intelligence')->assertOk();
        $this->assertSame(1, $studio->json('data.publishedIntelligence.timeline_records'));
        $user = User::findOrFail($doc->uploaded_by);
        [$other] = $this->documentWithPublishedRecords($user, '30', 'low');
        $oldOther = app(ComparisonM3BaselineService::class)->snapshot($other);
        $newOther = app(DocumentComparisonService::class)->snapshot($other);
        $compareReviewed = function ($service, $a, $b) {
            $engine = app(IdentityCandidates::class);
            $initial = $engine->build($a, $b);
            $decisions = [];
            foreach ($initial['candidates'] as $candidate) {
                if (in_array($candidate['kind'], ['provision', 'risk'])) {
                    $decisions[$candidate['key']] = ['state' => 'confirmed', 'version' => 1, 'id' => 'authored-baseline-review'];
                }
            }

            return $service->changes($a, $b, $engine->build($a, $b, $decisions));
        };
        $oldChanges = $compareReviewed(app(ComparisonM3BaselineService::class), $before, $oldOther);
        $newChanges = $compareReviewed(app(DocumentComparisonService::class), $after, $newOther);
        $oldSupported = array_values(array_filter($oldChanges, fn ($row) => $row['outcome'] === 'supported_change'));
        $newSupported = array_values(array_filter($newChanges, fn ($row) => $row['outcome'] === 'supported_change'));
        $this->assertCount(1, $oldSupported);
        $this->assertCount(2, $newSupported);
        file_put_contents(base_path('tests/Fixtures/ComparisonM3/coverage-authored.json'), json_encode([
            'tier' => 'A', 'before' => $count($before), 'after' => $count($after), 'studio' => $studio->json('data.publishedIntelligence'),
            'reviewed_comparisons_before' => array_count_values(array_column($oldChanges, 'outcome')),
            'reviewed_comparisons_after' => array_count_values(array_column($newChanges, 'outcome')),
            'supported_categories_before' => array_column($oldSupported, 'category'),
            'supported_categories_after' => array_column($newSupported, 'category'),
            'missing_observations_recovered' => 1, 'obligation_reclassified_without_duplicate' => 1,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
