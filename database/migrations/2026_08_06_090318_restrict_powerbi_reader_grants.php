<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security fix — powerbi_reader was granted SELECT on all 27 tables
 * (likely via a blanket `GRANT SELECT ON ALL TABLES IN SCHEMA public`
 * run once outside migrations), including personal_access_tokens,
 * audit_logs, and the raw, unfiltered documents/document_kpis tables.
 * This bypassed the entire design intent of power_bi_kpis / power_bi_
 * chart_points — the two views that already correctly exclude Restricted
 * classification and non-Ready documents. Scoping the role down to
 * exactly those two views, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM powerbi_reader');
        DB::statement('REVOKE ALL PRIVILEGES ON SCHEMA public FROM powerbi_reader');

        DB::statement('GRANT USAGE ON SCHEMA public TO powerbi_reader');
        DB::statement('GRANT SELECT ON power_bi_kpis TO powerbi_reader');
        DB::statement('GRANT SELECT ON power_bi_chart_points TO powerbi_reader');

        // Prevents a future `CREATE TABLE` from silently re-granting
        // powerbi_reader access via inherited default privileges — this is
        // the most likely mechanism behind how the original over-grant
        // happened in the first place.
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM powerbi_reader');
    }

    public function down(): void
    {
        // Deliberately not restoring the broad grant — that was the
        // vulnerability. Re-provisioning access, if ever needed, should be
        // a new, deliberate migration, not an automatic rollback of a fix.
    }
};