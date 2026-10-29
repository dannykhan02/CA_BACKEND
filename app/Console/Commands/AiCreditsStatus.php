<?php

namespace App\Console\Commands;

use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: how AI credits are configured here and whether each workspace's AI-credit ledger adds up. Prints no secrets and no
 * environment values other than the credit flag; deprecated variables are listed by name only.
 */
class AiCreditsStatus extends Command
{
    protected $signature = 'docintel:ai-credits-status
        {--workspace= : Only this workspace id}
        {--limit=20 : Most recently active workspaces to list}';

    protected $description = 'Read-only: AI-credit flag, admission mode, bands, deprecated env variables, and per-workspace ledger reconciliation';

    private const AI_UNITS = ['saved_ai_credit', 'subscription_ai_credit', 'trial_ai_credit'];

    /** Trial carry-over and expiry rows are balance moves outside the hold lifecycle (their references start with trial_). */
    private const NOT_HOLD_LIFECYCLE = 'trial\_%';

    public function handle(CreditAccountant $accountant): int
    {
        $fromEnv = $_ENV['DOCINTEL_AI_CREDITS_ENABLED'] ?? $_SERVER['DOCINTEL_AI_CREDITS_ENABLED'] ?? (getenv('DOCINTEL_AI_CREDITS_ENABLED') ?: null);
        $this->info(sprintf('AI credits: %s (config), DOCINTEL_AI_CREDITS_ENABLED in this process: %s. Set it on CA_BACKEND and ca-horizon-worker alike.',
            QuoteService::enabled() ? 'ON' : 'OFF', $fromEnv === null ? 'unset' : $fromEnv));
        $this->line(sprintf('Admission: %s (overrun factor %s). Synthesis reservation: %s (headroom %s). Dense shift: %s. Quote version: %s.',
            config('ai_credits.admission_mode'), config('ai_credits.overrun_factor'), config('ai_credits.synthesis_reservation.mode'),
            config('ai_credits.synthesis_reservation.headroom'), config('ai_credits.dense_shift.enabled') ? 'on' : 'off', config('ai_credits.quote_version')));

        $perCredit = (float) config('ai_credits.provider_cost_usd_per_credit');
        $this->line(sprintf('Provider cost per credit: USD %s.', $perCredit));
        $this->table(['band', 'up to tokens', 'credits', 'cap USD'], array_map(fn ($name, $b) => [$name, $b['max_tokens'], $b['credits'], round($b['credits'] * $perCredit, 6)],
            array_keys(config('ai_credits.bands')), config('ai_credits.bands')));
        $this->table(['plan', 'monthly', 'annual'], array_map(fn ($plan, $a) => [$plan, $a['monthly'], $a['annual']],
            array_keys(config('ai_credits.plans')), config('ai_credits.plans')));

        $this->settings();

        $deprecated = $this->deprecatedVariables();
        $this->line($deprecated === [] ? 'No deprecated AI_CREDITS_ variables are set.'
            : 'Deprecated (set, but the current config does not read them): '.implode(', ', $deprecated));

        $this->workspaces($accountant);

        return self::SUCCESS;
    }

    /** Effective values of the settlement, admission, plan, Q&A and referral settings (booleans and numbers only; no secrets here). */
    private function settings(): void
    {
        $yn = fn ($v) => $v ? 'yes' : 'no';
        $or = fn ($v, string $none = 'none') => $v === null || $v === '' ? $none : $v;
        $this->info('Plan and admission settings');
        $this->line(sprintf('Settlement: mode %s, buffer %s, minimum charge %s. Admission minimum ratio %s (applies in actual mode only). Saved credits during a subscription: %s.',
            config('ai_credits.settlement_mode'), config('ai_credits.settlement_buffer'), config('ai_credits.settlement_min'),
            config('ai_credits.admission_min_ratio'), $yn(config('ai_credits.saved_fallback_during_subscription'))));
        $this->line(sprintf('Free trial: %s credits, expires after %s.', config('ai_credits.grants.free_trial_credits'),
            $or(config('ai_credits.free_trial_days') === null ? null : config('ai_credits.free_trial_days').' days', 'never (plain saved credits)')));
        $rows = [];
        foreach (config('ai_credits.plan_limits') as $plan => $limits) {
            $rows[] = [$plan, $or($limits['allowed_types'], 'all types'), $yn($limits['allow_ocr']), $or($limits['max_quote_tokens'], 'no limit')];
        }
        $this->table(['plan', 'allowed types', 'OCR', 'max quote tokens'], $rows);
        foreach (config('ai_credits.plans') as $plan => $credits) {
            $this->line(sprintf('Plan credits per month: %s monthly %s, annual %s.', $plan, $credits['monthly'], $credits['annual']));
        }
        $this->line('Scanned pages allowed in a PDF when OCR is off (max_ocr_pages): '.implode(', ', array_map(
            fn ($plan, $limits) => $plan.' '.($limits['max_ocr_pages'] ?? 0), array_keys(config('ai_credits.plan_limits')), config('ai_credits.plan_limits'))).'.');
        foreach (config('ai_credits.qa.plans', []) as $plan => $qa) {
            $this->line(sprintf('Q&A %s plan: %s per day, USD %s per day.', $plan, $qa['max_per_day'], $qa['max_cost_usd_per_day']));
        }
        $this->line(sprintf('Q&A: customer price %s credits, %s per workspace per day, USD %s per day; free plan %s per day, USD %s per day.',
            config('ai_credits.qa.credits'), config('ai_credits.qa.max_per_workspace_per_day'), config('ai_credits.qa.max_cost_usd_per_workspace_per_day'),
            config('ai_credits.qa.free_max_per_workspace_per_day'), config('ai_credits.qa.free_max_cost_usd_per_day')));
        $this->line(sprintf('Referral reward: %s saved credits, when %s.', config('ai_credits.grants.referral_reward_credits'),
            config('ai_credits.grants.referral_reward_trigger') === 'first_payment' ? 'the referred user\'s first paid subscription is active' : 'the referred user\'s first payment completes'));
        if (config('ai_credits.invalid_env')) {
            $this->warn('Ignored as unusable (defaults in effect): '.implode(', ', array_keys(config('ai_credits.invalid_env'))));
        }
    }

