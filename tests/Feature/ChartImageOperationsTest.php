<?php

namespace Tests\Feature;

use App\Jobs\PurgeDocumentChartImagesJob;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ChartImageOperationsTest extends TestCase
{
    use RefreshDatabase;

    private string|false $oldService;

    private string|false $oldEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldService = getenv('RAILWAY_SERVICE_NAME');
        $this->oldEnvironment = getenv('RAILWAY_ENVIRONMENT_NAME');
        Storage::fake('documents');
        config()->set('filesystems.disks.documents', [
            'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
            'region' => 'auto', 'bucket' => 'private-test',
            'endpoint' => 'https://r2.example.test', 'use_path_style_endpoint' => true,
            'visibility' => 'private', 'throw' => true,
        ]);
        putenv('RAILWAY_SERVICE_NAME=ca-horizon-worker');
        putenv('RAILWAY_ENVIRONMENT_NAME=staging');
    }

    protected function tearDown(): void
    {
        $this->oldService === false ? putenv('RAILWAY_SERVICE_NAME') : putenv('RAILWAY_SERVICE_NAME='.$this->oldService);
        $this->oldEnvironment === false ? putenv('RAILWAY_ENVIRONMENT_NAME') : putenv('RAILWAY_ENVIRONMENT_NAME='.$this->oldEnvironment);
        parent::tearDown();
    }

    private function probe(string $phase, array $options = []): int
    {
        return Artisan::call('chart-images:storage-diagnostic', array_merge([
            'phase' => $phase, '--expected-service' => $phase === 'write' ? 'ca-horizon-worker' : 'CA_BACKEND',
            '--expected-environment' => 'staging',
        ], $options));
    }

    public function test_synthetic_worker_write_web_hash_privacy_check_and_confirmed_cleanup(): void
    {
        self::assertSame(0, $this->probe('write'));
        $output = Artisan::output();
        self::assertMatchesRegularExpression('/run=([a-f0-9-]{36}) sha256=([a-f0-9]{64})/', $output);
        preg_match('/run=([a-f0-9-]{36}) sha256=([a-f0-9]{64})/', $output, $matches);
        [, $run, $sha] = $matches;
        $path = "chart-storage-diagnostics/{$run}/probe.png";
        Storage::disk('documents')->assertExists($path);
        self::assertSame($sha, hash('sha256', Storage::disk('documents')->get($path)));

        putenv('RAILWAY_SERVICE_NAME=CA_BACKEND');
        Http::fake(['https://r2.example.test/*' => Http::response('', 403)]);
        self::assertSame(0, $this->probe('verify', [
            '--run' => $run, '--sha256' => $sha, '--public-access-reviewed' => true,
        ]));
        self::assertStringContainsString('cross_service_read=ok unsigned_access=denied', Artisan::output());
        self::assertSame(0, $this->probe('cleanup', ['--run' => $run, '--sha256' => $sha]));
        Storage::disk('documents')->assertMissing($path);
    }

    public function test_public_access_or_wrong_hash_fails_closed_without_deleting_the_probe(): void
    {
        $run = (string) Str::uuid();
        $path = "chart-storage-diagnostics/{$run}/probe.png";
        self::assertSame(0, $this->probe('write', ['--run' => $run]));
        preg_match('/sha256=([a-f0-9]{64})/', Artisan::output(), $matches);
        $sha = $matches[1];
        putenv('RAILWAY_SERVICE_NAME=CA_BACKEND');
        Http::fake(['https://r2.example.test/*' => Http::response('public', 200)]);
        self::assertSame(1, $this->probe('verify', [
            '--run' => $run, '--sha256' => $sha, '--public-access-reviewed' => true,
        ]));
        Storage::disk('documents')->assertExists($path);
        self::assertSame(1, $this->probe('cleanup', ['--run' => $run, '--sha256' => str_repeat('a', 64)]));
        Storage::disk('documents')->assertExists($path);
        self::assertSame(0, $this->probe('cleanup', ['--run' => $run, '--sha256' => $sha]));
    }

    public function test_service_identity_and_public_domain_review_are_required(): void
    {
        putenv('RAILWAY_SERVICE_NAME=CA_BACKEND');
        self::assertSame(1, $this->probe('write'));
        self::assertStringContainsString('identity is absent or differs', Artisan::output());
        self::assertSame(1, $this->probe('write', ['--expected-service' => 'CA_BACKEND']));
        self::assertStringContainsString('Use the Horizon worker for write', Artisan::output());
        putenv('RAILWAY_SERVICE_NAME=ca-horizon-worker');
        self::assertSame(0, $this->probe('write'));
        preg_match('/run=([a-f0-9-]{36}) sha256=([a-f0-9]{64})/', Artisan::output(), $matches);
        putenv('RAILWAY_SERVICE_NAME=CA_BACKEND');
        Http::fake(['https://r2.example.test/*' => Http::response('', 403)]);
        self::assertSame(1, $this->probe('verify', ['--run' => $matches[1], '--sha256' => $matches[2]]));
        self::assertStringContainsString('public-domain settings have not been reviewed', Artisan::output());
    }

    public function test_public_custom_domain_exposure_fails_even_when_r2_endpoint_denies_access(): void
    {
        self::assertSame(0, $this->probe('write'));
        preg_match('/run=([a-f0-9-]{36}) sha256=([a-f0-9]{64})/', Artisan::output(), $matches);
        putenv('RAILWAY_SERVICE_NAME=CA_BACKEND');
        Http::fake([
            'https://r2.example.test/*' => Http::response('', 403),
            'https://public.example.test/*' => Http::response('public', 200),
        ]);

        self::assertSame(1, $this->probe('verify', [
            '--run' => $matches[1], '--sha256' => $matches[2],
            '--public-base-url' => ['https://public.example.test'], '--public-access-reviewed' => true,
        ]));
        self::assertStringContainsString('Unsigned access was not denied for endpoint 2 (HTTP 200)', Artisan::output());
        self::assertSame(0, $this->probe('cleanup', ['--run' => $matches[1], '--sha256' => $matches[2]]));
    }

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create([
            'workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Chart.pdf', 'type' => 'PDF',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
        ]);
    }

    public function test_read_only_orphan_inventory_preserves_retained_revision_references(): void
    {
        $document = $this->document();
        $prefix = "chart-visuals/{$document->workspace_id}/{$document->id}/legacy/";
        $referenced = $prefix.str_repeat('a', 64);
        $unreferenced = $prefix.str_repeat('b', 64);
        $other = "chart-visuals/{$document->workspace_id}/".Str::uuid().'/legacy/'.str_repeat('c', 64);
        foreach ([$referenced, $unreferenced, $other] as $path) {
            Storage::disk('documents')->put($path, 'synthetic');
            touch(Storage::disk('documents')->path($path), time() - 172800);
        }
        DocumentChart::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'type' => 'bar', 'title' => 'Retained revision', 'description' => '', 'data' => [],
            'source_metadata' => ['path' => $referenced]]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis',
            'queue' => 'default', 'payload' => PurgeDocumentChartImagesJob::class,
            'exception' => 'synthetic failure', 'failed_at' => now()]);

        self::assertSame(0, Artisan::call('chart-images:audit', [
            '--workspace' => $document->workspace_id, '--document' => $document->id,
            '--min-age-hours' => 24,
        ]));
        $output = Artisan::output();
        self::assertStringContainsString('failed_purge_jobs=1', $output);
        self::assertStringContainsString("candidate={$unreferenced}", $output);
        self::assertStringNotContainsString("candidate={$referenced}", $output);
        self::assertStringNotContainsString("candidate={$other}", $output);
        foreach ([$referenced, $unreferenced, $other] as $path) Storage::disk('documents')->assertExists($path);
    }

    public function test_purge_refuses_a_prefix_still_referenced_by_another_retained_chart(): void
    {
        Queue::fake();
        $deleted = $this->document();
        $keeper = $this->document();
        $path = "chart-visuals/{$deleted->workspace_id}/{$deleted->id}/legacy/".str_repeat('d', 64);
        Storage::disk('documents')->put($path, 'synthetic');
        DocumentChart::create(['document_id' => $keeper->id, 'workspace_id' => $keeper->workspace_id,
            'type' => 'bar', 'title' => 'Retained reference', 'description' => '', 'data' => [],
            'source_metadata' => ['path' => $path]]);
        $deleted->forceDelete();
        $job = new PurgeDocumentChartImagesJob($deleted->workspace_id, $deleted->id);
        try {
            $job->handle();
            self::fail('A retained chart reference must prevent deletion.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Retained chart metadata', $error->getMessage());
        }
        Storage::disk('documents')->assertExists($path);
    }

    public function test_transient_purge_failure_retries_the_same_exact_prefix(): void
    {
        Queue::fake();
        $document = $this->document();
        $workspace = $document->workspace_id;
        $id = $document->id;
        $document->forceDelete();
        $disk = Mockery::mock();
        $disk->shouldReceive('deleteDirectory')->once()->with("chart-visuals/{$workspace}/{$id}")
            ->andThrow(new RuntimeException('transient R2 failure'));
        $disk->shouldReceive('deleteDirectory')->once()->with("chart-visuals/{$workspace}/{$id}")
            ->andReturn(true);
        Storage::shouldReceive('disk')->twice()->with('documents')->andReturn($disk);
        $job = new PurgeDocumentChartImagesJob($workspace, $id);
        self::assertSame(3, $job->tries);
        self::assertSame([60, 300], $job->backoff);
        try {
            $job->handle();
            self::fail('The first attempt should be retried by the queue.');
        } catch (RuntimeException $error) {
            self::assertSame('transient R2 failure', $error->getMessage());
        }
        $job->handle();
    }

    public function test_exhausted_purge_is_logged_without_storage_secrets(): void
    {
        Log::spy();
        $job = new PurgeDocumentChartImagesJob((string) Str::uuid(), (string) Str::uuid());
        $job->failed(new RuntimeException('secret storage endpoint'));
        Log::shouldHaveReceived('critical')->once()->withArgs(function ($message, $context) {
            return $message === 'Chart-image purge exhausted retries; operator review required.'
                && $context['error_type'] === RuntimeException::class
                && ! str_contains(json_encode($context), 'secret storage endpoint');
        });
    }
}
