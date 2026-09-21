<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditBillingPayments extends Command
{
    protected $signature = 'billing:audit-payments';

    protected $description = 'Read-only payment counts by status and Paystack live/test mode; no customer details';

    public function handle(): int
    {
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION READ ONLY');
            $rows = DB::select("SELECT status, COALESCE(paystack_response->'data'->>'domain', 'unknown') AS provider_mode,
                COUNT(*) AS payments, COUNT(DISTINCT user_id) AS purchasers, COUNT(DISTINCT workspace_id) AS workspaces
                FROM credit_purchases GROUP BY status, COALESCE(paystack_response->'data'->>'domain', 'unknown') ORDER BY status, provider_mode");
            $this->table(['Status', 'Provider mode', 'Payments', 'Purchasers', 'Workspaces'], array_map(fn ($row) => (array) $row, $rows));
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }
}
