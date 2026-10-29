<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only, customer-safe view of a workspace's AI-credit ledger: what was earned into saved credits, what is held right now and
 * the latest movements. It exposes no reasons, costs, models, quote or operation ids, and reads nothing from other workspaces.
 * Legacy document units (saved_document), unit conversions, hold reservations, absorbed-cost evidence, allowance creation and the
 * trial pocket's carry-over "out" leg are not shown.
 */
class CreditActivity
{
    private const BUCKETS = ['subscription_ai_credit' => 'monthly', CreditAccountant::SAVED => 'saved', CreditAccountant::TRIAL => 'trial'];

    /** Operation kinds that belong to a document. */
    private const DOCUMENT_KINDS = ['document', 'ocr', 'reanalysis'];

    /** reference prefix => [type, sign, direction, allowed units (null = any AI unit)] */
    private const RECENT = [
        'ai_debit:' => ['charged', -1, 'debit', null],
        'ai_release_unused:' => ['returned', 1, 'release', null],
        'ai_release:' => ['refunded', 1, 'release', null],
        'referral:' => ['referral', 1, 'credit', [CreditAccountant::SAVED]],
        'trial:' => ['trial_granted', 1, 'credit', [CreditAccountant::SAVED, CreditAccountant::TRIAL]],
        'trial_carryover:' => ['trial_carryover', 1, 'credit', [CreditAccountant::SAVED]],
        'trial_expire:' => ['trial_expired', -1, 'debit', [CreditAccountant::TRIAL]],
        'admin_grant:' => ['bonus', 1, 'credit', [CreditAccountant::SAVED]],
        'saved_adjust:' => [null, null, null, [CreditAccountant::SAVED]], // credit => bonus, debit => adjusted
    ];

    public function forWorkspace(string $workspaceId, int $limit): array
    {
        $accountant = app(CreditAccountant::class);
        if (! QuoteService::enabled() || $accountant->mode($workspaceId) !== 'credits') {
            return ['enabled' => false, 'saved_sources' => [], 'holds' => [], 'recent' => []];
        }

        $sources = $this->sources($workspaceId);
        $recent = $this->recentRows($workspaceId, $limit);
        $holds = DB::table('billing_operations')->where('workspace_id', $workspaceId)->where('status', 'reserved')->whereNotNull('amount_reserved')
            ->whereIn('kind', self::DOCUMENT_KINDS)->get(['resource_id', 'amount_reserved'])
            ->filter(fn ($hold) => Str::isUuid((string) $hold->resource_id));

        // Only valid UUIDs reach the documents table (a uuid column rejects anything else); with none, no query runs.
        $ids = $holds->pluck('resource_id')->merge(collect($recent)->pluck('document_id'))->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values()->all();
        $names = $ids === [] ? collect() : Document::where('workspace_id', $workspaceId)->whereIn('id', $ids)->pluck('name', 'id');

        $heldByDocument = [];
        foreach ($holds as $hold) {
            $heldByDocument[$hold->resource_id] = ($heldByDocument[$hold->resource_id] ?? 0) + (int) $hold->amount_reserved;
        }

        return [
            'enabled' => true,
            'saved_sources' => $sources,
            'holds' => array_values(array_map(fn ($id, $credits) => ['document_id' => $id, 'document_name' => $names->get($id), 'credits' => $credits],
                array_keys($heldByDocument), $heldByDocument)),
            'recent' => array_map(fn ($row) => $row + ['document_name' => $row['document_id'] !== null ? $names->get($row['document_id']) : null], $recent),
        ];
    }

    /** Lifetime credits added to saved, by source. One grouped query; spending is never subtracted. */
    public function sources(string $workspaceId): array
    {
        $rows = DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('unit', CreditAccountant::SAVED)->where('direction', 'credit')
            ->where(fn ($q) => $q->where('reference', 'like', 'referral:%')->orWhere('reference', 'like', 'trial\_carryover:%')
                ->orWhere('reference', 'like', 'admin\_grant:%')->orWhere('reference', 'like', 'saved\_adjust:%'))
            ->selectRaw("case when reference like 'referral:%' then 'referral' when reference like 'trial\\_carryover:%' then 'trial_carryover' else 'bonus' end as source, sum(amount) as credits")
            ->groupByRaw(1)->get()->pluck('credits', 'source');

        $out = [];
        foreach (['referral', 'trial_carryover', 'bonus'] as $source) {
            if ((int) ($rows[$source] ?? 0) > 0) {
                $out[] = ['source' => $source, 'credits' => (int) $rows[$source]];
            }
        }

        return $out;
    }

    /** Newest first; one query. Rows whose reference is not in RECENT are never selected. */
    private function recentRows(string $workspaceId, int $limit): array
    {
        $units = array_keys(self::BUCKETS);
        $rows = DB::table('credit_ledger')->where('workspace_id', $workspaceId)->whereIn('unit', $units)->whereIn('direction', ['credit', 'debit', 'release'])
            ->where(function ($q) {
                foreach (array_keys(self::RECENT) as $prefix) {
                    $q->orWhere('reference', 'like', str_replace('_', '\_', $prefix).'%');
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit * 2 + 5)
            ->get(['id', 'unit', 'direction', 'amount', 'reference', 'related_type', 'related_id', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $mapped = $this->classify($row);
            if ($mapped === null) {
                continue;
            }
            [$type, $sign] = $mapped;
            $isDocument = in_array($row->related_type, self::DOCUMENT_KINDS, true) && str_starts_with($row->reference, 'ai_')
                && is_string($row->related_id) && Str::isUuid($row->related_id);
            $out[] = ['id' => (string) $row->id, 'at' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(), 'type' => $type,
                'credits' => $sign * (int) $row->amount, 'bucket' => self::BUCKETS[$row->unit],
                'document_id' => $isDocument ? $row->related_id : null];
            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return array{0:string,1:int}|null type and sign, or null for a row that is not shown */
    private function classify(object $row): ?array
    {
        // Longest prefix first so "ai_release_unused:" is not read as "ai_release:", nor "trial_carryover:" as "trial:".
        $prefixes = array_keys(self::RECENT);
        usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            if (! str_starts_with($row->reference, $prefix)) {
                continue;
            }
            [$type, $sign, $direction, $units] = self::RECENT[$prefix];
            if ($units !== null && ! in_array($row->unit, $units, true)) {
                return null;
            }
            if ($prefix === 'saved_adjust:') {
                return $row->direction === 'credit' ? ['bonus', 1] : ($row->direction === 'debit' ? ['adjusted', -1] : null);
            }

            return $row->direction === $direction ? [$type, $sign] : null;
        }

        return null;
    }
}
