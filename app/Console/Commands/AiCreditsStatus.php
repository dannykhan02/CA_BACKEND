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

        $deprecated = $this->deprecatedVariables();
        $this->line($deprecated === [] ? 'No deprecated AI_CREDITS_ variables are set.'
            : 'Deprecated (set, but the current config does not read them): '.implode(', ', $deprecated));

        $this->workspaces($accountant);

        return self::SUCCESS;
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
            $rows[] = [$id, $b['saved'], $accountant->trialBalance($id)['available'], $b['monthly']['remaining'] ?? '-', $reserved, $debited, $released, $open, $absorbed,
                $reserved === $debited + $released + $open ? 'ok' : 'MISMATCH ('.($reserved - $debited - $released - $open).')'];
        }
        $this->table(['workspace', 'saved balance', 'trial left', 'monthly left', 'reserved', 'debited', 'released', 'open holds', 'absorbed', 'check'], $rows);
        $this->line('check: reserved must equal debited + released + open holds, over monthly, saved and trial credits. Absorbed credits (cost above a hold, not charged) are listed apart. Nothing was changed.');
    }
}
