<?php

namespace App\Console\Commands;

use App\Models\PowerBiCredential;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisions one Postgres LOGIN role per Power BI reporting consumer,
 * scoped to exactly one workspace via RLS (see
 * 2026_08_19_081447_add_rls_to_powerbi_views.php).
 *
 * Deliberately NOT a migration — creating a new client's credential is an
 * operational action taken on demand, not a one-time schema change, and
 * generates a secret that must never be committed to a migration file.
 *
 * Usage:
 *   php artisan powerbi:create-reader {workspace-uuid} --label="CA main dashboard"
 */
class CreatePowerBiReader extends Command
{
    protected $signature = 'powerbi:create-reader
        {workspace : UUID of the workspace this credential is scoped to}
        {--label= : Human-readable label, e.g. client/report name}';

    protected $description = 'Create a new Postgres role for Power BI, scoped to one workspace via RLS';

    public function handle(): int
    {
        $workspace = Workspace::find($this->argument('workspace'));

        if (! $workspace) {
            $this->error('No workspace found with that UUID.');
            return self::FAILURE;
        }

        $slug = Str::slug($workspace->name ?: $workspace->id, '_');
        $dbRole = 'powerbi_reader_' . $slug;

        if (PowerBiCredential::where('db_role', $dbRole)->whereNull('revoked_at')->exists()) {
            $this->error("An active credential already exists for role \"{$dbRole}\". Revoke it first if you need to rotate.");
            return self::FAILURE;
        }

        // Str::random() is alnum-only by default — safe to embed directly
        // in a single-quoted SQL literal without escaping concerns.
        $password = Str::random(40);

        // $dbRole is derived via Str::slug (alnum + underscore only), safe
        // to interpolate as a quoted identifier. $password contains no
        // quote characters. Neither is user-supplied free text.
        DB::statement("CREATE ROLE \"{$dbRole}\" LOGIN PASSWORD '{$password}'");
        DB::statement("GRANT powerbi_reader TO \"{$dbRole}\"");

        PowerBiCredential::create([
            'db_role' => $dbRole,
            'workspace_id' => $workspace->id,
            'label' => $this->option('label'),
        ]);

        $this->info("Created Power BI reader role: {$dbRole}");
        $this->warn('Password (shown once, not stored anywhere — save it now):');
        $this->line($password);
        $this->newLine();
        $this->info('This role can SELECT only rows where workspace_id = ' . $workspace->id . ' (enforced via RLS).');
        $this->info('Give this to the Power BI Desktop/Gateway Postgres connection for this client, not a human user.');

        return self::SUCCESS;
    }
}