<?php

namespace App\Console\Commands;

use App\Models\SubscriptionUsagePeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raises ai_credits_allowed on current and future usage periods of workspaces with an active subscription on a plan, up to the
 * configured allowance. Dry run unless --apply. Never lowers a value, never touches past or legacy (NULL) periods, and a second
 * run finds nothing to change.
 */
class ApplyPlanAllowance extends Command
{
    protected $signature = 'credits:apply-plan-allowance
        {plan : Plan key (starter or professional)}
        {--apply : Write the change; without it nothing is changed}';

    protected $description = 'Raise current and future subscription periods of a plan to its configured monthly AI credits (dry run by default)';

    public function handle(): int
    {
        $plan = (string) $this->argument('plan');
        if (! array_key_exists($plan, (array) config('ai_credits.plans'))) {
            $this->error(sprintf('Unknown plan "%s". Known plans: %s.', $plan, implode(', ', array_keys((array) config('ai_credits.plans')))));

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $changed = 0;
        $skipped = 0;
        $credits = 0;

        DB::transaction(function () use ($plan, $apply, &$changed, &$skipped, &$credits) {
            $periods = SubscriptionUsagePeriod::query()
                ->join('subscriptions', 'subscriptions.id', '=', 'subscription_usage_periods.subscription_id')
                ->where('subscriptions.plan_key', $plan)
                ->where('subscriptions.grandfathered', false)
                ->whereIn('subscriptions.status', ['active', 'non_renewing', 'past_due'])
                ->where('subscription_usage_periods.period_end', '>', now())
                ->whereNotNull('subscription_usage_periods.ai_credits_allowed')
                ->select('subscription_usage_periods.*', 'subscriptions.workspace_id', 'subscriptions.billing_interval')
                ->orderBy('subscriptions.workspace_id')->orderBy('subscription_usage_periods.period_start')
                ->lockForUpdate()->get();
            foreach ($periods as $period) {
                $to = (int) config('ai_credits.plans.'.$plan.'.'.$period->billing_interval, 0);
                $from = (int) $period->ai_credits_allowed;
                if ($to <= $from) {
                    $skipped++;

                    continue;
                }
                $this->line(sprintf('workspace %s period %s %s -> %s: %d -> %d', $period->workspace_id, $period->id,
                    $period->period_start->toDateTimeString(), $period->period_end->toDateTimeString(), $from, $to));
                $changed++;
                $credits += $to - $from;
                if ($apply) {
                    // Guarded so a concurrent higher value is never lowered.
                    SubscriptionUsagePeriod::whereKey($period->id)->where('ai_credits_allowed', '<', $to)->update(['ai_credits_allowed' => $to]);
                    Log::info('Plan allowance raised.', ['workspace_id' => $period->workspace_id, 'period_id' => $period->id, 'plan' => $plan, 'from' => $from, 'to' => $to]);
                }
            }
        });

        $this->line(sprintf('%d period(s) %s, %d credit(s) added in total; %d already at or above the configured value.',
            $changed, $apply ? 'raised' : 'would be raised', $credits, $skipped));
        if (! $apply) {
            $this->line('Nothing was changed.');
        }

        return self::SUCCESS;
    }
}
