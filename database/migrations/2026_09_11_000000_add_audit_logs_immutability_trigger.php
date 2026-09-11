<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Track 2 F3: audit_logs rows were previously immutable only by app-layer
 * convention ($timestamps = false on the AuditLog model, plus the fact
 * that no update/delete route exists for this table). Nothing at the
 * database layer actually stopped a future direct
 * DB::table('audit_logs')->update(...) / ->delete(...), a careless
 * migration, or any other code path with write access from silently
 * altering the trail.
 *
 * Approach: a BEFORE UPDATE OR DELETE trigger that unconditionally raises.
 * Chosen over a plain REVOKE UPDATE/DELETE because REVOKE has no effect
 * against the table OWNER (Postgres owners bypass their own grants by
 * default), and this app's migration/query role may well be the owner —
 * that was never confirmed this session. A trigger fires for ANY caller,
 * including the owner, so it does not depend on which role the app
 * actually connects as.
 *
 * INSERT and SELECT are untouched — the app still needs to write new
 * rows (AuditLogger::log()) and read them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'audit_logs rows are immutable — % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER audit_logs_immutable
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_log_mutation()');
    }
};
