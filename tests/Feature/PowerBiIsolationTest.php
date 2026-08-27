<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentKpi;
use App\Models\PowerBiCredential;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Verifies the Power BI Row-Level Security introduced in
 * 2026_08_19_081447_add_rls_to_powerbi_views.php actually isolates
 * workspaces from each other when queried as a real Postgres reader
 * role — not just when queried through Laravel's own connection.
 *
 * Deliberately does NOT rely on Eloquent factories (none exist yet for
 * these models) or query the views through the app's own DB
 * connection: that connection owns these tables and therefore bypasses
 * RLS entirely, exactly like neondb_owner does in production. The only
 * way to actually exercise the policy is a second, independent PDO
 * connection authenticated as the real powerbi_reader_<slug> role.
 */
class PowerBiIsolationTest extends TestCase
{
    // Deliberately NOT using DatabaseTransactions/RefreshDatabase here.
    // CREATE ROLE is transactional DDL — wrapping the test in an open,
    // uncommitted transaction means a second, independent PDO connection
    // can never see the role at all, which Postgres reports as a plain
    // "password authentication failed" rather than "role does not
    // exist" (deliberately, to avoid leaking valid usernames). Cleanup
    // is done explicitly in tearDown() instead of relying on rollback.

    private Workspace $workspaceA;
    private Workspace $workspaceB;

    private string $roleA;
    private string $roleB;
    private string $passwordA;
    private string $passwordB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceA = Workspace::create([
            'type' => 'Organization',
            'name' => 'RLS Test Workspace A',
        ]);

        $this->workspaceB = Workspace::create([
            'type' => 'Organization',
            'name' => 'RLS Test Workspace B',
        ]);

