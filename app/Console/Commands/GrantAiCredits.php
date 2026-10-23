<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\AiCredits\CreditAccountant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator grant of saved AI credits through the accountant, with a ledger entry; repeating a run id grants nothing again. */
class GrantAiCredits extends Command
{
    protected $signature = 'docintel:grant-ai-credits
        {workspace : Workspace id}
        {amount : Whole credits to add (at least 1)}
        {--reason= : Why (required, recorded in the ledger)}
        {--run-id= : Idempotency key; the same run id applies once (default: a new one, printed)}';

    protected $description = 'Grant saved AI credits to a workspace with a ledger entry (idempotent per run id)';

    public function handle(CreditAccountant $accountant): int
    {
        $amount = (string) $this->argument('amount');
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::FAILURE;
        }
        if (! ctype_digit($amount) || (int) $amount < 1 || (int) $amount > 100000) {
            $this->error('amount must be a whole number from 1 to 100000.');

            return self::FAILURE;
        }
        if (! Workspace::whereKey($this->argument('workspace'))->exists()) {
            $this->error('No such workspace.');

            return self::FAILURE;
        }
        $runId = (string) ($this->option('run-id') ?: Str::uuid());
        $reference = 'admin_grant:'.$runId;
        $workspaceId = (string) $this->argument('workspace');

        $applied = DB::transaction(function () use ($accountant, $workspaceId, $amount, $reference, $reason) {
            $exists = fn () => DB::table('credit_ledger')->where('reference', $reference)->exists();
            if ($exists()) {
                return false;
            }
            $accountant->grantSaved($workspaceId, (int) $amount, $reference, 'admin_grant: '.mb_substr($reason, 0, 200), 'admin_grant', $workspaceId);

            return true;
        });
        $this->info($applied ? sprintf('Granted %d saved AI credits (run id %s).', (int) $amount, $runId)
            : sprintf('Run id %s was already applied; nothing granted.', $runId));

        return self::SUCCESS;
    }
}
