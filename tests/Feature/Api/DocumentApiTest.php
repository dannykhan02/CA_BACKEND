<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentSeeder::class);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        return $user;
    }

    // ---- Authentication guards ----

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/documents')->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $doc = Document::first();
        $this->getJson("/api/documents/{$doc->id}")->assertStatus(401);
    }

    public function test_dashboard_summary_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/summary')->assertStatus(401);
    }

    public function test_index_returns_all_seeded_documents_paginated(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type', 'sizeKb', 'status', 'classification', 'year',
                        'uploadedAt', 'uploadedBy', 'pages', 'hasStructuredData',
                        'kpis', 'charts', 'pageFlags', 'insights'],
                ],
                'links', 'meta',
            ]);
    }

    public function test_index_list_items_omit_nested_relations_thin_shape(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents');

        foreach ($response->json('data') as $item) {
            $this->assertSame([], $item['kpis']);
            $this->assertSame([], $item['charts']);
            $this->assertSame([], $item['pageFlags']);
            $this->assertSame([], $item['insights']);
        }
    }

    public function test_index_respects_per_page(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?per_page=2');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(6, $response->json('meta.total'));
    }

    public function test_index_rejects_per_page_over_max(): void
    {
        $this->authenticate();

        $this->getJson('/api/documents?per_page=101')->assertStatus(422);
    }

    public function test_index_search_by_name_matches_case_insensitively(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?q=corrupted');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Corrupted_scan.pdf', $response->json('data.0.name'));
    }

    public function test_index_search_with_no_matches_returns_empty_set(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?q=nonexistent-file-xyz');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_filters_by_single_status(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?status[]=Ready');

        $response->assertOk()->assertJsonCount(3, 'data');
        foreach ($response->json('data') as $doc) {
            $this->assertSame('Ready', $doc['status']);
        }
    }

    public function test_index_filters_by_status_as_comma_separated_string(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?status=Failed,Processing');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_rejects_invalid_status_value(): void
    {
        $this->authenticate();

        $this->getJson('/api/documents?status[]=NotARealStatus')->assertStatus(422);
    }

    public function test_index_filters_by_classification(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?classification[]=Public');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_year(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?year=2025');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Universal Service Fund Annual Report.pdf', $response->json('data.0.name'));
    }

    public function test_index_filters_by_author_name(): void
    {
        $this->authenticate();

        // Amani Otieno uploaded doc1 (Q3 Sector), doc3 (Stakeholder), doc4 (Spectrum Auction) = 3
        $response = $this->getJson('/api/documents?author=Amani');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_index_combines_multiple_filters(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?status[]=Ready&classification[]=Public&year=2026');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Stakeholder Consultation Summary.pdf', $response->json('data.0.name'));
    }

    public function test_index_sorts_by_name_ascending(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents?sort_by=name&sort_dir=asc');

        $names = collect($response->json('data'))->pluck('name')->all();
        $sorted = collect($names)->sort()->values()->all();
        $this->assertSame($sorted, $names);
    }

    public function test_index_rejects_unwhitelisted_sort_column(): void
    {
        $this->authenticate();

        $this->getJson('/api/documents?sort_by=password')->assertStatus(422);
    }

    public function test_show_returns_full_nested_shape_for_structured_document(): void
    {
        $this->authenticate();
        $doc = Document::where('name', 'Q3 Sector Performance Report.pdf')->firstOrFail();

        $response = $this->getJson("/api/documents/{$doc->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Q3 Sector Performance Report.pdf')
            ->assertJsonCount(6, 'data.kpis')
            ->assertJsonCount(3, 'data.charts')
            ->assertJsonCount(3, 'data.pageFlags')
            ->assertJsonCount(4, 'data.insights');
    }

    public function test_show_unstructured_document_has_empty_kpis_and_charts_but_populated_insights(): void
    {
        $this->authenticate();
        $doc = Document::where('name', 'Stakeholder Consultation Summary.pdf')->firstOrFail();

        $response = $this->getJson("/api/documents/{$doc->id}");

        $response->assertOk()
            ->assertJsonPath('data.hasStructuredData', false)
            ->assertJsonCount(0, 'data.kpis')
            ->assertJsonCount(0, 'data.charts')
            ->assertJsonCount(3, 'data.insights');
    }

    public function test_show_processing_document_exposes_progress(): void
    {
        $this->authenticate();
        $doc = Document::where('name', 'Spectrum Auction Outcomes 2026.pdf')->firstOrFail();

        $response = $this->getJson("/api/documents/{$doc->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'Processing')
            ->assertJsonPath('data.progress', 42)
            ->assertJsonPath('data.errorMessage', null);
    }

    public function test_show_failed_document_exposes_error_message(): void
    {
        $this->authenticate();
        $doc = Document::where('name', 'Corrupted_scan.pdf')->firstOrFail();

        $response = $this->getJson("/api/documents/{$doc->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'Failed')
            ->assertJsonPath('data.errorMessage', 'The PDF appears to be password-protected. Please remove protection and re-upload.')
            ->assertJsonCount(0, 'data.kpis');
    }

    public function test_show_nonexistent_document_returns_normalized_404(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found.']);
    }

    public function test_show_malformed_id_does_not_500_and_returns_404(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/documents/not-a-uuid');

        $response->assertStatus(404);
    }
}