        [$this->roleA, $this->passwordA] = $this->createReaderRole($this->workspaceA);
        [$this->roleB, $this->passwordB] = $this->createReaderRole($this->workspaceB);
    }

    protected function tearDown(): void
    {
        // Explicit cleanup, since we're not relying on a transaction
        // rollback. Delete child rows before parents to respect FKs,
        // scoped by workspace_id — these two workspace UUIDs are unique
        // to this test run, so this can't touch unrelated data.
        $workspaceIds = array_filter([
            $this->workspaceA->id ?? null,
            $this->workspaceB->id ?? null,
        ]);

        if ($workspaceIds) {
            DocumentChartPoint::whereIn('workspace_id', $workspaceIds)->delete();
            DocumentChart::whereIn('workspace_id', $workspaceIds)->delete();
            DocumentKpi::whereIn('workspace_id', $workspaceIds)->delete();
            Document::whereIn('workspace_id', $workspaceIds)->delete();
            PowerBiCredential::whereIn('workspace_id', $workspaceIds)->delete();
            Workspace::whereIn('id', $workspaceIds)->delete();
        }

        foreach (array_filter([$this->roleA ?? null, $this->roleB ?? null]) as $role) {
            DB::statement("DROP ROLE IF EXISTS \"{$role}\"");
        }

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} [dbRole, password] */
    private function createReaderRole(Workspace $workspace): array
    {
        // Str::random() is alnum-only by default, same justification
        // CreatePowerBiReader itself uses for safe direct interpolation.
        $dbRole = 'powerbi_reader_test_' . Str::lower(Str::random(8));
        $password = Str::random(40);

        DB::statement("CREATE ROLE \"{$dbRole}\" LOGIN PASSWORD '{$password}'");
        DB::statement("GRANT powerbi_reader TO \"{$dbRole}\"");

        PowerBiCredential::create([
            'db_role' => $dbRole,
            'workspace_id' => $workspace->id,
            'label' => 'automated isolation test',
        ]);

        return [$dbRole, $password];
    }

    private function seedReadyDocument(Workspace $workspace, string $namePrefix): Document
    {
        $document = Document::create([
            'name' => "{$namePrefix} document",
            'type' => 'PDF',
            'size_kb' => 100,
            'status' => 'Ready',
            'classification' => 'Internal',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'power_bi_status' => 'synced',
        ]);

        DocumentKpi::create([
            'document_id' => $document->id,
            'workspace_id' => $workspace->id,
            'label' => 'Revenue',
            'value' => '1.2M',
            'value_numeric' => 1200000,
        ]);

        $chart = DocumentChart::create([
            'document_id' => $document->id,
            'workspace_id' => $workspace->id,
            'type' => 'bar',
            'title' => 'Revenue by quarter',
            'description' => 'Test fixture chart for RLS isolation test',
            'data' => [],
        ]);

        DocumentChartPoint::create([
            'document_chart_id' => $chart->id,
            'workspace_id' => $workspace->id,
            'label' => 'Q1',
            'value' => 100,
            'sort_order' => 1,
        ]);

        return $document;
    }

    /** Opens a fresh PDO connection authenticated as the given Postgres role. */
    private function connectAs(string $role, string $password): PDO
    {
        $connection = config('database.default');
        $host = config("database.connections.$connection.host");
        $port = config("database.connections.$connection.port");
        $database = config("database.connections.$connection.database");
        $sslmode = config("database.connections.$connection.sslmode") ?: 'prefer';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";

        return new PDO($dsn, $role, $password);
    }

    public function test_kpi_view_only_shows_own_workspace(): void
    {
        $docA = $this->seedReadyDocument($this->workspaceA, 'A');
        $docB = $this->seedReadyDocument($this->workspaceB, 'B');

        $pdoA = $this->connectAs($this->roleA, $this->passwordA);
        $rowsSeenByA = $pdoA->query('SELECT document_id FROM power_bi_kpis')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains($docA->id, $rowsSeenByA);
        $this->assertNotContains($docB->id, $rowsSeenByA);
    }

    public function test_chart_points_view_only_shows_own_workspace(): void
    {
        $docA = $this->seedReadyDocument($this->workspaceA, 'A');
        $docB = $this->seedReadyDocument($this->workspaceB, 'B');

        $pdoB = $this->connectAs($this->roleB, $this->passwordB);
        $rowsSeenByB = $pdoB->query('SELECT document_id FROM power_bi_chart_points')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains($docB->id, $rowsSeenByB);
        $this->assertNotContains($docA->id, $rowsSeenByB);
    }

    public function test_revoked_reader_sees_zero_rows(): void
    {
        $this->seedReadyDocument($this->workspaceA, 'A');

        PowerBiCredential::where('db_role', $this->roleA)->update(['revoked_at' => now()]);

        $pdoA = $this->connectAs($this->roleA, $this->passwordA);
        $rows = $pdoA->query('SELECT document_id FROM power_bi_kpis')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $rows);
    }

    public function test_restricted_document_excluded_even_for_own_workspace(): void
    {
        $document = $this->seedReadyDocument($this->workspaceA, 'A');
        $document->update(['classification' => 'Restricted']);

        $pdoA = $this->connectAs($this->roleA, $this->passwordA);
        $rows = $pdoA->query('SELECT document_id FROM power_bi_kpis')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($document->id, $rows);
    }

    public function test_unmapped_reader_sees_zero_rows(): void
    {
        // Distinct from test_revoked_reader_sees_zero_rows: this role is a
        // genuine member of the powerbi_reader group (so it passes GRANT-level
        // checks) but has NO row in powerbi_credentials at all — never
        // provisioned via powerbi:create-reader, not merely revoked. RLS must
        // deny it independently of the revoked_at path, not by coincidence of
        // the same policy catching both cases.
        $this->seedReadyDocument($this->workspaceA, 'A');

        $dbRole = 'powerbi_reader_test_unmapped_' . Str::lower(Str::random(8));
        $password = Str::random(40);

        DB::statement("CREATE ROLE \"{$dbRole}\" LOGIN PASSWORD '{$password}'");
        DB::statement("GRANT powerbi_reader TO \"{$dbRole}\"");

        try {
            $pdo = $this->connectAs($dbRole, $password);
            $rows = $pdo->query('SELECT document_id FROM power_bi_kpis')->fetchAll(PDO::FETCH_COLUMN);

            $this->assertCount(0, $rows);
        } finally {
            DB::statement("DROP ROLE IF EXISTS \"{$dbRole}\"");
        }
    }

    public function test_non_ready_document_excluded_even_for_own_workspace(): void
    {
        $document = $this->seedReadyDocument($this->workspaceA, 'A');
        $document->update(['status' => 'Needs Review']);

        $pdoA = $this->connectAs($this->roleA, $this->passwordA);
        $rows = $pdoA->query('SELECT document_id FROM power_bi_kpis')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($document->id, $rows);
    }
}