    /** @return list<string> names only */
    private function deprecatedVariables(): array
    {
        $read = [];
        preg_match_all("/env\\('(AI_CREDITS_[A-Z0-9_]+)'/", (string) file_get_contents(config_path('ai_credits.php')), $matches);
        foreach ($matches[1] as $name) {
            $read[$name] = true;
        }
        foreach (array_keys(config('ai_credits.stage_realistic_output', [])) as $purpose) {
            foreach (['BASE', 'PER_TOKEN', 'CAP'] as $part) {
                $read['AI_CREDITS_REALISTIC_'.strtoupper($purpose).'_'.$part] = true;
            }
        }
        // Plan limits are read with a computed name (AI_CREDITS_<PLAN>_<SETTING>), which the pattern above cannot see.
        foreach (array_keys(config('ai_credits.plan_limits', [])) as $plan) {
            foreach (['ALLOWED_TYPES', 'ALLOW_OCR', 'MAX_QUOTE_TOKENS', 'MAX_OCR_PAGES'] as $setting) {
                $read['AI_CREDITS_'.strtoupper($plan).'_'.$setting] = true;
            }
        }
        // Per-plan Q&A limits are read with a computed name as well (AI_CREDITS_<PLAN>_QA_PER_DAY / _QA_MAX_COST_USD).
        foreach (array_keys(config('ai_credits.qa.plans', [])) as $plan) {
            foreach (['QA_PER_DAY', 'QA_MAX_COST_USD'] as $setting) {
                $read['AI_CREDITS_'.strtoupper($plan).'_'.$setting] = true;
            }
        }
        $set = array_merge(array_keys($_ENV), array_keys($_SERVER), array_keys(getenv()));
        $set = array_unique(array_filter($set, fn ($name) => is_string($name) && str_starts_with($name, 'AI_CREDITS_')));
        sort($set);

        return array_values(array_filter($set, fn ($name) => ! isset($read[$name])));
    }

    private function workspaces(CreditAccountant $accountant): void
    {
        $ledger = DB::table('credit_ledger')->whereIn('unit', self::AI_UNITS)->whereIn('direction', ['reserve', 'debit', 'release'])->where('reference', 'not like', self::NOT_HOLD_LIFECYCLE);
        if ($this->option('workspace')) {
            $ledger->where('workspace_id', $this->option('workspace'));
        }
        $ids = (clone $ledger)->select('workspace_id')->groupBy('workspace_id')->orderByRaw('max(created_at) desc')
            ->limit(max(1, (int) $this->option('limit')))->pluck('workspace_id');
        if ($ids->isEmpty()) {
            $this->line('No AI-credit ledger entries'.($this->option('workspace') ? ' for that workspace.' : '.'));

            return;
        }
        $rows = [];
        foreach ($ids as $id) {
            $sum = fn (string $direction) => (int) DB::table('credit_ledger')->where('workspace_id', $id)->whereIn('unit', self::AI_UNITS)->where('direction', $direction)
                ->where('reference', 'not like', self::NOT_HOLD_LIFECYCLE)->sum('amount');
            $absorbed = (int) DB::table('credit_ledger')->where('workspace_id', $id)->where('direction', 'info')->where('reference', 'like', 'ai\_absorbed:%')->sum('amount');
            [$reserved, $debited, $released] = [$sum('reserve'), $sum('debit'), $sum('release')];
            $open = (int) DB::table('billing_operations')->where('workspace_id', $id)->where('status', 'reserved')->whereNotNull('amount_reserved')->sum('amount_reserved');
            $b = $accountant->balances($id);
            $rows[] = [$id, $b['saved'], $accountant->trialBalance($id)['available'], $b['monthly']['remaining'] ?? '-', $reserved, $debited, $released, $open,
                $reserved === $debited + $released + $open ? 'ok' : 'MISMATCH ('.($reserved - $debited - $released - $open).')', $absorbed];
        }
        $this->table(['workspace', 'saved balance', 'trial left', 'monthly left', 'reserved', 'debited', 'released', 'open holds', 'check', 'absorbed'], $rows);
        $this->line('check: reserved must equal debited + released + open holds, over monthly, saved and trial credits. Absorbed credits (cost above a hold, not charged) are listed apart. Nothing was changed.');
    }
}
