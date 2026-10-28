<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\OperationQuote;
use App\Services\AiCredits\ActualCostSettlement;
use App\Services\AiCredits\QuoteService;
use Illuminate\Console\Command;

/**
 * Read-only: what 'actual' settlement would have charged for recent Ready documents, whatever ai_credits.settlement_mode is
 * now. It only selects; it never writes to the database.
 */
class CreditsSettlementPreview extends Command
{
    protected $signature = 'credits:settlement-preview {--days=7 : Look back this many days}';

    protected $description = 'Read-only: per Ready document, the credits settlement at actual cost would charge and release';

    public function handle(ActualCostSettlement $settlement): int
    {
        $days = max(1, (int) $this->option('days'));
        $rows = [];
        $totals = ['quoted' => 0, 'charge' => 0, 'cost' => 0];

        $documents = Document::where('status', 'Ready')->where('updated_at', '>=', now()->subDays($days))->orderBy('updated_at')->get();
        $quotes = OperationQuote::where('kind', 'document')->whereIn('resource_id', $documents->pluck('id'))->get()
            ->sortByDesc(fn ($q) => (int) substr((string) strrchr($q->operation_key, ':'), 1))->groupBy('resource_id');
        foreach ($documents as $document) {
            $quote = $quotes->get($document->id)?->first();
            if (! $quote) {
                continue;
            }
            $plan = $settlement->plan($quote, null, 'actual');
            $totals['quoted'] += (int) $quote->credits;
            $totals['charge'] += $plan['charged'];
            $totals['cost'] += ActualCostSettlement::toScaled($plan['actual_cost_usd'] ?? '0', 6) ?? 0;
            $rows[] = [$document->id, $document->type, $document->ai_pipeline['route'] ?? '-', $quote->credits,
                $plan['actual_cost_usd'] ?? '-', $plan['charged'], $plan['released'],
                $plan['basis'].($plan['reason'] ? ' ('.$plan['reason'].')' : '')];
        }

        if (! QuoteService::enabled()) {
            $this->warn('AI credits are off here, so there are normally no quotes to preview.');
        }
        $this->table(['document_id', 'file_type', 'route', 'quoted_credits', 'actual_cost_usd', 'would_charge', 'would_release', 'basis'], $rows);
        $this->line(sprintf('Documents: %d. Total quoted: %d. Total would_charge: %d. Total actual cost (USD): %s.',
            count($rows), $totals['quoted'], $totals['charge'], ActualCostSettlement::formatMicros($totals['cost'])));
        $this->line('Nothing was changed.');

        return self::SUCCESS;
    }
}
