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
 * (retries included). Arithmetic is integer micro-dollars (no floats, no bcmath: production PHP does not ship the extension), so ceil() cannot jump a credit.
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
        $micros = 0;
        $overflow = false;
        $unparseable = false;
        foreach ($costs as $cost) {
            if ($cost === null || trim((string) $cost) === '') {
                continue;
            }
            $one = self::toScaled((string) $cost, 6);
            if ($one === null) {
                $unparseable = true;
            } elseif ($one > PHP_INT_MAX - $micros) {
                $overflow = true;
            } else {
                $micros += $one;
            }
        }
        $missing = $costs->contains(fn ($c) => $c === null || trim((string) $c) === '');
        $perCredit = self::toScaled(number_format((float) config('ai_credits.provider_cost_usd_per_credit'), 9, '.', ''), 6) ?? 0;
        $buffer = self::toScaled(number_format((float) config('ai_credits.settlement_buffer'), 6, '.', ''), 4) ?? 0;
        $reason = match (true) {
            $costs->isEmpty() => 'no_recorded_cost',
            $missing => 'unknown_run_cost',
            $unparseable => 'unparseable_run_cost',
            DB::table('provider_call_attempts')->where('operation_quote_id', $quote->id)
                ->whereIn('status', ['reserved', 'dispatched', 'uncertain'])->exists() => 'provider_attempt_unresolved',
            $overflow => 'cost_overflow',
            $micros <= 0 => 'zero_recorded_cost',
            $perCredit <= 0 || $perCredit > intdiv(PHP_INT_MAX, 10000) => 'invalid_cost_per_credit',
            $buffer <= 0 => 'invalid_settlement_buffer',
            $micros > intdiv(PHP_INT_MAX, $buffer) => 'cost_overflow',
            default => null,
        };
        $actual = $costs->isEmpty() || $unparseable || $overflow ? null : self::formatMicros($micros);
        if ($reason !== null) {
            return $this->result($quoted, $quoted, $actual, 'quoted_fallback', $reason);
        }

        // ceil(cost x buffer / cost per credit) on integers: micro-dollars x (buffer x 10^4) / (per-credit micro-dollars x 10^4).
        $numerator = $micros * $buffer;
        $denominator = $perCredit * 10000;
        $credits = intdiv($numerator, $denominator) + ($numerator % $denominator > 0 ? 1 : 0);
        $charged = min($quoted, max(1, (int) config('ai_credits.settlement_min'), $credits));

        return $this->result($quoted, $charged, $actual, 'actual', null);
    }

    /**
     * A non-negative decimal string as an integer scaled by 10^$scale, without floats. Digits beyond the scale round UP
     * (a cost is never rounded down). Null when the string is not a plain decimal or does not fit an int.
     */
    public static function toScaled(string $value, int $scale): ?int
    {
        if (! preg_match('/^(\d+)(?:\.(\d*))?$/', trim($value), $m)) {
            return null;
        }
        $whole = ltrim($m[1], '0');
        $fraction = $m[2] ?? '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundUp = trim(substr($fraction, $scale), '0') !== '';
        if (strlen($whole) > 15 - $scale) {
            return null;
        }

        return (int) ($whole.$kept) + ($roundUp ? 1 : 0);
    }

    public static function formatMicros(int $micros): string
    {
        return intdiv($micros, 1000000).'.'.str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function result(int $quoted, int $charged, ?string $actual, string $basis, ?string $reason): array
    {
        return ['charged' => $charged, 'released' => $quoted - $charged, 'actual_cost_usd' => $actual, 'basis' => $basis, 'reason' => $reason];
    }
}
