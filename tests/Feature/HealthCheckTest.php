<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_is_reachable_without_authentication(): void
    {
        // No Sanctum actingAs() call here on purpose — /api/health must be
        // reachable by external monitors (uptime checkers, deploy scripts)
        // that have no app credentials at all.
        $response = $this->getJson('/api/health');

        $response->assertStatus(in_array($response->status(), [200, 503]) ? $response->status() : 200);
        $this->assertContains($response->status(), [200, 503]);
    }

    public function test_healthy_response_shape_and_status_code(): void
    {
        // All four dependencies healthy by default in the test environment
        // (sqlite/testing DB, no Redis/queue/storage failures induced).
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'healthy',
            ])
            ->assertJsonStructure([
                'success',
                'status',
                'services' => [
                    'database',
                    'redis',
                    'queue',
                    'storage',
                ],
            ]);

        // Exactly these top-level keys — nothing extra. This is the check
        // that actually guards against an accidental future leak (e.g. a
        // stray debug field, a connection string, an exception message)
        // getting added to the response without anyone noticing.
        $this->assertEqualsCanonicalizing(
            ['success', 'status', 'services'],
            array_keys($response->json())
        );

        $this->assertEqualsCanonicalizing(
            ['database', 'redis', 'queue', 'storage'],
            array_keys($response->json('services'))
        );
    }

    public function test_returns_503_when_database_is_down(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE[08006] connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'status' => 'degraded',
            ])
            ->assertJsonPath('services.database', 'unhealthy');
    }

    public function test_returns_503_when_redis_is_down(): void
    {
        Redis::shouldReceive('connection->ping')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('services.redis', 'unhealthy');
    }

    public function test_returns_503_when_queue_is_down(): void
    {
        Queue::shouldReceive('connection->size')
            ->once()
            ->andThrow(new \RuntimeException('Queue connection failed'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('services.queue', 'unhealthy');
    }

    public function test_returns_503_when_storage_is_down(): void
    {
        Storage::shouldReceive('disk')
            ->once()
            ->andThrow(new \RuntimeException('Disk not configured'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('services.storage', 'unhealthy');
    }

    public function test_storage_check_does_not_touch_the_real_disk(): void
    {
        // Guard against the controller's real put()/delete() cycle writing
        // to an actual disk during test runs.
        Storage::fake(config('filesystems.default'));

        $this->getJson('/api/health')->assertStatus(200);

        Storage::disk(config('filesystems.default'))->assertDirectoryEmpty('/');
    }

    public function test_degraded_response_does_not_leak_sensitive_data(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new \RuntimeException(
                'SQLSTATE[08006] password authentication failed for user "app_prod" host=db.internal.example.com'
            ));

        $response = $this->getJson('/api/health');
        $body = $response->getContent();

        $response->assertStatus(503);

        // The controller's report($e) sends this to the logger, not the
        // HTTP response. This assertion is what actually proves that
        // contract holds, rather than trusting the docblock comment.
        $this->assertStringNotContainsStringIgnoringCase('password', $body);
        $this->assertStringNotContainsStringIgnoringCase('app_prod', $body);
        $this->assertStringNotContainsStringIgnoringCase('db.internal.example.com', $body);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
        $this->assertStringNotContainsStringIgnoringCase('exception', $body);
        $this->assertStringNotContainsStringIgnoringCase('stack', $body);
        $this->assertStringNotContainsStringIgnoringCase('trace', $body);
        $this->assertStringNotContainsStringIgnoringCase('/home/', $body);
        $this->assertStringNotContainsStringIgnoringCase('/var/www', $body);
    }

    public function test_partial_degradation_only_marks_failed_services_unhealthy(): void
    {
        // Only Redis fails — database, queue, storage should still report
        // healthy individually, proving services are checked independently
        // rather than one failure short-circuiting the rest.
        Redis::shouldReceive('connection->ping')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('services.redis', 'unhealthy')
            ->assertJsonPath('services.database', 'healthy')
            ->assertJsonPath('services.queue', 'healthy')
            ->assertJsonPath('services.storage', 'healthy');
    }
}