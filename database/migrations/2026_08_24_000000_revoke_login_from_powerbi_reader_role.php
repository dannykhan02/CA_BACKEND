<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security fix — pg_roles showed powerbi_reader (the shared NOLOGIN group
 * role that per-workspace reader roles are supposed to inherit from) had
 * rolcanlogin = true, meaning it was directly connectable with a standing
 * password outside of the powerbi_credentials tracking table entirely.
 *
 * RLS policies happen to fail closed for this role today (no matching row
 * in powerbi_credentials => no rows returned), and views are set with
 * security_invoker = true so that protection carries through — but safety
 * should not depend on that alone. This migration makes powerbi_reader
 * NOLOGIN, matching what every later migration in this chain assumes, and
 * strips any password so the old credential can't be reused if it's ever
 * mistakenly re-enabled.
 *
 * Before deploying: confirm nothing (env vars, CI secrets, saved Power BI
 * Desktop connections) connects using the bare `powerbi_reader` username
 * directly rather than a per-workspace powerbi_reader_<slug> login.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER ROLE powerbi_reader NOLOGIN');
        DB::statement('ALTER ROLE powerbi_reader PASSWORD NULL');
    }

    public function down(): void
    {
        // Deliberately not restoring LOGIN or a password — if direct login
        // on the shared group role is ever genuinely needed, that should be
        // a new, deliberate migration with a reasoned justification, not an
        // automatic rollback of a fix.
    }
};
