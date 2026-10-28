<?php

namespace App\Console\Commands;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Workspace;
use App\Services\AiCredits\ActualCostSettlement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closes provider_call_attempts that were left reserved, dispatched or uncertain (a crash, a lost job). An unresolved attempt
 * makes settlement charge the quote instead of the recorded cost, so they must not stay open for days.
 *
 * For each attempt older than the threshold (by its last update):
 *  - a document_ai_runs row for it (linked by provider_attempt_id, else same quote and purpose inside the attempt's time window)
 *    settles it at that run's cost, and the run is linked to the attempt;
 *  - otherwise, never dispatched ('reserved') -> 'released' at 0, exactly as OperationSpend::releaseAttempt does;
 *  - otherwise, dispatched or uncertain with no run -> 'settled' at its reserved amount: the provider may have billed it, so the
 *    spend cap keeps counting what it counted while open, but the attempt no longer blocks settlement.
 * Dry run unless --apply. Idempotent: only open attempts are touched, each re-checked under the quote (or workspace) lock.
 */
class ReconcileProviderAttempts extends Command
{
    protected $signature = 'credits:reconcile-attempts {--older-than=60 : Minutes since the attempt was last updated} {--apply : Write the changes (otherwise only list them)}';

    protected $description = 'Close stale provider call attempts: settle matched ones at the run cost, release or settle-at-hold the rest; dry run unless --apply';

    /** Minutes after an attempt's last update inside which a run still counts as its response. */
    private const MATCH_WINDOW_MINUTES = 30;

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('older-than'));
        $apply = (bool) $this->option('apply');
        $stale = DB::table('provider_call_attempts')->whereIn('status', ['reserved', 'dispatched', 'uncertain'])
            ->where('updated_at', '<=', now()->subMinutes($minutes))->orderBy('created_at')->get();
        $counts = ['matched' => 0, 'released' => 0, 'settled_at_hold' => 0, 'skipped' => 0];

        foreach ($stale as $attempt) {
            $outcome = $apply ? $this->resolve($attempt->id, $minutes) : $this->plan($attempt)['outcome'];
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            $this->line(sprintf('%s %s (%s, %s, quote %s): %s', $apply ? 'Resolved' : 'Would resolve', $attempt->id, $attempt->purpose, $attempt->status,
                $attempt->operation_quote_id ?? 'internal scope', $outcome));
        }
        $this->info(sprintf('%d open attempt(s) older than %d minutes: %d matched to a run, %d released, %d settled at the hold, %d skipped. %s', $stale->count(),
            $minutes, $counts['matched'], $counts['released'], $counts['settled_at_hold'], $counts['skipped'],
            $apply ? 'Applied.' : 'Dry run: nothing was changed. Use --apply.'));

        return self::SUCCESS;
    }

    /** @return array{outcome:string,run:?DocumentAiRun,micros:?int} */
    private function plan(object $attempt): array
    {
        $run = DocumentAiRun::where('provider_attempt_id', $attempt->id)->first();
        if (! $run && $attempt->operation_quote_id !== null) {
            $run = DocumentAiRun::where('operation_quote_id', $attempt->operation_quote_id)->where('purpose', $attempt->purpose)
                ->whereNull('provider_attempt_id')->whereNotNull('estimated_cost_usd')
                ->where('created_at', '>=', $attempt->created_at)
                ->where('created_at', '<=', Carbon::parse($attempt->updated_at)->addMinutes(self::MATCH_WINDOW_MINUTES))
                ->orderBy('created_at')->first();
        }
        $micros = $run?->estimated_cost_usd !== null ? ActualCostSettlement::toScaled((string) $run->estimated_cost_usd, 6) : null;
        if ($run && $micros !== null) {
            return ['outcome' => 'matched', 'run' => $run, 'micros' => $micros];
        }

        return ['outcome' => $attempt->status === 'reserved' ? 'released' : 'settled_at_hold', 'run' => null, 'micros' => null];
    }

    private function resolve(string $attemptId, int $minutes): string
    {
        return DB::transaction(function () use ($attemptId, $minutes) {
            $probe = DB::table('provider_call_attempts')->where('id', $attemptId)->first();
            if (! $probe) {
                return 'skipped';
            }
            // The same lock order OperationSpend uses, so this cannot race a live call on the quote.
            $probe->operation_quote_id
                ? OperationQuote::whereKey($probe->operation_quote_id)->lockForUpdate()->first()
                : Workspace::whereKey($probe->workspace_id)->lockForUpdate()->first();
            $attempt = DB::table('provider_call_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if (! $attempt || ! in_array($attempt->status, ['reserved', 'dispatched', 'uncertain'], true)
                || Carbon::parse($attempt->updated_at)->gt(now()->subMinutes($minutes))) {
                return 'skipped'; // resolved meanwhile, or touched again: younger than the threshold now
            }
            $plan = $this->plan($attempt);
            $update = match ($plan['outcome']) {
                'matched' => ['status' => 'settled', 'settled_microusd' => $plan['micros']],
                'released' => ['status' => 'released', 'settled_microusd' => 0],
                default => ['status' => 'settled', 'settled_microusd' => (int) $attempt->reserved_microusd],
            };
            DB::table('provider_call_attempts')->where('id', $attemptId)->update($update + ['updated_at' => now()]);
            if ($plan['run']) {
                // The attempt now carries this run's cost, so spent-cost sums count it once (OperationSpend::spentUsd skips linked runs).
                DocumentAiRun::whereKey($plan['run']->getKey())->whereNull('provider_attempt_id')->update(['provider_attempt_id' => $attemptId]);
            }

            return $plan['outcome'];
        });
    }
}
