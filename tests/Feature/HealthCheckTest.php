<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_service_status_envelope(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertJsonStructure([
            'success',
            'status',
            'services' => ['database', 'redis', 'queue', 'storage'],
        ]);

        $status = $response->json('status');
        $this->assertContains($status, ['healthy', 'degraded']);
        $this->assertContains($response->status(), [200, 503]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($body));
        $this->assertStringNotContainsString('exception', strtolower($body));
    }

    public function test_health_endpoint_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertJsonStructure(['success', 'status', 'services']);
    }
}
