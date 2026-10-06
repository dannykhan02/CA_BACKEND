<?php

namespace App\Console\Commands;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Services\AiCredits\QuoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only unit economics for AI credits. Aggregates and metadata only: no document names, text,
 * prompts or provider payloads are selected, and nothing here mutates billing.
 */
class CreditEconomicsReport extends Command
{
    protected $signature = 'docintel:credit-economics-report
        {--days=30 : Look-back window for operations and provider runs}
        {--fx= : KES per USD (default ai_credits.fx_kes_per_usd)}
        {--fee= : Payment fee rate, e.g. 0.029 (default ai_credits.payment_fee_rate)}
        {--margin= : Contribution margin target (default ai_credits.margin_target)}
        {--cost-stress=1.25 : Provider-cost multiplier for the stress scenario}
        {--fx-stress=1.10 : FX multiplier for the stress scenario}
        {--json : Emit JSON instead of tables}
        {--what-if : Print credit prices and documents per month for 5k/20k/60k/150k-token documents instead of the report}
        {--floor-share=1 : With --what-if, the share of the documented synthesis reservation the quote gate must fit (1 = today)}
        {--per-credit= : With --what-if, provider USD ceiling per credit (default config)}
        {--bands= : With --what-if, credits:maxTokens pairs in ascending order, e.g. 4:6000,15:30000,30:90000,60:250000}';

    protected $description = 'Read-only AI-credit unit economics: plan margins, per-operation provider cost quantiles, release counts.';

    /** Documented synthesis reservations by document size (incremental route above 14k tokens; legacy route below). */
    private const FLOORS = [5000 => 0.0, 20000 => 0.50, 60000 => 0.85, 150000 => 1.05];

    public function handle(): int
    {
        if ($this->option('what-if')) {
            return $this->whatIf();
        }
        $fx = (float) ($this->option('fx') ?: config('ai_credits.fx_kes_per_usd'));
        $fee = (float) ($this->option('fee') ?? config('ai_credits.payment_fee_rate'));
        $target = (float) ($this->option('margin') ?? config('ai_credits.margin_target'));
        $costStress = (float) $this->option('cost-stress');
        $fxStress = (float) $this->option('fx-stress');
        $since = now()->subDays((int) $this->option('days'));
        $perCredit = (float) config('ai_credits.provider_cost_usd_per_credit');

        $report = ['assumptions' => ['fx_kes_per_usd' => $fx, 'payment_fee_rate' => $fee, 'margin_target' => $target, 'provider_usd_per_credit_ceiling' => $perCredit,
            'kes_per_credit_ceiling' => round($perCredit * $fx, 4), 'cost_stress' => $costStress, 'fx_stress' => $fxStress,
            'note' => 'Plan margins are CEILING-based (every credit spent at its provider cap). Quantiles below are measured, when present.'],
            'plans' => $this->plans($fx, $fee, $target, $perCredit, $costStress, $fxStress),
            'operations' => $this->operations($since, $fx),
            'unquoted_documents' => $this->unquotedDocuments($since, $fx),
            'releases' => OperationQuote::where('created_at', '>=', $since)->where('status', 'released')->select('kind', 'release_reason', DB::raw('count(*) as n'))
                ->groupBy('kind', 'release_reason')->get()->map(fn ($r) => $r->toArray())->all()];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info(sprintf('FX %.2f KES/USD, fee %.1f%%, target %.0f%%, ceiling %.4f USD (%.2f KES) per credit', $fx, $fee * 100, $target * 100, $perCredit, $perCredit * $fx));
        $this->table(['plan', 'interval', 'price KES', 'credits', 'max provider KES', 'margin @cap', 'margin +cost', 'margin +cost+FX', 'meets target'],
            array_map(fn ($p) => [$p['plan'], $p['interval'], $p['price_kes'], $p['credits'], $p['max_provider_kes'], $p['margin_at_cap'].'%', $p['margin_cost_stress'].'%',
                $p['margin_cost_fx_stress'].'%', $p['meets_target_at_cap'] ? 'yes' : 'NO'], $report['plans']));
        $this->table(['kind', 'band', 'quotes', 'settled', 'released', 'credits quoted', 'USD p50', 'p90', 'p95', 'p99', 'max', 'unknown runs', 'over cap', 'USD/settled credit'],
            array_map(fn ($o) => array_values($o), $report['operations']));
        $this->line('Documents without a quote (pre-rollout): '.json_encode($report['unquoted_documents']));
        $this->line('Releases: '.json_encode($report['releases']));

        return self::SUCCESS;
    }

