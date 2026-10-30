<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\Matter;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Comparisons\ComparisonRecovery;
use App\Services\DocumentComparisonService;
use App\Services\Reports\ReportSnapshotService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComparisonTrustTest extends TestCase
{
    use RefreshDatabase;

    private function pair(): array
    {
        Bus::fake();
        $user = User::factory()->create(['role' => 'Administrator']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        Sanctum::actingAs($user);
        $docs = [];
        foreach (['A', 'B'] as $name) {
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => $name, 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026]);
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'stage' => $stage, 'status' => 'completed']);
            }
            $docs[] = $doc;
        }
        $item = app(DocumentComparisonService::class)->create($user, $docs[0]->id, $docs[1]->id);

        return [$user, ...$docs, $item];
    }

    public function test_lost_dispatch_is_recovered_by_authorized_read_and_late_delivery_is_fenced(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        $old = $item->dispatch_token;
        $item->update(['dispatched_at' => now()->subMinutes(6)]);
        $this->getJson('/api/document-comparisons/'.$item->id)->assertOk()->assertJsonPath('data.status', 'queued');
        $item->refresh();
        $this->assertNotSame($old, $item->dispatch_token);
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 2);
        $service = app(DocumentComparisonService::class);
        (new CompareDocumentsJob($item->id, $old))->handle($service);
        $this->assertSame('queued', $item->fresh()->status);
        $job = new CompareDocumentsJob($item->id, $item->dispatch_token);
        $job->handle($service);
        $completed = $item->fresh()->toArray();
        $job->handle($service);
        $job->failed(new \RuntimeException('late delivery'));
        $this->assertSame($completed, $item->fresh()->toArray());
    }

    public function test_live_postgres_session_prevents_recovery_even_after_a_day(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        $item->update(['status' => 'processing', 'dispatched_at' => now()->subDays(2)]);
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
        $statement = $pdo->prepare("SELECT pg_advisory_lock(hashtext('docintel-comparison'), hashtext(?))");
        $statement->execute([$item->id]);
        try {
            app(ComparisonRecovery::class)->reconcile($item->id);
            $this->assertSame('processing', $item->fresh()->status);
            $this->assertSame($item->dispatch_token, $item->fresh()->dispatch_token);
        } finally {
            $statement = $pdo->prepare("SELECT pg_advisory_unlock(hashtext('docintel-comparison'), hashtext(?))");
            $statement->execute([$item->id]);
        }
        app(ComparisonRecovery::class)->reconcile($item->id);
        $this->assertSame('queued', $item->fresh()->status);
    }

    public function test_crashed_paid_attempt_is_not_replayed_and_manual_retry_is_blocked(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        $item->update(['status' => 'processing', 'provider_started_at' => now()]);
        app(ComparisonRecovery::class)->reconcile($item->id);
        $this->getJson('/api/document-comparisons/'.$item->id)->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.retry_allowed', false);
        $this->postJson('/api/document-comparisons', ['base_document_id' => $a->id, 'compared_document_id' => $b->id])->assertStatus(409);
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 1);
    }

    public function test_recent_queued_work_is_not_failed_or_repeated_and_failed_retry_changes_generation(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        app(ComparisonRecovery::class)->reconcile($item->id);
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 1);
        $old = new CompareDocumentsJob($item->id, $item->dispatch_token);
        $old->failed(new \RuntimeException('lost worker'));
        $retry = app(DocumentComparisonService::class)->create($user, $a->id, $b->id);
        $this->assertSame($item->id, $retry->id);
        $this->assertNotSame($item->dispatch_token, $retry->dispatch_token);
        $old->failed(new \RuntimeException('late callback'));
        $this->assertSame('queued', $retry->fresh()->status);
    }

    public function test_legacy_pending_result_is_not_silently_recomputed_with_new_algorithm(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        $metadata = $item->metadata;
        unset($metadata['algorithm_version']);
        $item->update(['metadata' => $metadata, 'status' => 'processing']);
        app(ComparisonRecovery::class)->reconcile($item->id);
        $this->assertSame('failed', $item->fresh()->status);
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 1);
    }

    public function test_matter_obligations_and_report_use_the_same_types_and_exact_date_order(): void
    {
        [$user, $a] = $this->pair();
        $matter = Matter::create(['name' => 'Classification', 'workspace_id' => $a->workspace_id, 'created_by' => $user->id]);
        $a->update(['matter_id' => $matter->id]);
        foreach ([['Event', 'event', '2026-01-01', 'explicit'], ['Later', 'payment', '2026-11-02', 'explicit'],
            ['Earlier', ' Contractual-Obligation ', '2026-11-01', 'explicit'], ['Partial', 'delivery', null, 'inferred'], ['Relative', 'completion', null, 'relative']] as [$title,$type,$date,$dateType]) {
            $a->deadlines()->create(['workspace_id' => $a->workspace_id, 'title' => $title, 'description' => $title,
                'deadline_type' => $type, 'due_date' => $date, 'date_type' => $dateType, 'relative_text' => 'Reported context', 'evidence' => 'Source '.$title, 'confidence' => .9, 'prompt_version' => '1']);
        }
        $response = $this->getJson('/api/matters/'.$matter->id.'/intelligence?kind=obligations')->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(['Earlier', 'Later'], array_slice(array_column($response->json('data'), 'title'), 0, 2));
        $this->getJson('/api/matters/'.$matter->id)->assertOk()->assertJsonPath('overview.obligations', 4);
        $this->getJson('/api/matters/'.$matter->id.'/intelligence?kind=deadlines')->assertOk()->assertJsonCount(5, 'data');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $report = app(ReportSnapshotService::class)->matter($request, $matter);
        $this->assertCount(4, $report['sections']['obligations']);
        $this->assertCount(5, $report['sections']['deadlines']);
    }

    public function test_snapshot_keeps_period_and_raw_value_and_never_verifies_legacy_kpi_evidence(): void
    {
        [$user, $a, $b] = $this->pair();
        $a->kpis()->create(['workspace_id' => $a->workspace_id, 'label' => 'Revenue', 'value' => '12,000,000', 'unit' => 'KES', 'period' => '2025']);
        $snapshot = app(DocumentComparisonService::class)->snapshot($a);
        $row = $snapshot['kpis:label:revenue'][0];
        $this->assertSame('2025', $row['value']['period']);
        $this->assertSame('12,000,000', $row['value']['value']);
        $this->assertFalse($row['context']['analytical_eligible']);
        $this->assertSame('unverified', $row['context']['verification']);
    }

    public function test_revision_publication_during_execution_preserves_snapshots_and_marks_result_outdated(): void
    {
        [$user, $a, $b, $item] = $this->pair();
        $original = $item->metadata;
        $service = \Mockery::mock(DocumentComparisonService::class);
        $service->shouldReceive('changes')->once()->with($original['base'], $original['compared'])->andReturnUsing(function () use ($a) {
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $a->workspace_id,
                'document_id' => $a->id, 'generation' => 1, 'state' => 'published', 'extracted_text' => '', 'extracted_text_hash' => hash('sha256', '')]);
            $a->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 1])->save();

            return [];
        });
        (new CompareDocumentsJob($item->id, $item->dispatch_token))->handle($service);
        $this->assertSame($original, $item->fresh()->metadata);
        $this->getJson('/api/document-comparisons/'.$item->id)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.revision_status', 'outdated');
        Bus::assertDispatchedTimes(CompareDocumentsJob::class, 1);
    }
}
