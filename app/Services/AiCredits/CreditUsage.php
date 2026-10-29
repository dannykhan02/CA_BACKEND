<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Services\EntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only, customer-safe usage view of a workspace's AI credits for the Usage page: limits, the daily or monthly series, top
 * documents and saved credits. Charged/returned figures come from credit_ledger only. Nothing here changes how a credit is held,
 * settled, released or granted, and it exposes no money, costs, models, quote or operation ids.
 */
class CreditUsage
{
    /** Operation kinds that belong to a document. */
    private const DOCUMENT_KINDS = ['document', 'ocr', 'reanalysis'];

    private const AI_UNITS = [CreditAccountant::MONTHLY, CreditAccountant::SAVED, CreditAccountant::TRIAL];

    private const TOP_DOCUMENTS = 5;

    private const MONTHS = 6;

    private const FREE_CYCLE_DAYS = 30;

    /** SQL fragments: a ledger row's contribution to "charged" and to "returned" (LIKE underscores escaped). */
    private const CHARGED = "case when direction = 'debit' and reference like 'ai\\_debit:%' then amount else 0 end";

    private const RETURNED = "case when direction = 'release' and (reference like 'ai\\_release\\_unused:%' or reference like 'ai\\_release:%') then amount else 0 end";

    public function forWorkspace(string $workspaceId, string $range): array
    {
        $accountant = app(CreditAccountant::class);
        if (! QuoteService::enabled() || $accountant->mode($workspaceId) !== 'credits') {
            return ['enabled' => false, 'plan' => null, 'limits' => null, 'on_hold' => [], 'totals' => null, 'series' => ['range' => $range],
                'top_documents' => [], 'by_type' => [], 'saved' => null];
        }

        $ent = app(EntitlementService::class);
        $sub = $ent->subscription($workspaceId);
        $paid = $ent->paid($sub);
        $now = now();
        $cycleStart = $paid ? Carbon::instance($sub->current_period_start) : $now->copy()->subDays(self::FREE_CYCLE_DAYS - 1)->startOfDay();
        $balances = $accountant->balances($workspaceId);

        $dailyRows = $this->dailyRows($workspaceId, $cycleStart);
        $charged = (int) $dailyRows->sum('charged');
        $returned = (int) $dailyRows->sum('returned');
        $documents = $this->documentRows($workspaceId, $cycleStart);
        $info = $documents->isEmpty() ? collect() : Document::where('workspace_id', $workspaceId)->whereIn('id', $documents->keys()->all())->get(['id', 'name', 'type'])->keyBy('id');

        [$holds, $holdNames] = $this->openHolds($workspaceId);
        $saved = app(CreditActivity::class)->sources($workspaceId);

        return [
            'enabled' => true,
            'plan' => ['key' => app(PlanLimits::class)->planFor($workspaceId), 'interval' => $paid ? $sub->billing_interval : null,
                'cycle_start' => $cycleStart->toIso8601String(), 'cycle_end' => $paid ? $sub->current_period_end?->toIso8601String() : null,
                'resets_at' => $paid ? $sub->current_period_end?->toIso8601String() : null],
            'limits' => [
                'monthly' => $balances['monthly'] === null ? null
                    : ['allowed' => $balances['monthly']['allowed'], 'used' => $balances['monthly']['used'], 'held' => $balances['monthly']['reserved']],
                'trial' => $this->trial($accountant, $workspaceId),
                'saved' => ['balance' => (int) $balances['saved']],
                'questions' => app(QaGuard::class)->usageToday($workspaceId),
            ],
            'on_hold' => array_values(array_map(fn ($id, $credits) => ['document_id' => $id, 'document_name' => $holdNames->get($id), 'credits' => $credits],
                array_keys($holds), $holds)),
            'totals' => ['charged' => $charged, 'returned' => $returned, 'held' => $charged + $returned, 'documents' => $documents->count(),
                'unused_last_cycle' => $this->unusedLastCycle($sub)],
            'series' => ['range' => $range === '6m' ? '6m' : 'cycle', 'months' => $this->months($workspaceId, $paid ? $sub : null)]
                + ($range === '6m' ? [] : ['days' => $this->days($dailyRows, $cycleStart, $now)]),
            'top_documents' => $documents->sortByDesc('charged')->take(self::TOP_DOCUMENTS)->map(fn ($row, $id) => [
                'document_id' => $id, 'document_name' => $info->get($id)?->name, 'type' => $info->get($id)?->type,
                'charged' => $row['charged'], 'held' => $row['charged'] + $row['returned'], 'returned' => $row['returned']])->values()->all(),
            'by_type' => $this->byType($documents, $info),
            'saved' => ['balance' => (int) $balances['saved'], 'sources' => $saved, 'used' => $this->savedUsed($workspaceId)],
        ];
    }

    /** Ledger charges and returns per calendar day since $since. One grouped query. */
    private function dailyRows(string $workspaceId, Carbon $since): \Illuminate\Support\Collection
    {
        return DB::table('credit_ledger')->where('workspace_id', $workspaceId)->whereIn('unit', self::AI_UNITS)->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, sum('.self::CHARGED.') as charged, sum('.self::RETURNED.') as returned')
            ->groupByRaw('DATE(created_at)')->get()
            ->map(fn ($row) => ['day' => substr((string) $row->day, 0, 10), 'charged' => (int) $row->charged, 'returned' => (int) $row->returned]);
    }

