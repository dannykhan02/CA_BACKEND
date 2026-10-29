<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\CreditLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator correction of a workspace's saved AI credits by a signed amount, with exactly one ledger row (unit saved_ai_credit,
 * reference saved_adjust:<workspace>:<run-id>). Dry run unless --apply. A run id applies once. The balance never goes negative.
 * Monthly allowances, the trial pocket, holds and quotes are not touched. A negative delta must follow "--" so it is not read as
 * an option: credits:adjust-saved --reason=... --run-id=... --apply <workspace> -- -150
 */
class AdjustSavedCredits extends Command
{
    protected $signature = 'credits:adjust-saved
        {workspace : Workspace id}
        {delta : Signed whole number of saved AI credits (use "--" before a negative one)}
        {--reason= : Why (required, recorded in the ledger)}
        {--run-id= : Idempotency key (required); the same run id applies once}
        {--apply : Write the change; without it nothing is changed}';

    protected $description = 'Adjust a workspace\'s saved AI credits by a signed amount with one ledger entry (dry run by default, idempotent per run id)';

    public function handle(CreditLedger $ledger): int
    {
        $delta = (string) $this->argument('delta');
        $reason = trim((string) $this->option('reason'));
        $runId = trim((string) $this->option('run-id'));
        $workspaceId = (string) $this->argument('workspace');
        if ($reason === '') {
            return $this->refuse('--reason is required.');
        }
        if ($runId === '') {
            return $this->refuse('--run-id is required.');
        }
        if (mb_strlen($runId) > 100) {
            return $this->refuse('--run-id must be at most 100 characters.');
        }
        if (! preg_match('/^-?\d{1,9}$/', $delta) || (int) $delta === 0) {
            return $this->refuse('delta must be a non-zero whole number.');
        }
        $delta = (int) $delta;
        if (! Workspace::whereKey($workspaceId)->exists()) {
            return $this->refuse('No such workspace.');
        }
        $apply = (bool) $this->option('apply');
        $reference = 'saved_adjust:'.$workspaceId.':'.$runId;

        $result = DB::transaction(function () use ($ledger, $workspaceId, $delta, $reason, $reference, $apply) {
            // Same order as the purchase path: workspace first, then its credit row.
            Workspace::whereKey($workspaceId)->lockForUpdate()->first();
            $row = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (! $row) {
                return ['error' => 'The workspace has no credit row.'];
            }
            $current = (int) $row->ai_credits_remaining;
            $new = $current + $delta;
            if (DB::table('credit_ledger')->where('reference', $reference)->exists()) {
                return ['already' => true, 'current' => $current, 'new' => $new];
            }
            if ($new < 0) {
                return ['error' => sprintf('Refused: %d saved credits %+d would be %d. The balance cannot go negative.', $current, $delta, $new)];
            }
            if ($apply) {
                $row->forceFill(['ai_credits_remaining' => $new])->save();
                $rate = max(1, (int) config('ai_credits.legacy_saved_credits_per_document'));
                $ledger->record($workspaceId, $reference, CreditAccountant::SAVED, $delta > 0 ? 'credit' : 'debit', abs($delta),
                    'saved_adjust: '.mb_substr($reason, 0, 200), 'workspace', $workspaceId, null, $new + (int) $row->documents_remaining * $rate);
            }

            return ['current' => $current, 'new' => $new];
        });

        if (isset($result['error'])) {
            return $this->refuse($result['error']);
        }
        if ($result['already'] ?? false) {
            $this->info(sprintf('Run id %s was already applied to workspace %s; nothing changed.', $runId, $workspaceId));

            return self::SUCCESS;
        }
        $this->line(sprintf('Workspace %s: saved balance %d, delta %+d, new balance %d.', $workspaceId, $result['current'], $delta, $result['new']));
        $this->line($apply ? sprintf('Applied (ledger reference %s).', $reference) : 'Nothing was changed.');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
