<?php

namespace App\Services\AiCredits;

use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use Illuminate\Support\Facades\DB;

/**
 * What a quote should be charged when it settles. Read-only: CreditAccountant::settle applies the result and
 * credits:settlement-preview prints it.
 *
 * 'actual' mode: clamp(ceil(recorded cost x buffer / provider_cost_usd_per_credit), settlement_min, quoted credits).
 * The recorded cost is the sum of estimated_cost_usd over every document_ai_runs row attributed to the quote
 * (retries included). Arithmetic is bcmath on decimal strings, so ceil() cannot jump a credit through float error.
 * A quote whose cost is missing or cannot be trusted as complete is charged as quoted ('quoted_fallback').
 */
class ActualCostSettlement
{
    public const KINDS = ['document', 'ocr', 'reanalysis'];

    public static function mode(): string
    {
        return config('ai_credits.settlement_mode') === 'actual' ? 'actual' : 'quoted';
    }

    /** @return array{charged:int,released:int,actual_cost_usd:?string,basis:string,reason:?string} */
    public function plan(OperationQuote $quote, ?int $amount = null, ?string $mode = null): array
    {
        $quoted = $amount ?? (int) $quote->credits;
        if (($mode ?? self::mode()) !== 'actual' || ! in_array($quote->kind, self::KINDS, true)) {
            return $this->result($quoted, $quoted, null, 'quoted', null);
        }

        $runs = DocumentAiRun::where('operation_quote_id', $quote->id);
        $costs = (clone $runs)->pluck('estimated_cost_usd');
        $actual = '0';
        foreach ($costs as $cost) {
            if ($cost !== null) {
                $actual = bcadd($actual, (string) $cost, 6);
            }
        }
        $reason = match (true) {
            $costs->isEmpty() => 'no_recorded_cost',
            $costs->contains(null) => 'unknown_run_cost',
            DB::table('provider_call_attempts')->where('operation_quote_id', $quote->id)
                ->whereIn('status', ['reserved', 'dispatched', 'uncertain'])->exists() => 'provider_attempt_unresolved',
            bccomp($actual, '0', 6) <= 0 => 'zero_recorded_cost',
            default => null,
        };
        $perCredit = number_format((float) config('ai_credits.provider_cost_usd_per_credit'), 9, '.', '');
        if ($reason === null && bccomp($perCredit, '0', 9) <= 0) {
            $reason = 'invalid_cost_per_credit';
        }
        if ($reason !== null) {
            return $this->result($quoted, $quoted, $costs->isEmpty() ? null : $actual, 'quoted_fallback', $reason);
        }

        $scaled = bcmul($actual, number_format((float) config('ai_credits.settlement_buffer'), 6, '.', ''), 12);
        $credits = bcdiv($scaled, $perCredit, 0);
        if (bccomp(bcmul($credits, $perCredit, 12), $scaled, 12) < 0) {
            $credits = bcadd($credits, '1', 0);
        }
        $charged = (int) min($quoted, max(1, (int) config('ai_credits.settlement_min'), (int) $credits));

        return $this->result($quoted, $charged, $actual, 'actual', null);
    }

    private function result(int $quoted, int $charged, ?string $actual, string $basis, ?string $reason): array
    {
        return ['charged' => $charged, 'released' => $quoted - $charged, 'actual_cost_usd' => $actual, 'basis' => $basis, 'reason' => $reason];
    }
}
