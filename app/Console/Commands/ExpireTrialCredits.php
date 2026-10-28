<?php

namespace App\Console\Commands;

use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use Illuminate\Console\Command;

/**
 * Writes off trial credits whose expiry has passed. Availability already treats an expired trial as 0 on read; this keeps the
 * stored pocket and the ledger honest. Dry run unless --apply; one ledger row per workspace and expiry, so a rerun is harmless.
 */
class ExpireTrialCredits extends Command
{
    protected $signature = 'credits:expire-trials {--apply : Write the expiry (otherwise only list what would expire)}';

    protected $description = 'Expire unused trial credits whose 30-day (configured) window has passed; dry run unless --apply';

    public function handle(CreditAccountant $accountant): int
    {
        $apply = (bool) $this->option('apply');
        $due = WorkspaceCredit::where('trial_credits_remaining', '>', 0)->whereNotNull('trial_credits_expires_at')
            ->where('trial_credits_expires_at', '<=', now())->orderBy('trial_credits_expires_at')->get();
        $total = 0;
        foreach ($due as $row) {
            $balance = $accountant->trialBalance($row->workspace_id);
            $credits = $apply ? $accountant->expireTrial($row->workspace_id) : max(0, $balance['remaining'] - $balance['held']);
            $total += $credits;
            $this->line(sprintf('%s workspace %s: %d trial credits (expired %s)', $apply ? 'Expired' : 'Would expire', $row->workspace_id, $credits,
                $balance['expires_at']?->toDateTimeString()));
        }
        $this->info(sprintf('%d workspace(s), %d credits. %s', $due->count(), $total, $apply ? 'Applied.' : 'Dry run: nothing was changed. Use --apply.'));

        return self::SUCCESS;
    }
}
