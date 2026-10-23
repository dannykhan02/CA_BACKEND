<?php

namespace App\Services\AiCredits;

use App\Models\Document;

/**
 * How much of the incremental route's synthesis the quote's cap must fund up front (config ai_credits.synthesis_reservation).
 *
 * 'primary_only' reserves the primary synthesis request alone. A fallback or a repair is then paid from whatever cap remains
 * when it is sent, and OperationSpend::reserveAttempt refuses it with `budget_exceeded` if it does not fit: the document ends
 * Needs Review (partial) and its credit hold is released. 'full' keeps the earlier rule: primary + degraded fallback + repair.
 */
class SynthesisReservation
{
    public function primaryOnlyConfigured(): bool
    {
        return config('ai_credits.synthesis_reservation.mode') === 'primary_only';
    }

    /** The multiplier on the primary request's worst case. Never below 1: headroom cannot shrink a worst-case bound. */
    public function headroom(): float
    {
        return max(1.0, (float) config('ai_credits.synthesis_reservation.headroom', 1.0));
    }

    /**
     * What the quote's cap must pay before the pipeline starts, from an AnthropicClient::synthesisReservation() result.
     *
     * @param  array<string,mixed>  $reservation
     */
    public function floor(array $reservation): float
    {
        $primary = (float) ($reservation['synthesis_reserved_usd'] ?? 0);
        if (! $this->primaryOnlyConfigured()) {
            return $primary + (float) ($reservation['synthesis_degraded_reserved_usd'] ?? 0) + (float) ($reservation['repair_reserved_usd'] ?? 0);
        }

        return round($primary * $this->headroom(), 6);
    }

    /**
     * Whether this document's fallback and repair are funded at request time. That needs a quote whose cap
     * OperationSpend enforces: without one (credits off, a legacy workspace) the document budget is the only guard,
     * so the pipeline keeps holding all three requests.
     */
    public function fundsRecoveryAtRequestTime(Document $document): bool
    {
        return $this->primaryOnlyConfigured() && app(OperationSpend::class)->activeQuote($document, null) !== null;
    }
}
