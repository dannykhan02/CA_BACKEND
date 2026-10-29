<?php

namespace App\Services\AiCredits;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Q&A has no production cost data yet, so the abuse caps are on whenever AI credits are enabled and the
 * per-question customer debit stays off until ai_credits.qa.credits > 0.
 */
class QaGuard
{
    /** Aborts 429 over a cap. Returns a reserved quote when Q&A is metered, otherwise null. */
    public function admit(User $user): ?OperationQuote
    {
        $workspaceId = $user->current_workspace_id;
        if (! QuoteService::enabled()) {
            // Credits off: no counters and no customer debit, but the daily provider-spend ceiling still applies.
            $this->assertDailySpendBelowCeiling($workspaceId);

            return null;
        }
        $plan = $this->planOf($workspaceId);
        $dayLimit = (int) config('ai_credits.qa.max_per_workspace_per_day');
        $planDayLimit = $this->planDayLimit($plan);
        $this->hit('qa:ws:'.$workspaceId.':'.now()->format('Ymd'), $planDayLimit !== null ? min($dayLimit, $planDayLimit) : $dayLimit, 90000,
            $planDayLimit !== null && $planDayLimit <= $dayLimit ? $plan : null);
        $this->hit('qa:user:'.$user->id.':'.now()->format('YmdH'), (int) config('ai_credits.qa.max_per_user_per_hour'), 4000);
        $this->assertDailySpendBelowCeiling($workspaceId);

        $credits = (int) config('ai_credits.qa.credits');
        $accountant = app(CreditAccountant::class);
        if ($credits < 1 || $accountant->mode($workspaceId) === 'legacy') {
            return null;
        }
        $quote = app(QuoteService::class)->issue($workspaceId, 'qa', (string) Str::uuid(), ['band' => 'qa', 'credits' => $credits,
            'cap' => app(QuoteService::class)->providerCap($credits), 'preflight' => ['kind' => 'document_qa']]);
        $accountant->reserve($quote, $user->id);

        return $quote->fresh();
    }

    public function finish(?OperationQuote $quote, bool $answered, ?string $userId): void
    {
        if (! $quote) {
            return;
        }
        $answered ? app(CreditAccountant::class)->settle('qa', $quote->resource_id, $userId)
            : app(CreditAccountant::class)->release('qa', $quote->resource_id, 'qa_failed', $userId);
    }

    /**
     * Today's question allowance for display, in credit mode only (null otherwise). Free workspaces see a count against their day limit;
     * paid ones see the share of the daily provider-cost ceiling used, as a whole percent. No amounts of money leave this method.
     *
     * @return array{mode:string,used?:int,limit?:int,percent?:int,resets_at:string}|null
     */
    public function usageToday(string $workspaceId): ?array
    {
        $plan = QuoteService::enabled() ? $this->planOf($workspaceId) : null;
        if ($plan === null) {
            return null;
        }
        $resetsAt = now()->addDay()->startOfDay()->toIso8601String();
        $today = DocumentAiRun::where('workspace_id', $workspaceId)->where('purpose', 'document_qa')->where('created_at', '>=', now()->startOfDay());
        if ($plan === 'free') {
            $dayLimit = (int) config('ai_credits.qa.max_per_workspace_per_day');

            return ['mode' => 'count', 'used' => $today->count(), 'limit' => min($dayLimit, $this->planDayLimit($plan) ?? $dayLimit), 'resets_at' => $resetsAt];
        }
        $ceiling = $this->micros((float) config('ai_credits.qa.max_cost_usd_per_workspace_per_day'));
        $ceiling = min($ceiling, $this->planCostCeiling($plan) ?? $ceiling);
        $spent = $this->micros((float) $today->sum('estimated_cost_usd'));
        $percent = $ceiling < 1 ? 100 : min(100, intdiv($spent * 100 + intdiv($ceiling, 2), $ceiling));

        return ['mode' => 'percent', 'percent' => $percent, 'resets_at' => $resetsAt];
    }

    private function assertDailySpendBelowCeiling(?string $workspaceId): void
    {
        // Integer micro-dollars: no float comparison decides a cap.
        $spent = $this->micros((float) DocumentAiRun::where('workspace_id', $workspaceId)->where('purpose', 'document_qa')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_usd'));
        $ceiling = $this->micros((float) config('ai_credits.qa.max_cost_usd_per_workspace_per_day'));
        $plan = $workspaceId !== null && QuoteService::enabled() ? $this->planOf($workspaceId) : null;
        $planCeiling = $this->planCostCeiling($plan);
        if ($plan !== null && $planCeiling !== null && $planCeiling <= $ceiling && $spent >= $planCeiling) {
            $this->planLimit($plan);
        }
        abort_if($spent >= $ceiling, 429, 'Daily question limit reached. Please try again tomorrow.');
    }

    private function micros(float $usd): int
    {
        return (int) round($usd * 1000000);
    }

    /** The plan's own daily question count, or null when the workspace has no plan-specific limit (not in credit mode). */
    private function planDayLimit(?string $plan): ?int
    {
        return match ($plan) {
            'free' => (int) config('ai_credits.qa.free_max_per_workspace_per_day'),
            'starter', 'professional' => (int) config('ai_credits.qa.plans.'.$plan.'.max_per_day', config('ai_credits.qa.max_per_workspace_per_day')),
            default => null,
        };
    }

    /** The plan's own daily provider-cost ceiling in micro-dollars, or null. */
    private function planCostCeiling(?string $plan): ?int
    {
        return match ($plan) {
            'free' => $this->micros((float) config('ai_credits.qa.free_max_cost_usd_per_day')),
            'starter', 'professional' => $this->micros((float) config('ai_credits.qa.plans.'.$plan.'.max_cost_usd_per_day', config('ai_credits.qa.max_cost_usd_per_workspace_per_day'))),
            default => null,
        };
    }

    private function hit(string $key, int $limit, int $ttlSeconds, ?string $planLimit = null): void
    {
        Cache::add($key, 0, $ttlSeconds);
        if (Cache::increment($key) > $limit) {
            $planLimit !== null ? $this->planLimit($planLimit) : abort(429, 'Too many questions. Please slow down and try again shortly.');
        }
    }

    /** The workspace's plan when plan limits apply to it (credit mode); null otherwise. */
    private function planOf(?string $workspaceId): ?string
    {
        $plans = app(PlanLimits::class);

        return $workspaceId !== null && $plans->applies($workspaceId) ? $plans->planFor($workspaceId) : null;
    }

    /** The plan's daily question allowance is used up. */
    private function planLimit(string $plan): never
    {
        $required = match ($plan) {
            'free' => 'starter',
            'starter' => 'professional',
            default => null,
        };
        $message = match ($plan) {
            'free' => 'You have used your free questions for today. Upgrade to the Starter plan to ask more.',
            'starter' => 'You have used your questions for today. Upgrade to the Professional plan to ask more.',
            default => 'You have used your questions for today. Please try again tomorrow.',
        };
        throw new HttpResponseException(response()->json(['code' => 'plan_limit', 'reason' => 'qa_daily_limit', 'plan' => $plan,
            'required_plan' => $required, 'resets_at' => now()->addDay()->startOfDay()->toIso8601String(), 'message' => $message], 429));
    }
}
