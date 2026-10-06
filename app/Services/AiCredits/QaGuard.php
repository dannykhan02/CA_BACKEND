<?php

namespace App\Services\AiCredits;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\User;
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
        if (! QuoteService::enabled()) {
            return null;
        }
        $workspaceId = $user->current_workspace_id;
        $this->hit('qa:ws:'.$workspaceId.':'.now()->format('Ymd'), (int) config('ai_credits.qa.max_per_workspace_per_day'), 90000);
        $this->hit('qa:user:'.$user->id.':'.now()->format('YmdH'), (int) config('ai_credits.qa.max_per_user_per_hour'), 4000);
        $spent = (float) DocumentAiRun::where('workspace_id', $workspaceId)->where('purpose', 'document_qa')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_usd');
        abort_if($spent >= (float) config('ai_credits.qa.max_cost_usd_per_workspace_per_day'), 429, 'Daily question limit reached. Please try again tomorrow.');

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

    private function hit(string $key, int $limit, int $ttlSeconds): void
    {
        Cache::add($key, 0, $ttlSeconds);
        abort_if(Cache::increment($key) > $limit, 429, 'Too many questions. Please slow down and try again shortly.');
    }
}