    /** Read-only scenario table. Overrides config in memory only, and restores it. */
    private function whatIf(): int
    {
        $original = config('ai_credits');
        try {
            if ($this->option('per-credit')) {
                config(['ai_credits.provider_cost_usd_per_credit' => (float) $this->option('per-credit')]);
            }
            if ($this->option('bands')) {
                $names = array_keys($original['bands']);
                $pairs = array_map(fn ($p) => array_map('intval', explode(':', $p)), explode(',', (string) $this->option('bands')));
                if (count($pairs) !== count($names)) {
                    $this->error('--bands needs '.count($names).' credits:maxTokens pairs.');

                    return self::INVALID;
                }
                foreach ($names as $i => $name) {
                    config(["ai_credits.bands.{$name}" => ['credits' => $pairs[$i][0], 'max_tokens' => $pairs[$i][1]]]);
                }
            }
            $quotes = app(QuoteService::class);
            $share = (float) $this->option('floor-share');
            $rows = [];
            foreach (self::FLOORS as $tokens => $floor) {
                $c = $quotes->classify($tokens, false, $floor * $share);
                $rows[] = ['tokens' => $tokens, 'band' => $c['declined'] ? 'declined' : $c['band'], 'credits' => $c['declined'] ? null : $c['credits'],
                    'cap_usd' => $c['declined'] ? null : $c['cap'],
                    'starter_docs_per_month' => $c['declined'] ? 0 : intdiv((int) config('ai_credits.plans.starter.monthly'), $c['credits']),
                    'professional_docs_per_month' => $c['declined'] ? 0 : intdiv((int) config('ai_credits.plans.professional.monthly'), $c['credits'])];
            }
        } finally {
            config(['ai_credits' => $original]);
        }
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT));
        } else {
            $this->table(['tokens', 'band', 'credits', 'cap USD', 'Starter docs/month', 'Professional docs/month'], array_map('array_values', $rows));
            $this->line('Nothing was changed. Floors are the documented reservations x --floor-share; not measured costs.');
        }

        return self::SUCCESS;
    }

    private function plans(float $fx, float $fee, float $target, float $perCredit, float $costStress, float $fxStress): array
    {
        $rows = [];
        foreach (config('billing.plans') as $key => $plan) {
            foreach (['monthly' => 1, 'annual' => 12] as $interval => $months) {
                $credits = (int) config("ai_credits.plans.{$key}.{$interval}");
                $revenue = $plan['prices'][$interval] / 100 / $months; // KES per month; prices are stored in cents
                $cap = fn (float $c, float $x) => $credits * $perCredit * $c * $x;
                $margin = fn (float $c, float $x) => $revenue > 0 ? round((($revenue * (1 - $fee)) - $cap($c, $fx * $x)) / $revenue * 100, 1) : 0.0;
                $rows[] = ['plan' => $key, 'interval' => $interval, 'price_kes' => round($revenue, 2), 'credits' => $credits,
                    'max_provider_kes' => round($cap(1.0, $fx), 2), 'margin_at_cap' => $margin(1.0, 1.0), 'margin_cost_stress' => $margin($costStress, 1.0),
                    'margin_cost_fx_stress' => $margin($costStress, $fxStress), 'meets_target_at_cap' => $margin(1.0, 1.0) >= $target * 100];
            }
        }

        return $rows;
    }

    private function operations(\DateTimeInterface $since, float $fx): array
    {
        $rows = [];
        $quotes = OperationQuote::where('created_at', '>=', $since)->get()->groupBy(fn ($q) => $q->kind.'|'.$q->band);
        foreach ($quotes as $group => $items) {
            [$kind, $band] = explode('|', $group);
            $ids = $items->pluck('id');
            $runs = DocumentAiRun::whereIn('operation_quote_id', $ids)->get(['operation_quote_id', 'estimated_cost_usd']);
            $perQuote = $runs->groupBy('operation_quote_id');
            $known = $items->map(fn ($q) => (float) $perQuote->get($q->id, collect())->sum('estimated_cost_usd'))->filter(fn ($v) => $v > 0)->values()->all();
            $over = $items->filter(fn ($q) => (float) $perQuote->get($q->id, collect())->sum('estimated_cost_usd') > $q->provider_cost_cap_usd)->count();
            $settled = $items->where('status', 'settled');
            $settledCost = $settled->sum(fn ($q) => (float) $perQuote->get($q->id, collect())->sum('estimated_cost_usd'));
            $settledCredits = $settled->sum('credits');
            $rows[] = ['kind' => $kind, 'band' => $band, 'quotes' => $items->count(), 'settled' => $settled->count(), 'released' => $items->where('status', 'released')->count(),
                'credits_quoted' => $items->sum('credits'), 'p50' => $this->quantile($known, .50), 'p90' => $this->quantile($known, .90), 'p95' => $this->quantile($known, .95),
                'p99' => $this->quantile($known, .99), 'max' => $known ? round(max($known), 4) : null, 'unknown_runs' => $runs->whereNull('estimated_cost_usd')->count(),
                'over_cap' => $over, 'usd_per_settled_credit' => $settledCredits ? round($settledCost / $settledCredits, 5) : null];
        }

        return $rows;
    }

    /** Pre-rollout cohort: cost per document from provider runs, only documents with fully known usage. */
    private function unquotedDocuments(\DateTimeInterface $since, float $fx): array
    {
        $runs = DocumentAiRun::where('created_at', '>=', $since)->whereNull('operation_quote_id')->get(['document_id', 'estimated_cost_usd']);
        $byDoc = $runs->groupBy('document_id');
        $known = $byDoc->filter(fn ($r) => $r->whereNull('estimated_cost_usd')->isEmpty())->map(fn ($r) => (float) $r->sum('estimated_cost_usd'))->values()->all();

        return ['documents' => $byDoc->count(), 'documents_fully_known' => count($known), 'runs_with_unknown_cost' => $runs->whereNull('estimated_cost_usd')->count(),
            'usd_p50' => $this->quantile($known, .50), 'usd_p90' => $this->quantile($known, .90), 'usd_p95' => $this->quantile($known, .95), 'usd_max' => $known ? round(max($known), 4) : null,
            'kes_p90' => ($q = $this->quantile($known, .90)) !== null ? round($q * $fx, 2) : null];
    }

    private function quantile(array $values, float $q): ?float
    {
        if (! $values) {
            return null;
        }
        sort($values);
        $pos = ($count = count($values)) > 1 ? $q * ($count - 1) : 0;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return round($values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo), 4);
    }
}