    /** @return array<int,array{date:string,charged:int,returned:int}> every date from the cycle start to today, zero-filled */
    private function days(\Illuminate\Support\Collection $rows, Carbon $start, Carbon $now): array
    {
        $byDay = $rows->keyBy('day');
        $out = [];
        for ($day = $start->copy()->startOfDay(); $day->lte($now); $day = $day->copy()->addDay()) {
            $key = $day->format('Y-m-d');
            $out[] = ['date' => $key, 'charged' => (int) ($byDay[$key]['charged'] ?? 0), 'returned' => (int) ($byDay[$key]['returned'] ?? 0)];
        }

        return $out;
    }

    /**
     * Charged and returned credits per document since $since, grouped by related_id so retries of one document add up. Only document
     * operations with an "ai_" reference and a valid UUID are kept (a uuid column rejects anything else).
     *
     * @return \Illuminate\Support\Collection<string,array{charged:int,returned:int}>
     */
    private function documentRows(string $workspaceId, Carbon $since): \Illuminate\Support\Collection
    {
        return DB::table('credit_ledger')->where('workspace_id', $workspaceId)->whereIn('unit', self::AI_UNITS)->where('created_at', '>=', $since)
            ->whereIn('related_type', self::DOCUMENT_KINDS)->where('reference', 'like', 'ai\\_%')->whereNotNull('related_id')
            ->selectRaw('related_id, sum('.self::CHARGED.') as charged, sum('.self::RETURNED.') as returned')->groupBy('related_id')->get()
            ->filter(fn ($row) => is_string($row->related_id) && Str::isUuid($row->related_id))
            ->mapWithKeys(fn ($row) => [$row->related_id => ['charged' => (int) $row->charged, 'returned' => (int) $row->returned]]);
    }

    /** @return array{0:array<string,int>,1:\Illuminate\Support\Collection} credits held per document, and the document names */
    private function openHolds(string $workspaceId): array
    {
        $holds = DB::table('billing_operations')->where('workspace_id', $workspaceId)->where('status', 'reserved')->whereNotNull('amount_reserved')
            ->whereIn('kind', self::DOCUMENT_KINDS)->get(['resource_id', 'amount_reserved'])
            ->filter(fn ($hold) => Str::isUuid((string) $hold->resource_id));
        $byDocument = [];
        foreach ($holds as $hold) {
            $byDocument[$hold->resource_id] = ($byDocument[$hold->resource_id] ?? 0) + (int) $hold->amount_reserved;
        }
        $ids = array_keys($byDocument);

        return [$byDocument, $ids === [] ? collect() : Document::where('workspace_id', $workspaceId)->whereIn('id', $ids)->pluck('name', 'id')];
    }

    private function trial(CreditAccountant $accountant, string $workspaceId): ?array
    {
        $total = (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('unit', CreditAccountant::TRIAL)->where('direction', 'credit')->sum('amount');
        if ($total < 1) {
            return null;
        }
        $trial = $accountant->trialBalance($workspaceId);

        return ['remaining' => $trial['expired'] ? 0 : $trial['remaining'], 'total' => $total, 'expires_at' => $trial['expires_at']?->toIso8601String()];
    }

    /** The previous closed usage period's allowed minus used (floor 0), or null when there is none or it had no AI allowance. */
    private function unusedLastCycle(?object $sub): ?int
    {
        if (! $sub || ! $sub->exists) {
            return null;
        }
        $previous = $sub->periods()->where('period_end', '<=', now())->orderByDesc('period_end')->first();

        return $previous && $previous->ai_credits_allowed !== null ? max(0, (int) $previous->ai_credits_allowed - (int) $previous->ai_credits_used) : null;
    }

    /** @return array<int,array{start:string,end:string,allowed:?int,used:int,current:bool}> oldest first */
    private function months(string $workspaceId, ?object $sub): array
    {
        $now = now();
        if ($sub) {
            return $sub->periods()->orderByDesc('period_start')->limit(self::MONTHS)->get()->reverse()->values()->map(fn ($period) => [
                'start' => $period->period_start->toIso8601String(), 'end' => $period->period_end->toIso8601String(),
                'allowed' => $period->ai_credits_allowed === null ? null : (int) $period->ai_credits_allowed, 'used' => (int) $period->ai_credits_used,
                'current' => $period->period_start->lte($now) && $period->period_end->gt($now)])->all();
        }

        // No subscription: calendar months, with what the ledger charged and no allowance.
        $first = $now->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);
        $perDay = $this->dailyRows($workspaceId, $first)->groupBy(fn ($row) => substr($row['day'], 0, 7))->map(fn ($rows) => (int) $rows->sum('charged'));
        $out = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $start = $first->copy()->addMonthsNoOverflow($i);
            $out[] = ['start' => $start->toIso8601String(), 'end' => $start->copy()->addMonthNoOverflow()->toIso8601String(), 'allowed' => null,
                'used' => (int) ($perDay[$start->format('Y-m')] ?? 0), 'current' => $i === self::MONTHS - 1];
        }

        return $out;
    }

    /** @return array<int,array{type:string,documents:int,charged:int}> documents with no known type (deleted) are grouped as "Other" */
    private function byType(\Illuminate\Support\Collection $documents, \Illuminate\Support\Collection $info): array
    {
        return $documents->groupBy(fn ($row, $id) => $info->get($id)?->type ?? 'Other')
            ->map(fn ($rows, $type) => ['type' => (string) $type, 'documents' => $rows->count(), 'charged' => (int) $rows->sum('charged')])
            ->sortByDesc('charged')->values()->all();
    }

    /** Lifetime credits spent from saved credits on AI operations. */
    private function savedUsed(string $workspaceId): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('unit', CreditAccountant::SAVED)->where('direction', 'debit')
            ->where('reference', 'like', 'ai\\_debit:%')->sum('amount');
    }
}
