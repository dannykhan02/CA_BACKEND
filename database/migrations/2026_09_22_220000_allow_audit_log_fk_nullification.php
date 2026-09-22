<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The previous migration is recorded as applied in production, but
        // its installed function still compares json values without a cast.
        // It also permits workspace FK nullification but not user FK
        // nullification, despite both foreign keys using ON DELETE SET NULL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND (
                        (OLD.user_id IS NOT NULL AND NEW.user_id IS NULL)
                        OR (OLD.workspace_id IS NOT NULL AND NEW.workspace_id IS NULL)
                    )
                    AND (
                        NEW.user_id IS NOT DISTINCT FROM OLD.user_id
                        OR (OLD.user_id IS NOT NULL AND NEW.user_id IS NULL)
                    )
                    AND (
                        NEW.workspace_id IS NOT DISTINCT FROM OLD.workspace_id
                        OR (OLD.workspace_id IS NOT NULL AND NEW.workspace_id IS NULL)
                    )
                    AND (to_jsonb(NEW) - 'user_id' - 'workspace_id')
                        = (to_jsonb(OLD) - 'user_id' - 'workspace_id')
                    AND NEW.metadata::text IS NOT DISTINCT FROM OLD.metadata::text
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'audit_logs rows are immutable — % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
            RETURNS trigger AS $$
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
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
