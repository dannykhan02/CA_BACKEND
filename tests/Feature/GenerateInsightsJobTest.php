<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateInsightsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_writes_normalized_chart_points_from_chart_json(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Test WS']);

        $document = Document::create([
            'name' => 'Insights Test Doc',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Processing',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'last_updated_by' => $user->id,
            'extracted_text' => 'Revenue was 100 in Q1 and 150 in Q2.',
        ]);

        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldReceive('extractDocumentInsights')
                ->once()
                ->andReturn([
                    'kpis' => [],
                    'charts' => [
                        [
                            'type' => 'bar',
                            'title' => 'Revenue',
                            'description' => 'Quarterly revenue',
                            'data' => [
                                ['label' => 'Q1', 'value' => 100],
                                ['label' => 'Q2', 'value' => 150],
                                ['label' => 'Invalid', 'value' => 'n/a'],
                            ],
                        ],
                    ],
                    'insights' => ['Revenue grew between quarters.'],
                ]);
        });

        (new GenerateInsightsJob($document->id))->handle(
            app(AnthropicClient::class),
            app(\App\Services\Pipeline\PipelineStageRecorder::class),
        );

        $document->refresh();
        $this->assertSame('Ready', $document->status);

        $chart = DocumentChart::where('document_id', $document->id)->sole();
        $points = DocumentChartPoint::where('document_chart_id', $chart->id)->orderBy('sort_order')->get();

        $this->assertCount(2, $points);
        $this->assertSame('Q1', $points[0]->label);
        $this->assertSame('100.0000', $points[0]->value);
        $this->assertSame(0, $points[0]->sort_order);
        $this->assertSame($workspace->id, $points[0]->workspace_id);
        $this->assertSame('Q2', $points[1]->label);
        $this->assertSame('150.0000', $points[1]->value);
        $this->assertSame(1, $points[1]->sort_order);
    }

    public function test_reprocess_replaces_stale_chart_points(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Reprocess WS']);

        $document = Document::create([
            'name' => 'Reprocess Doc',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Processing',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'last_updated_by' => $user->id,
            'extracted_text' => 'Updated metrics.',
        ]);

        $staleChart = DocumentChart::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'type' => 'bar',
            'title' => 'Old Chart',
            'description' => 'Stale',
            'data' => [['label' => 'Old', 'value' => 1]],
        ]);

        DocumentChartPoint::create([
            'document_chart_id' => $staleChart->id,
            'workspace_id' => $workspace->id,
            'label' => 'Old',
            'value' => 1,
            'sort_order' => 0,
        ]);

        $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldReceive('extractDocumentInsights')
                ->once()
                ->andReturn([
                    'kpis' => [],
                    'charts' => [
                        [
                            'type' => 'line',
                            'title' => 'New Chart',
                            'description' => 'Fresh',
                            'data' => [['label' => 'New', 'value' => 99]],
                        ],
                    ],
                    'insights' => [],
                ]);
        });

        (new GenerateInsightsJob($document->id, true))->handle(
            app(AnthropicClient::class),
            app(\App\Services\Pipeline\PipelineStageRecorder::class),
        );

        $this->assertSame(1, DocumentChart::where('document_id', $document->id)->count());
        $this->assertSame(1, DocumentChartPoint::where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseMissing('document_chart_points', ['label' => 'Old']);
        $this->assertDatabaseHas('document_chart_points', ['label' => 'New', 'value' => '99.0000']);
    }
}
