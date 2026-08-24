<?php

namespace App\Console\Commands;

use App\Models\PowerBiCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a Power BI Postgres credential: marks the mapping row revoked
 * (so RLS immediately returns zero rows) and drops the LOGIN role.
 *
 * Usage:
 *   php artisan powerbi:revoke-reader powerbi_reader_ca_main_dashboard
 *
 * To rotate, revoke the old role then run powerbi:create-reader again.
 */
class RevokePowerBiReader extends Command
{
    protected $signature = 'powerbi:revoke-reader
        {role : Postgres role name, e.g. powerbi_reader_ca_main_dashboard}';

    protected $description = 'Revoke a Power BI reader credential and drop its Postgres role';

    public function handle(): int
    {
        $dbRole = $this->argument('role');

        if (! preg_match('/^powerbi_reader_[a-z0-9_]+$/', $dbRole)) {
            $this->error('Role name must match powerbi_reader_<slug> (lowercase alnum and underscores).');
            return self::FAILURE;
        }

        $credential = PowerBiCredential::where('db_role', $dbRole)->first();

        if (! $credential) {
            $this->error("No credential record found for role \"{$dbRole}\".");
            return self::FAILURE;
        }

        if ($credential->revoked_at !== null) {
            $this->warn("Credential for \"{$dbRole}\" was already revoked at {$credential->revoked_at}.");
        } else {
            $credential->update(['revoked_at' => now()]);
            $this->info("Marked credential revoked in powerbi_credentials.");
        }

        DB::statement("DROP ROLE IF EXISTS \"{$dbRole}\"");
        $this->info("Dropped Postgres role \"{$dbRole}\".");

        $this->newLine();
        $this->info('RLS will deny all rows for this role immediately. Create a replacement with:');
        $this->line("  php artisan powerbi:create-reader {$credential->workspace_id}");

        return self::SUCCESS;
    }
}
