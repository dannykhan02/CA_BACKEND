<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only billing reconciliation for the charge-on-publish policy (docs/billing-credits.md).
 * Changes nothing and refunds nothing: documents settled under the earlier policy are history,
 * and the one condition that means a real accounting fault — a document charged twice — is the
 * only one that fails the command.
 */
class AuditCreditSettlements extends Command
{
    protected $signature = 'credits:audit-settlements {--limit=20 : Ids to list per section}';

    protected $description = 'Report settled documents with no published revision, double charges, and long-held reservations';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $orphaned = Document::withTrashed()->whereNotNull('credit_accounted_at')
            ->whereNull('published_intelligence_revision_id')->orderBy('credit_accounted_at');
        $this->report('Settled without a published revision', $orphaned->count(),
            $orphaned->limit($limit)->pluck('id')->all(), $limit,
            'Expected for documents settled before the charge-on-publish policy; no refund is issued.');

        // One charge per document is the invariant the policy rests on; the ledger is append-only,
        // so two debit rows for one document mean it really was charged twice.
        $doubled = DB::table('credit_ledger')->where('direction', 'debit')->where('related_type', 'document')
            ->whereIn('unit', ['saved_document', 'subscription_document', 'saved_ai_credit', 'subscription_ai_credit'])
            ->whereNotNull('related_id')->groupBy('related_id')
            ->havingRaw('count(*) > 1')->orderBy('related_id')->pluck('related_id');
        $this->report('Charged more than once', $doubled->count(), $doubled->take($limit)->all(), $limit,
            'Each of these needs manual correction: the policy charges exactly once per document.');

        $held = DB::table('billing_operations')->where('status', 'reserved')
            ->where(function ($q) {
                // Legacy comparison counters are not swept, so only variable-price comparison holds belong here.
                $q->whereIn('kind', ['document', 'ocr', 'reanalysis'])
                    ->orWhere(fn ($c) => $c->where('kind', 'comparison')->whereNotNull('amount_reserved'));
            })
            ->where('updated_at', '<=', now()->subDay())->orderBy('updated_at');
        $this->report('Reservations held longer than 24h', $held->count(),
            $held->limit($limit)->pluck('resource_id')->all(), $limit,
            'billing:release-stale-reservations releases these on its hourly run.');

        return $doubled->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<int, string>  $ids */
    private function report(string $label, int $count, array $ids, int $limit, string $note): void
    {
        $this->line(sprintf('%s: %d', $label, $count));
        foreach ($ids as $id) {
            $this->line('  - '.$id);
        }
        if ($count > $limit) {
            $this->line(sprintf('  ... %d more (raise --limit to list them)', $count - $limit));
        }
        if ($count > 0) {
            $this->line('  '.$note);
        }
    }
}
