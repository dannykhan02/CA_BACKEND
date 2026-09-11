<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Track 2 F3, follow-up: the original unconditional trigger
 * (add_audit_logs_immutability_trigger) blocked the legitimate FK cascade
 * from audit_logs.workspace_id's nullOnDelete() — the same convention used
 * by audit_logs.user_id, documents.uploaded_by, documents.workspace_id,
 * etc. throughout this schema. No restrictOnDelete precedent exists
 * anywhere in this codebase, and introducing one here would make any
 * workspace with audit history permanently undeletable — a product
 * decision nobody made, as a side effect of an immutability fix.
 *
 * Narrow the trigger to permit ONLY the specific FK-nullification shape:
 * workspace_id going from NOT NULL to NULL, with every other column
 * (user_id, action, auditable_type, auditable_id, metadata, created_at)
 * unchanged. Genuine tampering — changing the actor, the action, the
 * subject, the metadata, or the timestamp — is still rejected
 * unconditionally, on UPDATE or DELETE alike.
 *
 * metadata is a json column (not jsonb) — Postgres has no equality
 * operator for bare json, so it's compared via ::text cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND OLD.workspace_id IS NOT NULL
                    AND NEW.workspace_id IS NULL
                    AND NEW.user_id IS NOT DISTINCT FROM OLD.user_id
                    AND NEW.action IS NOT DISTINCT FROM OLD.action
                    AND NEW.auditable_type IS NOT DISTINCT FROM OLD.auditable_type
                    AND NEW.auditable_id IS NOT DISTINCT FROM OLD.auditable_id
                    AND NEW.metadata::text IS NOT DISTINCT FROM OLD.metadata::text
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'audit_logs rows are immutable — % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
    }

    public function down(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'audit_logs rows are immutable — % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
    }
};
