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
        $free = $this->isFree($workspaceId);
        $dayLimit = (int) config('ai_credits.qa.max_per_workspace_per_day');
        $freeDayLimit = $free ? (int) config('ai_credits.qa.free_max_per_workspace_per_day') : null;
        $this->hit('qa:ws:'.$workspaceId.':'.now()->format('Ymd'), $freeDayLimit !== null ? min($dayLimit, $freeDayLimit) : $dayLimit, 90000,
            $freeDayLimit !== null && $freeDayLimit <= $dayLimit);
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

    private function assertDailySpendBelowCeiling(?string $workspaceId): void
    {
        $spent = (float) DocumentAiRun::where('workspace_id', $workspaceId)->where('purpose', 'document_qa')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_usd');
        $ceiling = (float) config('ai_credits.qa.max_cost_usd_per_workspace_per_day');
        if ($workspaceId !== null && QuoteService::enabled() && $this->isFree($workspaceId)) {
            $freeCeiling = (float) config('ai_credits.qa.free_max_cost_usd_per_day');
            if ($freeCeiling <= $ceiling && $spent >= $freeCeiling) {
                $this->planLimit();
            }
        }
        abort_if($spent >= $ceiling, 429, 'Daily question limit reached. Please try again tomorrow.');
    }

    private function hit(string $key, int $limit, int $ttlSeconds, bool $planLimit = false): void
    {
        Cache::add($key, 0, $ttlSeconds);
        if (Cache::increment($key) > $limit) {
            $planLimit ? $this->planLimit() : abort(429, 'Too many questions. Please slow down and try again shortly.');
        }
    }

    private function isFree(?string $workspaceId): bool
    {
        $plans = app(PlanLimits::class);

        return $workspaceId !== null && $plans->applies($workspaceId) && $plans->planFor($workspaceId) === 'free';
    }

    /** The free plan's daily question allowance is used up. */
    private function planLimit(): never
    {
        throw new HttpResponseException(response()->json(['code' => 'plan_limit', 'reason' => 'qa_daily_limit', 'plan' => 'free',
            'required_plan' => 'starter', 'resets_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'message' => 'You have used your free questions for today. Upgrade to the Starter plan to ask more.'], 429));
    }
}
