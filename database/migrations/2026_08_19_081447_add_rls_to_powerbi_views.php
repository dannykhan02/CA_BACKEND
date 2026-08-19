<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the confirmed Day 10 Power BI gap: power_bi_kpis / power_bi_
 * chart_points already exclude Restricted classification and non-Ready
 * documents, but had no workspace_id boundary at all, even though every
 * table they join now carries workspace_id (see
 * 2026_08_04_130000_add_workspace_id_to_document_children.php).
 *
 * Chosen approach (Option B, confirmed): enforce the boundary at the
 * database layer via Row-Level Security, keyed off a per-role mapping in
 * powerbi_credentials — NOT one view per workspace. Each Power BI
 * reporting credential is its own Postgres LOGIN role; RLS restricts that
 * role to only the workspace_id it's mapped to in powerbi_credentials.
 * A role with no mapping row sees zero rows.
 *
 * IMPORTANT — this requires switching power_bi_kpis / power_bi_chart_points
 * to security_invoker views (PG15+; confirmed available on PG 18.4 here).
 * Non-invoker views run as their OWNER when reading underlying tables,
 * which is how powerbi_reader could read these views with zero direct
 * table grants — but it also means RLS keyed on current_user would
 * silently evaluate against the view owner, not the actual querying role,
 * and do nothing. security_invoker=true makes both the permission check
 * and the RLS policy evaluate against the real caller.
 *
 * neondb_owner (the app's own migration/query role, and the owner of
 * these tables) bypasses RLS by default as table owner — this does NOT
 * restrict the Laravel application's own queries in any way. Only
 * non-owner roles (powerbi_reader and its per-workspace children) are
 * affected.
 */
return new class extends Migration
{
    private array $rlsTables = [
        'documents',
        'document_kpis',
        'document_charts',
        'document_chart_points',
    ];

    public function up(): void
    {
        // 1. Enable RLS on the four base tables the two views read from.
        foreach ($this->rlsTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        }

        // 2. Fail-closed policy: a role only sees rows matching the
        // workspace_id it's mapped to in powerbi_credentials. No mapping
        // row => the subquery returns NULL => the equality is never true
        // => zero rows, for any role this policy applies to.
        foreach ($this->rlsTables as $table) {
            DB::statement("
                CREATE POLICY powerbi_workspace_scope ON {$table}
                FOR SELECT TO public
                USING (
                    workspace_id = (
                        SELECT workspace_id FROM powerbi_credentials
                        WHERE db_role = current_user AND revoked_at IS NULL
                    )
                )
            ");
        }

        // 3. security_invoker views now need the invoking role to hold its
        // own SELECT grant on the underlying tables — the view no longer
        // borrows the owner's access. Grant to the existing powerbi_reader
        // group role; per-workspace roles inherit this via
        // `GRANT powerbi_reader TO powerbi_reader_<slug>` at creation time
        // (see CreatePowerBiReader command). RLS above is what actually
        // narrows what these grants can see.
        foreach ($this->rlsTables as $table) {
            DB::statement("GRANT SELECT ON {$table} TO powerbi_reader");
        }

        // 3b. The RLS policy above reads powerbi_credentials to resolve
        // current_user's own workspace_id. Under security_invoker the
        // invoking role needs its own grant to read that table too — but a
        // blanket SELECT would let any reader role see every client's
        // db_role/workspace_id mapping, not just its own. Scope it with
        // RLS on powerbi_credentials itself: a role may only see the row
        // where db_role = current_user.
        DB::statement("ALTER TABLE powerbi_credentials ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY powerbi_credentials_self ON powerbi_credentials
            FOR SELECT TO public
            USING (db_role = current_user)
        ");
        DB::statement("GRANT SELECT ON powerbi_credentials TO powerbi_reader");

        // 4. Flip both views to security_invoker so RLS + the grants above
        // actually apply to the querying role instead of the view owner.
        DB::statement('ALTER VIEW power_bi_kpis SET (security_invoker = true)');
        DB::statement('ALTER VIEW power_bi_chart_points SET (security_invoker = true)');
    }

    public function down(): void
    {
        DB::statement('ALTER VIEW power_bi_kpis SET (security_invoker = false)');
        DB::statement('ALTER VIEW power_bi_chart_points SET (security_invoker = false)');

        DB::statement("REVOKE SELECT ON powerbi_credentials FROM powerbi_reader");
        DB::statement("DROP POLICY IF EXISTS powerbi_credentials_self ON powerbi_credentials");
        DB::statement("ALTER TABLE powerbi_credentials DISABLE ROW LEVEL SECURITY");

        foreach ($this->rlsTables as $table) {
            DB::statement("REVOKE SELECT ON {$table} FROM powerbi_reader");
            DB::statement("DROP POLICY IF EXISTS powerbi_workspace_scope ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};