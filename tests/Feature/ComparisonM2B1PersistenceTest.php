<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\DocumentComparisonService;
use App\Services\IntelligenceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIdentityPair;
use Tests\TestCase;
use Tests\Unit\ComparisonM2B1PeriodTest;

/** Tier 1 state integration: snapshot inputs mocked explicitly; never real extraction evidence. */
class ComparisonM2B1PersistenceTest extends TestCase
{
    use BuildsIdentityPair;
    use RefreshDatabase;

    public function test_period_result_survives_job_storage_reload_revision_publication_and_duplicate_delivery(): void
    {
        [$user, $a, $b] = $this->identityPair();
        $facts = [];
        foreach ([$a, $b] as $index => $doc) {
            $fact = ComparisonM2B1PeriodTest::fact([], (bool) $index);
            $fact['source']['document_id'] = $doc->id;
            $fact['source']['intelligence_revision_id'] = $doc->published_intelligence_revision_id;
            $facts[$doc->id] = ['kpis:label:operating income' => [$fact]];
        }
        $service = \Mockery::mock(DocumentComparisonService::class, [app(IntelligenceAccess::class)])->makePartial();
        $service->shouldReceive('snapshot')->andReturnUsing(fn ($doc) => $facts[$doc->id]);
        $comparison = $service->create($user, $a->id, $b->id);
        $before = $comparison->metadata;
        $this->assertSame(DocumentComparisonService::ALGORITHM, $before['algorithm_version']);
        $this->assertSame('explicit-financial-period-m2b1-v1', $before['interpretation_rules']);
        (new CompareDocumentsJob($comparison->id, $comparison->dispatch_token))->handle($service);
        $comparison->refresh();
        $this->assertSame('completed', $comparison->status);
        $changes = $comparison->changes;
        $this->assertSame('period_comparison', $changes[0]['outcome']);
        $this->assertSame('25000000', $changes[0]['interpretation']['absolute_difference']);
        $this->assertSame('25.000000', $changes[0]['interpretation']['relative_percent']);
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertOk()
            ->assertJsonPath('data.changes.0.interpretation.rule_id', 'explicit-financial-period-m2b1-v1');
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $a->workspace_id, 'document_id' => $a->id,
            'generation' => 2, 'state' => 'published', 'extracted_text' => 'New isolated revision', 'extracted_text_hash' => hash('sha256', 'New isolated revision')]);
        $a->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        (new CompareDocumentsJob($comparison->id, $comparison->dispatch_token))->handle($service);
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertOk()->assertJsonPath('data.revision_status', 'outdated');
        $this->assertSame($changes, $comparison->fresh()->changes);
        $this->assertNull($comparison->fresh()->provider_started_at);
        $this->assertSame($before['base'], $comparison->fresh()->metadata['base']);
        $b->forceFill(['uploaded_by' => User::factory()->create()->id])->save();
        $this->getJson('/api/document-comparisons/'.$comparison->id)->assertNotFound();
        Http::assertNothingSent();
    }
}
