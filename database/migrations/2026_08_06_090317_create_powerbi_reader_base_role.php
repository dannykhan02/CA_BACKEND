<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent creation of the NOLOGIN group role that per-workspace Power BI
 * LOGIN roles inherit via GRANT powerbi_reader TO powerbi_reader_<slug>.
 * Required before restrict_powerbi_reader_grants and add_rls migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            DO $$
            BEGIN
                CREATE ROLE powerbi_reader NOLOGIN;
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END
            $$
        ');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The later grant-hardening migration intentionally does not restore its old,
        // over-broad grants on rollback. Revoke the role's remaining narrow grants
        // before dropping it; PostgreSQL will not drop a role with ACL dependencies.
        if (! DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', ['powerbi_reader'])) {
            return;
        }
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM powerbi_reader');
        DB::statement('REVOKE ALL PRIVILEGES ON SCHEMA public FROM powerbi_reader');
        DB::statement('DROP ROLE IF EXISTS powerbi_reader');
    }
};
