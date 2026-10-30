<?php

namespace Tests\Feature;

use App\Jobs\CompareDocumentsJob;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Comparisons\ComparisonRecovery;
use App\Services\DocumentComparisonService;
use App\Services\EntitlementService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComparisonRecoveryConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE users, workspaces RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function fixture(bool $paid): DocumentComparison
    {
        Bus::fake();
        config(['ai_credits.enabled' => true]);
        $user = User::factory()->create(['role' => 'Administrator']);
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 24, 'trial_credits_remaining' => 0]);
        $documents = [];
        foreach (['A', 'B'] as $name) {
            $documents[] = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'name' => $name,
                'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026]);
        }
        $metadata = ['algorithm_version' => DocumentComparisonService::ALGORITHM, 'base' => [], 'compared' => []];
        if ($paid) {
            $metadata['ai_context'] = ['base' => [], 'compared' => []];
        }
        $item = DocumentComparison::create(['workspace_id' => $workspace->id, 'created_by' => $user->id,
            'base_document_id' => $documents[0]->id, 'compared_document_id' => $documents[1]->id,
            'fingerprint' => hash('sha256', 'concurrency'), 'metadata' => $metadata,
            'dispatch_token' => (string) Str::uuid(), 'dispatched_at' => now()->subHour()]);
        if ($paid) {
            app(EntitlementService::class)->reserveComparison($item, true);
        }

        return $item;
    }

    private function race(callable $work): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl');
        }
        DB::disconnect();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $work();
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $error) {
                    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public function test_two_recovery_workers_only_replace_one_dispatch_generation(): void
    {
        $item = $this->fixture(false);
        $old = $item->dispatch_token;
        $this->race(fn () => app(ComparisonRecovery::class)->reconcile($item->id));
        $new = $item->fresh();
        $this->assertNotSame($old, $new->dispatch_token);
        $this->assertSame('queued', $new->status);
        // A third recovery cannot change the freshly delivered generation.
        app(ComparisonRecovery::class)->reconcile($item->id);
        $this->assertSame($new->dispatch_token, $item->fresh()->dispatch_token);
    }

    public function test_duplicate_paid_deliveries_make_one_provider_call_and_one_credit_debit(): void
    {
        $item = $this->fixture(true);
        $file = tempnam(sys_get_temp_dir(), 'comparison-provider-count-');
        try {
            $this->race(function () use ($item, $file) {
                $client = \Mockery::mock(AnthropicClient::class);
                $client->shouldReceive('forOperation')->andReturnSelf();
                $client->shouldReceive('compareDocumentIntelligence')->andReturnUsing(function () use ($file) {
                    file_put_contents($file, "called\n", FILE_APPEND | LOCK_EX);

                    return ['changes' => [], 'model' => 'fake-no-provider', 'prompt_version' => 2];
                });
                app()->instance(AnthropicClient::class, $client);
                (new CompareDocumentsJob($item->id, $item->dispatch_token))->handle(app(DocumentComparisonService::class));
            });
            $this->assertSame("called\n", file_get_contents($file));
            $this->assertSame('completed', $item->fresh()->status);
            $this->assertSame(12, (int) DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->value('amount_settled'));
            $this->assertSame(1, DB::table('credit_ledger')->where('related_type', 'comparison')->where('related_id', $item->id)->where('reason', 'comparison_completed')->where('direction', 'debit')->count());
            app(ComparisonRecovery::class)->reconcile($item->id);
            $this->assertSame('completed', $item->fresh()->status);
        } finally {
            unlink($file);
        }
    }
    public function test_expired_hold_is_renewed_before_one_call_and_failure_releases_without_replay(): void
    {
        $item = $this->fixture(true);
        app(EntitlementService::class)->releaseComparison($item);
        $client = \Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('forOperation')->once()->andReturnSelf();
        $client->shouldReceive('compareDocumentIntelligence')->once()->andThrow(new \RuntimeException('Synthetic provider failure'));
        app()->instance(AnthropicClient::class, $client);
        $job = new CompareDocumentsJob($item->id, $item->dispatch_token);
        try {
            $job->handle(app(DocumentComparisonService::class));
            $this->fail('Expected synthetic provider failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic provider failure', $error->getMessage());
        }
        $job->handle(app(DocumentComparisonService::class));
        $job->failed(new \RuntimeException('duplicate callback'));
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->provider_started_at);
        $this->assertSame('released', DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->value('status'));
        $this->assertSame(24, (int) DB::table('workspace_credits')->where('workspace_id', $item->workspace_id)->value('ai_credits_remaining'));
        $this->assertSame(0, DB::table('credit_ledger')->where('related_id', $item->id)->where('reason', 'comparison_completed')->count());
    }

    public function test_deleting_either_source_releases_hold_before_soft_or_hard_comparison_cleanup(): void
    {
        foreach ([false, true] as $hard) {
            $item = $this->fixture(true);
            $source = Document::findOrFail($item->compared_document_id);
            $hard ? $source->forceDelete() : $source->delete();
            (new CompareDocumentsJob($item->id, $item->dispatch_token))->handle(app(DocumentComparisonService::class));
            $this->assertNull($item->fresh());
            $this->assertSame('released', DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->value('status'));
            $this->assertSame(24, (int) DB::table('workspace_credits')->where('workspace_id', $item->workspace_id)->value('ai_credits_remaining'));
        }
    }

    public function test_active_session_protects_an_expired_credit_hold_until_execution_is_abandoned(): void
    {
        $item = $this->fixture(true);
        $item->update(['status' => 'processing', 'provider_started_at' => now()->subDays(2)]);
        DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->update(['updated_at' => now()->subDays(2)]);
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
        $lock = $pdo->prepare("SELECT pg_advisory_lock(hashtext('docintel-comparison'), hashtext(?))");
        $lock->execute([$item->id]);
        try {
            app(EntitlementService::class)->releaseStaleReservations($item->workspace_id);
            app(ComparisonRecovery::class)->reconcile($item->id);
            $this->assertSame('reserved', DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->value('status'));
            $this->assertSame('processing', $item->fresh()->status);
        } finally {
            $unlock = $pdo->prepare("SELECT pg_advisory_unlock(hashtext('docintel-comparison'), hashtext(?))");
            $unlock->execute([$item->id]);
        }
        app(ComparisonRecovery::class)->reconcile($item->id);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('released', DB::table('billing_operations')->where('kind', 'comparison')->where('resource_id', $item->id)->value('status'));
    }

    public function test_concurrent_manual_retries_issue_one_new_generation(): void
    {
        $seed = $this->fixture(false);
        $user = User::findOrFail($seed->created_by);
        foreach ([$seed->base_document_id, $seed->compared_document_id] as $id) {
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                \App\Models\ProcessingJob::create(['workspace_id' => $seed->workspace_id, 'document_id' => $id, 'stage' => $stage, 'status' => 'completed']);
            }
        }
        $current = app(DocumentComparisonService::class)->create($user, $seed->base_document_id, $seed->compared_document_id);
        $current->update(['status' => 'failed']);
        $file = tempnam(sys_get_temp_dir(), 'comparison-retry-count-');
        try {
            $this->race(function () use ($current, $user, $file) {
                Bus::fake();
                app(DocumentComparisonService::class)->create($user, $current->base_document_id, $current->compared_document_id);
                file_put_contents($file, Bus::dispatched(CompareDocumentsJob::class)->count()."\n", FILE_APPEND | LOCK_EX);
            });
            $this->assertSame(1, array_sum(array_map('intval', file($file))));
            $this->assertNotSame($current->dispatch_token, $current->fresh()->dispatch_token);
            $this->assertSame('queued', $current->fresh()->status);
        } finally { unlink($file); }
    }

}
