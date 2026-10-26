<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Http\Resources\ChartResource;
use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\PurgeDocumentChartImagesJob;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\DocumentIntelligenceService;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\SourceChartAccess;
use App\Services\Intelligence\SourceChartContract;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceChartContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_charts_render_by_default_with_unverified_origin_and_export_limits(): void
    {
        [, $document] = $this->document();
        $chart = DocumentChart::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'type' => 'bar', 'title' => 'Survey area', 'description' => '',
            'data' => [['label' => 'Pasture', 'value' => 2.91],
                ['label' => 'Woodland', 'value' => 1.22]],
            'source_metadata' => ['origin' => 'model_unverified'],
        ]);
        $request = Request::create('/api/documents/'.$document->id);
        self::assertArrayNotHasKey('model_chart_rendering', config('document_intelligence'));
        self::assertArrayNotHasKey('model_chart_match_shadow', config('document_intelligence'));
        $serialized = (new ChartResource($chart))->resolve($request);
        self::assertTrue($serialized['renderEligible']);
        self::assertSame('model_unverified', $serialized['sourceOrigin']);
        self::assertSame('ai_interpreted', $serialized['evidenceStatus']);
        self::assertSame('AI-interpreted · Not independently verified', $serialized['evidenceLabel']);
        self::assertFalse($serialized['valuesVerified']);
        self::assertFalse($serialized['dimensionsVerified']);
        self::assertSame(['csv' => false, 'png' => false, 'word' => false,
            'originalImage' => false], $serialized['exportSupport']);
        self::assertNull($serialized['sourcePage']);
        self::assertNull($serialized['valueFormat']);
        $chart->description = 'Values in KES million';
        $reported = (new ChartResource($chart))->resolve($request);
        self::assertSame('KES million', $reported['valueFormat']['unit']);
        self::assertSame('unit_unverified', $reported['valueFormat']['unitVerification']);
        self::assertSame('ai_interpreted', $reported['evidenceStatus']);
        $chart->description = '';
        $chart->title = 'Survey area (ha)';
        $chart->source_metadata = ['origin' => 'model_unverified', 'reported_unit' => 'm²'];
        $conflict = (new ChartResource($chart))->resolve($request);
        self::assertFalse($conflict['renderEligible']);
        self::assertNull($conflict['valueFormat']);
        $chart->source_metadata = ['origin' => 'model_unverified'];
        $line = $chart->replicate();
        $line->type = 'line';
        $line->title = 'Payments over time (TVD)';
        $line->data = [['label' => '2025-11-20', 'value' => 1245000],
            ['label' => '2026-01-20', 'value' => 11205000]];
        self::assertTrue((new ChartResource($line))->resolve($request)['renderEligible']);
        $pie = $chart->replicate();
        $pie->type = 'pie';
        $pie->title = 'Land-use share (%)';
        $pie->data = [['label' => 'Pasture', 'value' => 40],
            ['label' => 'Woodland', 'value' => 60]];
        self::assertTrue((new ChartResource($pie))->resolve($request)['renderEligible']);
        $chart->data = [['label' => 'Pasture', 'value' => 2.91],
            ['label' => 'Total', 'value' => 4.13]];
        $invalid = (new ChartResource($chart))->resolve($request);
        self::assertFalse($invalid['renderEligible']);
        self::assertStringContainsString('Totals', $invalid['unavailabilityReason']);
    }

    public function test_row_matcher_runs_only_on_explicit_diagnostic_and_cannot_block_rendering(): void
    {
        [, $document] = $this->document();
        $table = 'page:1/table[1]';
        $nodes = [['id' => 'page:1', 'type' => 'page', 'page' => 1],
            ['id' => $table, 'type' => 'table', 'parent' => 'page:1', 'page' => 1,
                'meta' => ['structural_type' => 'basic_relational']]];
        $text = '';
        foreach ([['A', '10'], ['B', '20']] as $r => $values) {
            $row = $table.'/row['.($r + 1).']';
            $nodes[] = ['id' => $row, 'type' => 'row', 'parent' => $table, 'page' => 1];
            foreach ($values as $c => $value) {
                $start = mb_strlen($text);
                $text .= $value;
                $nodes[] = ['id' => $row.'/cell['.$c.']', 'type' => 'cell',
                    'parent' => $row, 'page' => 1, 'text' => $value,
                    'start' => $start, 'end' => mb_strlen($text),
                    'meta' => ['bbox' => [10 + $c * 100, 20 + $r * 20,
                        80 + $c * 100, 30 + $r * 20]]];
                $text .= ' ';
            }
            $text .= "\n";
        }
        $source = new StructuredDocument('PDF', 'smalot-page.v2+poppler-grid.v1+basic-grid.v1',
            $text, $nodes, []);
        $this->mock(StructuredExtractionStore::class,
            fn ($mock) => $mock->shouldReceive('active')->once()->andReturn($source));
        $chart = DocumentChart::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'type' => 'bar', 'title' => 'Values',
            'description' => '', 'data' => [['label' => 'A', 'value' => 11],
                ['label' => 'B', 'value' => 20]],
            'source_metadata' => ['origin' => 'model_unverified']]);
        $request = Request::create('/api/documents/'.$document->id);
        $request->attributes->set('chart_published_document', $document);
        $serialized = (new ChartResource($chart))->resolve($request);
        self::assertTrue($serialized['renderEligible']);
        self::assertSame('ai_interpreted', $serialized['evidenceStatus']);
        self::assertFalse($serialized['valuesVerified']);
        self::assertNull($serialized['unavailabilityReason']);

        self::assertSame(0, Artisan::call('docintel:model-chart-diagnostic',
            ['document' => $document->id, 'chart' => $chart->id]));
        $ordinary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($ordinary['api']['renderEligible']);
        self::assertArrayNotHasKey('row_match', $ordinary);
        self::assertSame(0, Artisan::call('docintel:model-chart-diagnostic',
            ['document' => $document->id, 'chart' => $chart->id, '--match-rows' => true]));
        $diagnostic = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('contradiction', $diagnostic['row_match']['points'][0]['status']);
        self::assertTrue($diagnostic['api']['renderEligible']);
        self::assertSame('ai_interpreted', $diagnostic['api']['evidenceStatus']);
    }

    public function test_four_persisted_model_charts_remain_in_the_chart_relation_and_api_collection(): void
    {
        [, $document] = $this->document();
        foreach (['Receipts', 'Area', 'Budget', 'Payment schedule'] as $title) {
            DocumentChart::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'type' => 'bar',
                'title' => $title, 'description' => '',
                'data' => [['label' => 'First', 'value' => 1], ['label' => 'Second', 'value' => 2]],
                'source_metadata' => ['origin' => 'model_unverified']]);
        }
        $charts = ChartResource::collection($document->charts()->get())
            ->resolve(Request::create('/api/documents/'.$document->id));
        self::assertCount(4, $charts);
        self::assertSame('Payment schedule', $charts[3]['title']);
        self::assertTrue($charts[3]['renderEligible']);
        self::assertSame('ai_interpreted', $charts[3]['evidenceStatus']);
    }

    private function document(): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $document = Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Source.pdf', 'type' => 'PDF', 'status' => 'Ready',
            'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
            'file_hash' => hash('sha256', 'pdf'),
        ]);
        $document->forceFill(['ai_pipeline' => ['key' => str_repeat('a', 64)]])->save();

        return [$user, $document];
    }

    private function chart(array $overrides = []): array
    {
        return array_replace_recursive([
            'type' => 'bar', 'title' => 'Interest payments', 'description' => 'Printed chart',
            'basis' => 'categorical', 'axis' => ['x' => 'Financial year', 'y' => 'Interest payments'],
            'unit' => 'USD million', 'valueStatus' => 'exact', 'data' => [],
            'series' => [
                ['name' => 'Domestic', 'data' => [['label' => 'FY2023', 'value' => 10], ['label' => 'FY2024', 'value' => 12]]],
                ['name' => 'External', 'data' => [['label' => 'FY2023', 'value' => 6], ['label' => 'FY2024', 'value' => 8]]],
            ],
        ], $overrides);
    }

    public function test_explicit_multi_series_category_contract_and_missing_information(): void
    {
        $source = ['page' => 8, 'path' => 'chart-visuals/test', 'sha256' => str_repeat('a', 64),
            'document_hash' => str_repeat('b', 64), 'pipeline_key' => str_repeat('c', 64)];
        $contract = app(SourceChartContract::class);
        $valid = $contract->metadata($this->chart(), $source);
        self::assertTrue($valid['dimensions_verified']);
        self::assertTrue($valid['values_verified']);
        self::assertSame('categorical', $valid['basis']);
        self::assertSame(['Domestic', 'External'], array_column($valid['series'], 'name'));
        self::assertSame(8, $valid['page']);
        self::assertFalse($contract->metadata($this->chart(), ['page' => 8])['values_verified']);

        self::assertFalse($contract->metadata($this->chart(['unit' => null]), $source)['dimensions_verified']);
        self::assertFalse($contract->metadata($this->chart(['unit' => 'unavailable']), $source)['dimensions_verified']);
        self::assertFalse($contract->metadata($this->chart(['unit' => 'USD/KES']), $source)['dimensions_verified']);
        self::assertFalse($contract->metadata($this->chart(['valueStatus' => 'estimated']), $source)['values_verified']);
        $mixed = $contract->metadata($this->chart(['series' => [
            ['name' => 'Domestic', 'unit' => 'USD million', 'data' => [['label' => 'FY2023', 'value' => 10], ['label' => 'FY2024', 'value' => 12]]],
            ['name' => 'External', 'unit' => '%', 'data' => [['label' => 'FY2023', 'value' => 6], ['label' => 'FY2024', 'value' => 8]]],
        ]]), $source);
        self::assertFalse($mixed['dimensions_verified']);
        self::assertNull($mixed['unit']);
        self::assertSame('%', $mixed['series'][1]['unit']);
        self::assertFalse($contract->metadata($this->chart(['series' => [
            ['name' => 'Domestic', 'data' => [['label' => 'FY2023', 'value' => 10], ['label' => 'FY2024', 'value' => 12]]],
            ['name' => 'External', 'data' => [['label' => 'FY2022', 'value' => 6], ['label' => 'FY2024', 'value' => 8]]],
        ]]), $source)['dimensions_verified']);
        $quarterly = $this->chart(['type' => 'line', 'basis' => 'time_series', 'series' => [
            ['name' => 'Internal', 'data' => [['label' => 'Q1', 'value' => 92.3], ['label' => 'Q2', 'value' => 92.3]]],
            ['name' => 'External', 'data' => [['label' => 'Q1', 'value' => 95], ['label' => 'Q2', 'value' => 92.8]]],
        ]]);
        self::assertFalse($contract->metadata($quarterly, $source)['dimensions_verified']);
        $quarterly['series'][0]['data'][0]['label'] = 'Q1 FY2025';
        $quarterly['series'][0]['data'][1]['label'] = 'Q2 FY2025';
        $quarterly['series'][1]['data'][0]['label'] = 'Q1 FY2025';
        $quarterly['series'][1]['data'][1]['label'] = 'Q2 FY2025';
        self::assertTrue($contract->metadata($quarterly, $source)['dimensions_verified']);
        $pie = ['type' => 'pie', 'basis' => 'composition', 'axis' => ['x' => 'Category', 'y' => 'Share'],
            'unit' => '%', 'valueStatus' => 'exact', 'data' => [
                ['label' => 'North', 'value' => 31], ['label' => 'South', 'value' => 30], ['label' => 'East', 'value' => 36],
            ]];
        self::assertFalse($contract->metadata($pie, $source)['dimensions_verified']);
        $pie['data'][2]['value'] = 39;
        self::assertTrue($contract->metadata($pie, $source)['dimensions_verified']);
    }

    public function test_chart_image_requires_document_permission_and_published_revision(): void
    {
        Storage::fake('documents');
        [$owner, $document] = $this->document();
        [$other] = $this->document();
        $key = str_repeat('a', 64);
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $document->file_hash,
            'extracted_text' => 'Chart page', 'extracted_text_hash' => hash('sha256', 'Chart page'),
            'pipeline_key' => $key, 'ai_pipeline' => ['key' => $key],
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 1])->save();
        $bytes = 'image-bytes';
        $sha = hash('sha256', $bytes);
        $path = "chart-visuals/{$document->workspace_id}/{$document->id}/{$revision->id}/{$sha}";
        Storage::disk('documents')->put($path, $bytes);
        $metadata = app(SourceChartContract::class)->metadata($this->chart(), [
            'page' => 8, 'path' => $path, 'sha256' => $sha, 'media_type' => 'image/png',
            'document_hash' => $document->file_hash, 'pipeline_key' => $key,
        ]);
        $chart = DocumentChart::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revision->id, 'type' => 'bar', 'title' => 'Interest payments',
            'description' => '', 'data' => [], 'source_metadata' => $metadata,
        ]);
        $legacy = DocumentChart::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revision->id, 'type' => 'line', 'title' => 'Legacy',
            'description' => '', 'data' => [['label' => 'A', 'value' => 1]],
        ]);

        Sanctum::actingAs($owner);
        $response = $this->getJson("/api/documents/{$document->id}")->assertOk();
        $rows = collect($response->json('data.charts'))->keyBy('id');
        self::assertTrue($rows[$chart->id]['dimensionsVerified']);
        self::assertTrue($rows[$chart->id]['renderEligible']);
        self::assertTrue($rows[$chart->id]['exportSupport']['png']);
        self::assertSame($revision->id, $rows[$chart->id]['sourceRevision']);
        self::assertSame('USD million', $rows[$chart->id]['valueFormat']['unit']);
        self::assertSame('External', $rows[$chart->id]['series'][1]['name']);
        self::assertSame(8, $rows[$chart->id]['sourcePage']);
        $snapshot = $this->getJson("/api/documents/{$document->id}/report-snapshot")->assertOk();
        $snapshotRows = collect($snapshot->json('data.document.charts'))->keyBy('id');
        self::assertSame($rows[$chart->id]['series'], $snapshotRows[$chart->id]['series']);
        self::assertSame($rows[$chart->id]['sourceImageUrl'], $snapshotRows[$chart->id]['sourceImageUrl']);
        self::assertNull($rows[$legacy->id]['sourceImageUrl']);
        self::assertFalse($rows[$legacy->id]['valuesVerified']);
        self::assertFalse($rows[$legacy->id]['renderEligible']);
        self::assertSame([['label' => 'A', 'value' => 1]], $rows[$legacy->id]['data']);
        $imageUrl = "/api/documents/{$document->id}/charts/{$chart->id}/image";
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

        $candidate = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 2, 'state' => 'candidate', 'file_hash' => $document->file_hash,
            'pipeline_key' => str_repeat('d', 64),
        ]);
        $candidateChart = DocumentChart::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $candidate->id, 'type' => 'bar', 'title' => 'Candidate',
            'description' => '', 'data' => [], 'source_metadata' => $metadata,
        ]);
        $this->get("/api/documents/{$document->id}/charts/{$candidateChart->id}/image")->assertNotFound();
        $this->getJson("/api/documents/{$document->id}")->assertJsonCount(2, 'data.charts');

        Sanctum::actingAs($other);
        $this->get($imageUrl)->assertForbidden();
        Sanctum::actingAs($owner);
        Storage::disk('documents')->delete($path);
        $this->get($imageUrl)->assertNotFound();
        $this->getJson("/api/documents/{$document->id}")->assertJsonPath('data.charts.0.sourceImageUrl', null);
        $this->getJson("/api/documents/{$document->id}")->assertJsonPath('data.charts.0.renderEligible', false);
        Storage::disk('documents')->put($path, $bytes);
        $document->forceFill(['file_hash' => hash('sha256', 'changed')])->save();
        $this->get($imageUrl)->assertNotFound();
    }

    public function test_existing_visual_merge_persists_series_without_inventing_flat_points(): void
    {
        Storage::fake('documents');
        [, $document] = $this->document();
        $bytes = 'source-image';
        $sha = hash('sha256', $bytes);
        $path = "chart-visuals/{$document->workspace_id}/{$document->id}/legacy/{$sha}";
        Storage::disk('documents')->put($path, $bytes);
        $job = new AnalyzeEmbeddedVisualsJob($document->id);
        $inserted = $job->mergeCharts($document, [$this->chart()], [
            'page' => 8, 'path' => $path, 'sha256' => $sha, 'media_type' => 'image/png',
            'document_hash' => $document->file_hash, 'pipeline_key' => str_repeat('a', 64),
        ]);
        self::assertSame(1, $inserted);
        $chart = DocumentChart::where('document_id', $document->id)->sole();
        self::assertSame([], $chart->data);
        self::assertSame('External', $chart->source_metadata['series'][1]['name']);
        self::assertSame(0, $chart->points()->count());
    }

    public function test_normal_revision_without_explicit_pipeline_key_uses_file_bound_visual_identity(): void
    {
        Storage::fake('documents');
        [, $document] = $this->document();
        $document->forceFill(['ai_pipeline' => ['route' => 'normal']])->save();
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $document->file_hash,
            'extracted_text' => 'Chart page', 'extracted_text_hash' => hash('sha256', 'Chart page'),
            'ai_pipeline' => ['route' => 'normal'],
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 1])->save();
        $key = hash('sha256', $document->workspace_id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
        $bytes = 'visual';
        $sha = hash('sha256', $bytes);
        $path = "chart-visuals/{$document->workspace_id}/{$document->id}/{$revision->id}/{$sha}";
        Storage::disk('documents')->put($path, $bytes);
        $chart = DocumentChart::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revision->id, 'type' => 'bar', 'title' => 'Chart',
            'description' => '', 'data' => [],
            'source_metadata' => app(SourceChartContract::class)->metadata($this->chart(), [
                'path' => $path, 'sha256' => $sha, 'document_hash' => $document->file_hash,
                'pipeline_key' => $key, 'media_type' => 'image/png',
            ]),
        ]);
        $view = app(DocumentIntelligenceService::class)->publishedView($document->fresh());
        self::assertSame($path, app(SourceChartAccess::class)->path($view, $chart));
    }

    public function test_image_access_tracks_membership_account_status_and_soft_deletion(): void
    {
        Storage::fake('documents');
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Chart security']);
        $user = User::factory()->create(['role' => 'Viewer', 'current_workspace_id' => $workspace->id]);
        $member = WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'Viewer', 'joined_at' => now()]);
        $document = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'name' => 'Chart.pdf', 'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Internal',
            'size_kb' => 1, 'year' => 2026, 'file_hash' => hash('sha256', 'chart-pdf')]);
        $key = hash('sha256', $workspace->id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
        $bytes = 'retained-image';
        $sha = hash('sha256', $bytes);
        $path = "chart-visuals/{$workspace->id}/{$document->id}/legacy/{$sha}";
        Storage::disk('documents')->put($path, $bytes);
        $chart = DocumentChart::create(['document_id' => $document->id, 'workspace_id' => $workspace->id,
            'type' => 'bar', 'title' => 'Source', 'description' => '', 'data' => [],
            'source_metadata' => app(SourceChartContract::class)->metadata($this->chart(), [
                'path' => $path, 'sha256' => $sha, 'media_type' => 'image/png',
                'document_hash' => $document->file_hash, 'pipeline_key' => $key,
            ])]);
        $url = "/api/documents/{$document->id}/charts/{$chart->id}/image";
        $this->get($url)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->get($url)->assertOk();
        $document->forceFill(['classification' => 'Restricted'])->save();
        $this->get($url)->assertForbidden();
        $document->forceFill(['classification' => 'Internal'])->save();
        $otherWorkspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Other tenant']);
        $outsider = User::factory()->create(['role' => 'Administrator', 'current_workspace_id' => $otherWorkspace->id]);
        WorkspaceMember::create(['workspace_id' => $otherWorkspace->id, 'user_id' => $outsider->id,
            'role' => 'Administrator', 'joined_at' => now()]);
        Sanctum::actingAs($outsider);
        $this->get($url)->assertForbidden();
        Sanctum::actingAs($user);
        $member->delete();
        $this->get($url)->assertForbidden();
        $this->getJson('/api/documents')->assertForbidden();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'Viewer', 'joined_at' => now()]);
        $user->forceFill(['active' => false])->save();
        Sanctum::actingAs($user->fresh());
        $this->get($url)->assertForbidden();
        $user->forceFill(['active' => true])->save();
        Sanctum::actingAs($user->fresh());
        $document->delete();
        $this->get($url)->assertNotFound();
        Storage::disk('documents')->assertExists($path);
    }

    public function test_permanent_deletion_purges_only_the_owned_chart_image_prefix(): void
    {
        Storage::fake('documents');
        Queue::fake();
        [, $document] = $this->document();
        $owned = "chart-visuals/{$document->workspace_id}/{$document->id}/legacy/image";
        $other = "chart-visuals/{$document->workspace_id}/{$document->id}-other/legacy/image";
        $otherWorkspace = 'chart-visuals/'.Str::uuid()."/{$document->id}/legacy/image";
        Storage::disk('documents')->put($owned, 'owned');
        Storage::disk('documents')->put($other, 'other');
        Storage::disk('documents')->put($otherWorkspace, 'other workspace');
        $job = new PurgeDocumentChartImagesJob($document->workspace_id, $document->id);
        $job->handle();
        Storage::disk('documents')->assertExists($owned);
        DB::transaction(function () use ($document): void {
            $document->forceDelete();
            Queue::assertNotPushed(PurgeDocumentChartImagesJob::class);
        });
        Queue::assertPushed(PurgeDocumentChartImagesJob::class);
        $job->handle();
        Storage::disk('documents')->assertMissing($owned);
        Storage::disk('documents')->assertExists($other);
        Storage::disk('documents')->assertExists($otherWorkspace);
    }
}
