<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentKpi;
use App\Models\PowerBiCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PowerBiRlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Power BI RLS tests require PostgreSQL.');
        }
    }

    public function test_power_bi_views_are_scoped_to_credential_workspace(): void
    {
        $user = User::factory()->create();

        $workspaceA = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Workspace A']);
        $workspaceB = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Workspace B']);

        $docA = $this->createReadyDocument($workspaceA->id, $user->id, 'Doc A KPI');
        $docB = $this->createReadyDocument($workspaceB->id, $user->id, 'Doc B KPI');

        $this->createReaderRole('powerbi_reader_a', $workspaceA->id);
        $this->createReaderRole('powerbi_reader_b', $workspaceB->id);

        $rowsA = $this->selectAsRole('powerbi_reader_a', 'SELECT label FROM power_bi_kpis ORDER BY label');
        $rowsB = $this->selectAsRole('powerbi_reader_b', 'SELECT label FROM power_bi_kpis ORDER BY label');

        $this->assertSame(['Doc A KPI'], array_column($rowsA, 'label'));
        $this->assertSame(['Doc B KPI'], array_column($rowsB, 'label'));
    }

    public function test_role_without_credential_mapping_sees_no_rows(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Orphan']);

        $this->createReadyDocument($workspace->id, $user->id, 'Hidden KPI');

        DB::statement("CREATE ROLE powerbi_reader_orphan LOGIN PASSWORD 'test'");
        DB::statement('GRANT powerbi_reader TO powerbi_reader_orphan');

        $rows = $this->selectAsRole('powerbi_reader_orphan', 'SELECT label FROM power_bi_kpis');

        $this->assertSame([], $rows);

        DB::statement('DROP ROLE IF EXISTS powerbi_reader_orphan');
    }

    public function test_chart_points_view_respects_workspace_rls(): void
    {
        $user = User::factory()->create();

        $workspaceA = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Charts A']);
        $workspaceB = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Charts B']);

        $this->createReadyDocument($workspaceA->id, $user->id, 'KPI A', 'Point A');
        $this->createReadyDocument($workspaceB->id, $user->id, 'KPI B', 'Point B');

        $this->createReaderRole('powerbi_reader_charts_a', $workspaceA->id);

        $rows = $this->selectAsRole(
            'powerbi_reader_charts_a',
            'SELECT label FROM power_bi_chart_points ORDER BY label'
        );

        $this->assertSame(['Point A'], array_column($rows, 'label'));
        $this->assertNotContains('Point B', array_column($rows, 'label'));
    }

    private function createReadyDocument(string $workspaceId, string $userId, string $kpiLabel, ?string $chartPointLabel = null): Document
    {
        $document = Document::create([
            'name' => 'Test Document',
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspaceId,
            'uploaded_by' => $userId,
            'last_updated_by' => $userId,
            'power_bi_status' => 'synced',
        ]);

        DocumentKpi::create([
            'workspace_id' => $workspaceId,
            'document_id' => $document->id,
            'label' => $kpiLabel,
            'value' => '100',
            'value_numeric' => 100,
        ]);

        if ($chartPointLabel !== null) {
            $chart = DocumentChart::create([
                'workspace_id' => $workspaceId,
                'document_id' => $document->id,
                'type' => 'bar',
                'title' => 'Chart',
                'data' => [['label' => $chartPointLabel, 'value' => 42]],
            ]);

            DocumentChartPoint::create([
                'document_chart_id' => $chart->id,
                'workspace_id' => $workspaceId,
                'label' => $chartPointLabel,
                'value' => 42,
                'sort_order' => 0,
            ]);
        }

        return $document;
    }

    private function createReaderRole(string $role, string $workspaceId): void
    {
        DB::statement("CREATE ROLE \"{$role}\" LOGIN PASSWORD 'test'");
        DB::statement("GRANT powerbi_reader TO \"{$role}\"");

        PowerBiCredential::create([
            'db_role' => $role,
            'workspace_id' => $workspaceId,
        ]);
    }

    /** @return array<int, object> */
    private function selectAsRole(string $role, string $sql): array
    {
        DB::statement("SET ROLE \"{$role}\"");

        try {
            return DB::select($sql);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['powerbi_reader_a', 'powerbi_reader_b', 'powerbi_reader_charts_a'] as $role) {
                DB::statement("DROP ROLE IF EXISTS \"{$role}\"");
            }
        }

        parent::tearDown();
    }
}
