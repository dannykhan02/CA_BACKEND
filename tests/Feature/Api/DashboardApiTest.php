<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentSeeder::class);
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/summary')->assertStatus(401);
    }

    public function test_summary_returns_correct_counts_per_status(): void
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/summary');

        // Seeded: 3 Ready, 1 Needs Review, 1 Processing, 1 Failed = 6 total
        $response->assertOk()->assertJson([
            'data' => [
                'total' => 6,
                'ready' => 3,
                'processing' => 1,
                'needsReview' => 1,
                'failed' => 1,
            ],
        ]);
    }

    public function test_summary_reflects_zero_counts_on_empty_database(): void
    {
        // No seeding this time — confirms the grouped-count query doesn't
        // choke on an empty table (a common off-by-null bug with pluck()).
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        \App\Models\Document::query()->delete();

        $response = $this->getJson('/api/dashboard/summary');

        $response->assertOk()->assertJson([
            'data' => ['total' => 0, 'ready' => 0, 'processing' => 0, 'needsReview' => 0, 'failed' => 0],
        ]);
    }
}